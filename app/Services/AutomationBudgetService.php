<?php

namespace App\Services;

use App\Exceptions\MetaAdsException;
use App\Models\AdAccount;
use App\Models\AdSet;
use App\Models\AutomationLog;
use App\Models\AutomationTask;
use App\Models\Campaign;
use App\Models\T4JamProfile;
use App\Support\MetaFlowLog;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class AutomationBudgetService
{
    private const BUDGET_INCREASE_RATIO = 0.15;

    private const BUDGET_INCREASE_COOLDOWN_HOURS = 72;

    private const MIN_RESULTS_FOR_BUDGET_INCREASE = 3;

    private const BUDGET_SCALE_CPR_RATIO = 0.80;

    private const BUDGET_INCREASE_LOCK_SECONDS = 120;

    private array $statusCache = [];

    private bool $stopBatch = false;

    public function __construct(private readonly AutomationTaskMutationService $taskMutations) {}

    private const CONVERSION_ACTION_TYPES = [
        'purchase' => ['purchase', 'omni_purchase', 'offsite_conversion.fb_pixel_purchase', 'onsite_conversion.purchase'],
        'add_to_cart' => ['add_to_cart', 'omni_add_to_cart', 'offsite_conversion.fb_pixel_add_to_cart', 'onsite_conversion.add_to_cart'],
        'lead' => ['lead', 'omni_lead', 'offsite_conversion.fb_pixel_lead', 'onsite_conversion.lead_grouped'],
        'add_payment_info' => ['add_payment_info', 'omni_add_payment_info', 'offsite_conversion.fb_pixel_add_payment_info'],
        'initiate_checkout' => ['initiate_checkout', 'omni_initiated_checkout', 'offsite_conversion.fb_pixel_initiate_checkout'],
        'contact_website' => ['contact_website', 'omni_contact_website', 'offsite_conversion.contact_website'],
        'onsite_conversion.messaging_conversation_started_7d' => ['onsite_conversion.messaging_conversation_started_7d'],
    ];

    public static function conversions(): array
    {
        return array_keys(self::CONVERSION_ACTION_TYPES);
    }

    public static function conversionLabel(string $conversion): string
    {
        return match ($conversion) {
            'purchase' => 'Purchase', 'lead' => 'Lead', 'add_to_cart' => 'ATC',
            'initiate_checkout' => 'Checkout', 'contact_website' => 'Website Contact',
            'onsite_conversion.messaging_conversation_started_7d' => 'WhatsApp',
            default => 'Add Payment Info',
        };
    }

    public function automationInsightsDatePreset(): string
    {
        $datePreset = trim((string) config('services.meta.automation_insights_date_preset', 'today'));

        return $datePreset !== '' ? $datePreset : 'today';
    }

    public function pauseTasksOverCprCap(T4JamProfile $profile, MetaAdsClient $client, bool $refreshMetrics = false, ?array $syncedTargets = null, string $source = 'scheduler'): int
    {
        return Cache::lock('automation-profile:'.$profile->id, 900)->get(
            fn () => $this->evaluateTasks($profile, $client, $refreshMetrics, $syncedTargets, $source)
        ) ?: 0;
    }

    public function enforceTaskImmediately(T4JamProfile $profile, MetaAdsClient $client, AutomationTask $task): bool
    {
        $target = $this->target($task);
        $campaignIds = $task->level === 'campaign' ? $this->normalizeIds([$task->campaign_external_id]) : [];
        $adSetIds = $task->level === 'adset' ? $this->normalizeIds([$task->ad_set_external_id]) : [];

        try {
            return Cache::lock('automation-profile:'.$profile->id, 900)->block(10, function () use ($profile, $client, $target, $campaignIds, $adSetIds): bool {
                $this->evaluateTasks(
                    $profile,
                    $client,
                    true,
                    null,
                    'manual_activation',
                    true,
                    $target?->adAccount?->external_id,
                    $campaignIds,
                    $adSetIds,
                );

                return true;
            });
        } catch (LockTimeoutException) {
            MetaFlowLog::warning('immediate automation enforcement lock unavailable', [
                'profile_id' => $profile->id,
                'automation_task_id' => $task->id,
            ]);

            return false;
        }
    }

    public function pauseWebhookTasksOverCprCap(
        T4JamProfile $profile,
        MetaAdsClient $client,
        string $adAccountExternalId,
        array $campaignIds = [],
        array $adSetIds = [],
        ?array $syncedTargets = null,
    ): int {
        $campaignIds = $this->normalizeIds($campaignIds);
        $adSetIds = $this->normalizeIds($adSetIds);
        $lockKey = 'automation-webhook:'.$profile->id.':'.md5($adAccountExternalId.'|'.implode(',', $campaignIds).'|'.implode(',', $adSetIds));

        return Cache::lock($lockKey, 60)->get(
            fn () => $this->evaluateTasks(
                $profile,
                $client,
                $syncedTargets === null,
                $syncedTargets,
                'webhook',
                true,
                $adAccountExternalId,
                $campaignIds,
                $adSetIds,
            )
        ) ?: 0;
    }

    public function reconcileTasks(
        T4JamProfile $profile,
        MetaAdsClient $client,
        string $adAccountExternalId,
        array $campaignIds,
        array $adSetIds,
        array $freshTargets,
    ): int {
        return Cache::lock('automation-profile:'.$profile->id, 55)->get(
            fn () => $this->evaluateTasks(
                $profile,
                $client,
                false,
                $freshTargets,
                'reconciliation',
                true,
                $adAccountExternalId,
                $this->normalizeIds($campaignIds),
                $this->normalizeIds($adSetIds),
                false,
            )
        ) ?: 0;
    }

    public function refreshTaskMetricsForDisplay(T4JamProfile $profile, MetaAdsClient $client, Collection $tasks): int
    {
        if ($tasks->isEmpty()) {
            return 0;
        }

        return Cache::lock('automation-display-sync:'.$profile->id, 30)->get(function () use ($profile, $client, $tasks): int {
            $datePreset = $this->automationInsightsDatePreset();
            $freshTargets = $this->refreshMetrics($tasks, $client, $profile, $datePreset);
            $updated = 0;

            $tasks->each(function (AutomationTask $task) use ($freshTargets, &$updated): void {
                $target = $this->target($task);

                if (! $target) {
                    return;
                }

                $metrics = $freshTargets[$this->targetKey($task, $target)] ?? null;

                if ($metrics === null) {
                    $task->update(['metrics_unavailable_at' => now()]);

                    return;
                }

                $task->update([
                    'current_spend' => (int) $metrics['spend'],
                    'current_result' => max(0, (int) ($metrics['results'][$task->conversion] ?? 0)),
                    'current_budget' => (int) $target->daily_budget,
                    'last_metrics_synced_at' => $metrics['insights_synced_at'] ?? now(),
                    'metrics_unavailable_at' => null,
                ]);
                $updated++;
            });

            MetaFlowLog::info('automation display metrics refreshed', [
                'profile_id' => $profile->id,
                'date_preset' => $datePreset,
                'tasks' => $tasks->count(),
                'updated' => $updated,
            ]);

            return $updated;
        }) ?: 0;
    }

    private function evaluateTasks(
        T4JamProfile $profile,
        MetaAdsClient $client,
        bool $refreshMetrics = false,
        ?array $syncedTargets = null,
        string $source = 'scheduler',
        bool $forceDue = false,
        ?string $adAccountExternalId = null,
        array $campaignIds = [],
        array $adSetIds = [],
        bool $allowMissingTargetRefresh = true,
    ): int {
        $this->statusCache = [];
        $this->stopBatch = false;
        $paused = 0;
        $account = $adAccountExternalId
            ? AdAccount::query()->where('external_id', $adAccountExternalId)->first()
            : null;
        $tasks = AutomationTask::query()
            ->with(['campaign', 'adSet', 'adAccount'])
            ->where('user_id', $profile->user_id)
            ->when($adAccountExternalId, function ($query) use ($account): void {
                if ($account) {
                    $query->where('ad_account_id', $account->id);

                    return;
                }

                $query->whereRaw('1 = 0');
            })
            ->when($campaignIds !== [] || $adSetIds !== [], function ($query) use ($campaignIds, $adSetIds): void {
                $query->where(function ($targetQuery) use ($campaignIds, $adSetIds): void {
                    if ($campaignIds !== []) {
                        $targetQuery->orWhere(function ($campaignQuery) use ($campaignIds): void {
                            $campaignQuery->where('level', 'campaign')
                                ->whereIn('campaign_external_id', $campaignIds);
                        });
                    }

                    if ($adSetIds !== []) {
                        $targetQuery->orWhere(function ($adSetQuery) use ($adSetIds): void {
                            $adSetQuery->where('level', 'adset')
                                ->whereIn('ad_set_external_id', $adSetIds);
                        });
                    }
                });
            })
            ->where(function ($query): void {
                $query->where('is_active', true)
                    ->orWhere(function ($pausedQuery): void {
                        $pausedQuery
                            ->where('is_active', false)
                            ->where(function ($pauseStateQuery): void {
                                $pauseStateQuery
                                    ->where('last_budget_action', 'pause');
                            });
                    })
                    ->orWhere(function ($scheduleQuery): void {
                        $scheduleQuery
                            ->where('is_active', false)
                            ->where('use_on_off', true)
                            ->where(function ($pauseStateQuery): void {
                                $pauseStateQuery
                                    ->where('last_budget_action', 'schedule_pause')
                                    ->orWhere('last_budget_action', 'manual_pause')
                                    ->orWhere('last_budget_action', 'manual');
                            });
                    });
            })
            ->get();

        if ($refreshMetrics) {
            $tasks = $tasks
                ->filter(function (AutomationTask $task) use ($profile, $client, $forceDue, $source): bool {
                    $scheduleContinued = $this->applyScheduledStatusIfNeeded($task, $client, $profile, $source);

                    if (! $forceDue && ! $this->isDue($task) && ! $this->isDailyResumeDue($task)) {
                        return false;
                    }

                    return $scheduleContinued;
                })
                ->values();
        }

        $datePreset = $this->automationInsightsDatePreset();
        $freshTargets = $refreshMetrics ? $this->refreshMetrics($tasks, $client, $profile, $datePreset) : $syncedTargets;

        if (! $refreshMetrics && $allowMissingTargetRefresh && $freshTargets !== null) {
            $missingTasks = $tasks
                ->filter(function (AutomationTask $task) use ($freshTargets): bool {
                    $target = $this->target($task);

                    return $target !== null && ! array_key_exists($this->targetKey($task, $target), $freshTargets);
                })
                ->values();

            if ($missingTasks->isNotEmpty()) {
                $freshTargets += $this->refreshMetrics($missingTasks, $client, $profile, $datePreset);
            }
        }

        $tasks
            ->each(function (AutomationTask $task) use ($profile, $client, $freshTargets, &$paused, $source): void {
                if ($this->stopBatch) {
                    return;
                }

                $beforeAction = $task->last_budget_action;
                $beforeLog = $task->last_log;
                $reason = 'rules_not_met';
                $cpr = null;
                $targetId = null;
                $message = null;
                try {
                    $target = $this->target($task);

                    if (! $target) {
                        $reason = 'target_missing';

                        return;
                    }

                    if ($task->is_active && $this->targetPausedByMeta($target)) {
                        $this->pauseTaskFromMetaStatus($task);
                        $reason = 'target_not_active';

                        return;
                    }

                    if (! $this->isWithinAutomationWindow($task)) {
                        $reason = 'outside_automation_window';

                        return;
                    }

                    $targetKey = $this->targetKey($task, $target);

                    if ($freshTargets !== null && ! isset($freshTargets[$targetKey])) {
                        $reason = 'insight_unavailable';
                        $task->update(['metrics_unavailable_at' => now()]);

                        return;
                    }

                    $metrics = $freshTargets[$targetKey] ?? null;
                    if ($metrics !== null && $this->automationInsightsDatePreset() === 'today'
                        && isset($metrics['date_stop'])
                        && $metrics['date_stop'] !== $this->automationDayStart($task)->toDateString()) {
                        $reason = 'insight_unavailable';
                        $task->update(['metrics_unavailable_at' => now()]);

                        return;
                    }
                    $spend = (int) ($metrics['spend'] ?? $task->current_spend);
                    $result = $metrics !== null
                        ? max(0, (int) ($metrics['results'][$task->conversion] ?? 0))
                        : max(0, (int) $task->current_result);
                    $cpr = $result > 0 ? (int) round($spend / $result) : $spend;

                    $task->update([
                        'current_spend' => $spend,
                        'current_result' => $result,
                        'current_budget' => $target->daily_budget,
                        'last_metrics_synced_at' => $metrics['insights_synced_at'] ?? now(),
                        'metrics_unavailable_at' => null,
                        'last_checked_at' => now(),
                    ]);

                    if (! $task->is_active) {
                        if ((int) $task->pause_cpr_cap >= (int) $task->cpr_cap) {
                            $reason = 'invalid_recovery_threshold';
                        }
                        $this->resumeTaskIfEligible($task, $target, $client, $profile, $result, $cpr, $metrics !== null);

                        return;
                    }

                    if ((int) $task->cpr_cap <= 0) {
                        $reason = 'missing_cpr_cap';

                        return;
                    }

                    if ($cpr < (int) $task->cpr_cap) {
                        $reason = $this->increaseBudgetIfEligible(
                            $task,
                            $target,
                            $client,
                            $profile,
                            $result,
                            $cpr,
                            $metrics !== null,
                        );
                        $this->logPendingPauseCancellation($task);
                        $this->clearPendingPause($task);

                        return;
                    }

                    if (! $task->pause_when_cpr_loss) {
                        $reason = 'pause_disabled';

                        return;
                    }

                    if ($target->status !== 'ACTIVE') {
                        $target = $this->refreshInactiveTargetStatus($task, $target, $client, $profile);
                    }

                    if ($target->status !== 'ACTIVE') {
                        $reason = 'target_not_active';

                        return;
                    }

                    if (! config('services.meta.enable_writes')) {
                        $reason = 'writes_disabled';
                        $this->recordSkippedPause($task, 'Automation melewati pause karena META_ADS_ENABLE_WRITES=false.');

                        return;
                    }

                    $targetId = $target->external_id;

                    $this->taskMutationLock($task)->get(function () use ($task, $target, $client, $profile, $cpr, $targetId, &$reason, &$message, &$paused): void {
                        $task->refresh();
                        $target->refresh();

                        if (! $task->is_active || ! $this->writableMetaTarget($target)) {
                            return;
                        }

                        if ($target->status !== 'ACTIVE') {
                            return;
                        }

                        if ((int) $task->cpr_cap <= 0 || ! $task->pause_when_cpr_loss || $cpr < (int) $task->cpr_cap) {
                            return;
                        }

                        if ($this->hasPendingPause($task)) {
                            $this->retryPendingPauseUnderLock($task, $target, $client, $profile, $cpr, $paused);

                            return;
                        }

                        if (! $this->assertNoPendingMetaAction($task)) {
                            return;
                        }

                        if (! config('services.meta.enable_writes')) {
                            $this->recordSkippedPause($task, 'Automation melewati pause karena META_ADS_ENABLE_WRITES=false.');

                            return;
                        }

                        try {
                            if ($task->level === 'adset') {
                                $client->updateAdSetStatus($targetId, false);
                            } else {
                                $client->updateCampaignStatus($targetId, false);
                            }
                        } catch (MetaAdsException $exception) {
                            $this->handlePauseFailure($task, $profile, $exception, $cpr, $task->cpr_cap, $targetId, $reason, $message);

                            return;
                        }

                        $message = sprintf(
                            'Campaign otomatis dipause karena CPR Rp. %s mencapai batas Rp. %s.',
                            number_format($cpr, 0, ',', '.'),
                            number_format((int) $task->cpr_cap, 0, ',', '.'),
                        );

                        DB::transaction(function () use ($task, $target, $message): void {
                            $target->update([
                                'status' => 'PAUSED',
                                'effective_status' => 'PAUSED',
                            ]);
                            $task->update([
                                'is_active' => false,
                                'last_log' => $message,
                                'last_checked_at' => now(),
                                'last_budget_action' => 'pause',
                                'cpr_paused_at' => now(),
                            ]);
                            AutomationLog::create([
                                'automation_task_id' => $task->id,
                                'messages' => [$message],
                            ]);
                        });

                        $this->clearPendingPause($task);

                        MetaFlowLog::info('automation cpr cap status update finished', [
                            'profile_id' => $profile->id,
                            'automation_task_id' => $task->id,
                            'target_id' => $targetId,
                            'cpr' => $cpr,
                            'cpr_cap' => $task->cpr_cap,
                        ]);

                        $paused++;
                    });

                } catch (MetaAdsException $exception) {
                    if ($this->isRateLimitException($exception)) {
                        $this->stopBatch = true;
                    }

                    $reason = 'cpr_pause_status_update_failed';
                    $message = 'CPR cap terlewati, tetapi campaign gagal dipause di Meta.';

                    MetaFlowLog::warning('automation cpr cap status update failed', [
                        'profile_id' => $profile->id,
                        'automation_task_id' => $task->id,
                        'target_id' => $targetId ?? null,
                        'cpr' => $cpr ?? null,
                        'cpr_cap' => $task->cpr_cap,
                        'http_status' => $exception->httpStatus,
                        'meta_code' => $exception->metaCode,
                    ]);

                    $task->update([
                        'last_log' => $message,
                        'last_checked_at' => now(),
                    ]);
                    AutomationLog::create([
                        'automation_task_id' => $task->id,
                        'messages' => [$message],
                    ]);

                    return;
                } finally {
                    $action = ($task->last_budget_action !== $beforeAction || ($task->last_budget_action === 'increase' && $beforeLog !== $task->last_log)) && in_array($task->last_budget_action, ['pause', 'resume', 'daily_resume', 'increase', 'schedule_pause', 'schedule_resume', 'manual_pause', 'manual_resume', 'manual_budget_decrease'], true) ? $task->last_budget_action : 'none';
                    $this->logEvaluation($profile, $task, $action, $action === 'none' ? $reason : null, $source);
                }
            });

        return $paused;
    }

    private function applyScheduledStatusIfNeeded(
        AutomationTask $task,
        MetaAdsClient $client,
        T4JamProfile $profile,
        string $source,
    ): bool {
        if (! $task->use_on_off) {
            return true;
        }

        $target = $this->target($task);

        if (! $target) {
            $this->logEvaluation($profile, $task, 'none', 'target_missing', $source);

            return false;
        }

        if (! $this->isWithinAutomationWindow($task)) {
            if ($task->is_active) {
                $this->setScheduledStatus($task, $target, $client, $profile, false, $source);
            } else {
                $this->logEvaluation($profile, $task, 'none', 'outside_automation_window', $source);
            }

            return false;
        }

        if (! $task->is_active && in_array($task->last_budget_action, ['schedule_pause', 'manual_pause'], true)) {
            return $this->setScheduledStatus($task, $target, $client, $profile, true, $source);
        }

        if (! $task->is_active && $task->last_budget_action === 'manual' && $target->status === 'PAUSED') {
            $task->update(['last_budget_action' => 'manual_pause']);

            return $this->setScheduledStatus($task, $target, $client, $profile, true, $source);
        }

        return true;
    }

    private function setScheduledStatus(
        AutomationTask $task,
        Campaign|AdSet $target,
        MetaAdsClient $client,
        T4JamProfile $profile,
        bool $active,
        string $source = 'scheduler',
    ): bool {
        if (! config('services.meta.enable_writes')) {
            $this->logEvaluation($profile, $task, 'none', 'writes_disabled', $source);

            return false;
        }

        $lockAcquired = $this->taskMutationLock($task)->get(function () use ($task, $target, $client, $profile, $active, $source): bool {
            $task->refresh();
            $target->refresh();

            if (! $this->writableMetaTarget($target)) {
                $this->logEvaluation($profile, $task, 'none', 'target_not_editable', $source);

                return false;
            }

            if ($active) {
                if ($task->is_active && $target->status === 'ACTIVE') {
                    return false;
                }
            } else {
                if (! $task->is_active && $target->status === 'PAUSED') {
                    return false;
                }
            }

            if (! $this->assertNoPendingMetaAction($task)) {
                return false;
            }

            try {
                if ($task->level === 'adset') {
                    $client->updateAdSetStatus($target->external_id, $active);
                } else {
                    $client->updateCampaignStatus($target->external_id, $active);
                }
            } catch (MetaAdsException $exception) {
                MetaFlowLog::warning('automation schedule status update failed', [
                    'profile_id' => $profile->id,
                    'automation_task_id' => $task->id,
                    'target_id' => $target->external_id,
                    'active' => $active,
                    'http_status' => $exception->httpStatus,
                    'meta_code' => $exception->metaCode,
                ]);

                $this->logEvaluation($profile, $task, 'none', 'schedule_status_update_failed', $source);

                return false;
            }

            $action = $active ? 'schedule_resume' : 'schedule_pause';
            $message = $active
                ? sprintf(
                    'Campaign otomatis diaktifkan kembali karena sudah masuk jam aktif %s-%s.',
                    substr((string) $task->on_time, 0, 5),
                    substr((string) $task->off_time, 0, 5),
                )
                : sprintf(
                    'Campaign otomatis dipause karena sudah di luar jam aktif %s-%s.',
                    substr((string) $task->on_time, 0, 5),
                    substr((string) $task->off_time, 0, 5),
                );

            DB::transaction(function () use ($task, $target, $active, $action, $message): void {
                $target->update([
                    'status' => $active ? 'ACTIVE' : 'PAUSED',
                    'effective_status' => $active ? 'ACTIVE' : 'PAUSED',
                ]);
                $task->update([
                    'is_active' => $active,
                    'last_log' => $message,
                    'last_checked_at' => now(),
                    'last_budget_action' => $action,
                ]);
                AutomationLog::create([
                    'automation_task_id' => $task->id,
                    'messages' => [$message],
                ]);
            });

            $this->logEvaluation($profile, $task->fresh(), $action, null, $source);

            return $active;
        });

        return $lockAcquired === false ? false : $lockAcquired;
    }

    private function resumeTaskIfEligible(
        AutomationTask $task,
        Campaign|AdSet $target,
        MetaAdsClient $client,
        T4JamProfile $profile,
        int $result,
        int $cpr,
        bool $metricsFresh,
    ): void {
        $recoveryCap = (int) $task->pause_cpr_cap;
        $dailyResume = $metricsFresh && $this->isDailyResumeDue($task) && $cpr < (int) $task->cpr_cap;
        $counterResume = $task->counter_cpr && $recoveryCap > 0 && $recoveryCap < (int) $task->cpr_cap
            && $result > 0 && $cpr <= $recoveryCap;

        if ((! $dailyResume && ! $counterResume) || $task->last_budget_action !== 'pause' || $target->status !== 'PAUSED'
            || ! config('services.meta.enable_writes')) {
            return;
        }

        $this->taskMutationLock($task)->get(function () use ($task, $target, $client, $profile, $metricsFresh): void {
            $task->refresh();
            $target->refresh();
            $result = (int) $task->current_result;
            $cpr = $result > 0 ? (int) round($task->current_spend / $result) : (int) $task->current_spend;
            $recoveryCap = (int) $task->pause_cpr_cap;
            $dailyResume = $metricsFresh && $this->isDailyResumeDue($task)
                && $task->last_metrics_synced_at?->gte($this->automationDayStart($task))
                && $task->metrics_unavailable_at === null && $cpr < (int) $task->cpr_cap;
            $counterResume = $task->counter_cpr && $recoveryCap > 0 && $recoveryCap < (int) $task->cpr_cap
                && $result > 0 && $cpr <= $recoveryCap;

            // A CPR recovery candidate is intentionally inactive until Meta accepts
            // the ACTIVE mutation. Do not reject that expected state.
            if ($task->is_active || (! $dailyResume && ! $counterResume) || $task->last_budget_action !== 'pause' || $target->status !== 'PAUSED'
                || ! config('services.meta.enable_writes') || ! $this->isWithinAutomationWindow($task)
                || ! $this->writableMetaTarget($target)) {
                return;
            }

            if ($task->pending_meta_action !== null || ! $this->assertNoPendingMetaAction($task)) {
                return;
            }

            try {
                if ($dailyResume) {
                    $remote = $task->level === 'adset' ? $client->adSet($target->external_id) : $client->campaign($target->external_id);
                    if (($remote['id'] ?? null) !== $target->external_id || ($remote['status'] ?? null) !== 'PAUSED'
                        || in_array($remote['effective_status'] ?? null, ['DELETED', 'ARCHIVED'], true)) {
                        return;
                    }
                }
                if ($task->level === 'adset') {
                    $response = $client->updateAdSetStatus($target->external_id, true);
                } else {
                    $response = $client->updateCampaignStatus($target->external_id, true);
                }
                if (($response['success'] ?? false) !== true) {
                    throw new MetaAdsException('Meta belum mengonfirmasi aktivasi automation.');
                }
            } catch (MetaAdsException $exception) {
                MetaFlowLog::warning('automation cpr recovery failed', [
                    'profile_id' => $profile->id,
                    'automation_task_id' => $task->id,
                    'target_id' => $target->external_id,
                    'cpr' => $cpr,
                    'recovery_cap' => $recoveryCap,
                    'http_status' => $exception->httpStatus,
                    'meta_code' => $exception->metaCode,
                ]);

                return;
            }

            $message = $dailyResume ? 'Campaign otomatis diaktifkan kembali pada hari baru setelah pause CPR.' : sprintf(
                'Campaign otomatis diaktifkan kembali karena CPR Rp. %s sudah di bawah batas recovery Rp. %s.',
                number_format($cpr, 0, ',', '.'),
                number_format($recoveryCap, 0, ',', '.'),
            );

            DB::transaction(function () use ($task, $target, $message, $dailyResume): void {
                $target->update([
                    'status' => 'ACTIVE',
                    'effective_status' => 'ACTIVE',
                ]);
                $task->update([
                    'is_active' => true,
                    'last_log' => $message,
                    'last_checked_at' => now(),
                    'last_budget_action' => $dailyResume ? 'daily_resume' : 'resume',
                    'cpr_paused_at' => null,
                ]);
                AutomationLog::create([
                    'automation_task_id' => $task->id,
                    'messages' => [$message],
                ]);
            });

            $this->clearPendingPause($task);
        });
    }

    public function automationDayStart(AutomationTask $task): Carbon
    {
        $timezone = $task->adAccount?->timezone_name ?: 'Asia/Jakarta';

        return now($timezone)->startOfDay();
    }

    private function isDailyResumeDue(AutomationTask $task): bool
    {
        return $this->automationInsightsDatePreset() === 'today'
            && ! $task->is_active && $task->last_budget_action === 'pause'
            && (int) $task->cpr_cap > 0
            && $task->cpr_paused_at !== null
            && $task->cpr_paused_at->lt($this->automationDayStart($task));
    }

    public function emptyDailyInsights(Collection $tasks): array
    {
        if ($this->automationInsightsDatePreset() !== 'today' || $tasks->isEmpty()) {
            return [];
        }

        // Only a successful, empty target response may start a new day's totals.
        // Preserve known positive totals from today if Meta temporarily omits them.
        foreach ($tasks as $task) {
            if ($task->last_metrics_synced_at === null && ! $this->isDailyResumeDue($task)) {
                return [];
            }
            if ($task->last_metrics_synced_at?->gte($this->automationDayStart($task))
                && ((int) $task->current_spend > 0 || (int) $task->current_result > 0)) {
                return [];
            }
        }

        return ['spend' => '0', 'actions' => []];
    }

    private function refreshMetrics(Collection $tasks, MetaAdsClient $client, T4JamProfile $profile, ?string $datePreset = null): array
    {
        $freshTargets = [];
        $targets = [];

        $tasks->each(function (AutomationTask $task) use (&$targets): void {
            $target = $this->target($task);

            if (! $target) {
                return;
            }

            $targetKey = $this->targetKey($task, $target);
            $targets[$targetKey] ??= ['target' => $target, 'tasks' => []];
            $targets[$targetKey]['tasks'][] = $task;
        });

        $freshInsights = $this->bulkRefreshTargetInsights($targets, $client, $profile, $datePreset);

        foreach ($targets as $targetKey => $targetData) {
            /** @var Campaign|AdSet $target */
            $target = $targetData['target'];
            $insights = $freshInsights[$targetKey] ?? null;

            if ($insights === null) {
                continue;
            }

            $metrics = $this->metricSnapshot($insights);

            if ($metrics === null) {
                continue;
            }

            $target->update($this->insightPayload($metrics));
            $freshTargets[$targetKey] = $metrics;
        }

        return $freshTargets;
    }

    private function bulkRefreshTargetInsights(array $targets, MetaAdsClient $client, T4JamProfile $profile, ?string $datePreset = null): array
    {
        $groups = [];
        $freshInsights = [];

        foreach ($targets as $targetKey => $targetData) {
            /** @var Campaign|AdSet $target */
            $target = $targetData['target'];
            $task = $targetData['tasks'][0];
            $adAccountId = $target->adAccount?->external_id;

            if (! $adAccountId) {
                continue;
            }

            $groups[$task->level][$adAccountId][$targetKey] = $targetData;
        }

        foreach ($groups as $level => $accountGroups) {
            foreach ($accountGroups as $adAccountId => $targetGroup) {
                $firstTargetData = reset($targetGroup);
                $firstTask = $firstTargetData['tasks'][0];

                try {
                    $rows = $level === 'adset'
                        ? $client->accountAdSetInsights($adAccountId, $datePreset)
                        : $client->accountCampaignInsights($adAccountId, $datePreset);
                } catch (MetaAdsException $exception) {
                    MetaFlowLog::warning('automation account insights sync failed', [
                        'profile_id' => $profile->id,
                        'automation_task_id' => $firstTask->id,
                        'ad_account_id' => $adAccountId,
                        'level' => $level,
                        'http_status' => $exception->httpStatus,
                        'meta_code' => $exception->metaCode,
                    ]);

                    continue;
                }

                $idField = $level === 'adset' ? 'adset_id' : 'campaign_id';
                $rowsByTarget = collect($rows)->keyBy($idField);

                foreach ($targetGroup as $targetKey => $targetData) {
                    /** @var Campaign|AdSet $target */
                    $target = $targetData['target'];
                    $insights = $rowsByTarget->get($target->external_id, []);

                    if ($insights === []) {
                        try {
                            $insights = $level === 'adset'
                                ? $client->adSetInsights($target->external_id, $datePreset)
                                : $client->campaignInsights($target->external_id, $datePreset);
                        } catch (MetaAdsException $exception) {
                            MetaFlowLog::warning('automation target insights fallback failed', [
                                'profile_id' => $profile->id,
                                'automation_task_id' => $targetData['tasks'][0]->id,
                                'ad_account_id' => $adAccountId,
                                'level' => $level,
                                'target_id' => $target->external_id,
                                'date_preset' => $datePreset,
                                'http_status' => $exception->httpStatus,
                                'meta_code' => $exception->metaCode,
                            ]);

                            continue;
                        }
                    }

                    if ($insights === []) {
                        $insights = $this->emptyDailyInsights(collect($targetData['tasks']));
                    }

                    if ($insights === []) {
                        MetaFlowLog::info('automation target insight unavailable', [
                            'profile_id' => $profile->id,
                            'automation_task_id' => $targetData['tasks'][0]->id,
                            'ad_account_id' => $adAccountId,
                            'level' => $level,
                            'target_id' => $target->external_id,
                            'date_preset' => $datePreset,
                            'has_insight_row' => false,
                        ]);

                        continue;
                    }

                    $freshInsights[$targetKey] = $insights;
                }
            }
        }

        return $freshInsights;
    }

    private function logEvaluation(T4JamProfile $profile, AutomationTask $task, string $action, ?string $reason, string $source): void
    {
        $result = (int) $task->current_result;
        $target = $this->target($task);
        MetaFlowLog::info('automation evaluation', [
            'profile_id' => $profile->id,
            'automation_task_id' => $task->id,
            'level' => $task->level,
            'target_id' => $task->level === 'adset' ? $task->ad_set_external_id : $task->campaign_external_id,
            'conversion' => $task->conversion,
            'spend' => $task->current_spend,
            'result' => $result,
            'cpr' => $result > 0 ? (int) round($task->current_spend / $result) : $task->current_spend,
            'cpr_cap' => $task->cpr_cap,
            'target_status' => $target?->status,
            'action' => $action,
            'reason' => $reason,
            'source' => $source,
        ]);
    }

    private function recordSkippedPause(AutomationTask $task, string $message): void
    {
        $task->update(['last_log' => $message]);
        AutomationLog::create([
            'automation_task_id' => $task->id,
            'messages' => [$message],
        ]);
    }

    private function pauseTaskFromMetaStatus(AutomationTask $task): void
    {
        $message = 'Status dipause dari Meta Ads Manager.';
        $task->update([
            'is_active' => false,
            'last_budget_action' => 'meta_sync',
            'last_log' => $message,
        ]);
        AutomationLog::create([
            'automation_task_id' => $task->id,
            'messages' => [$message],
        ]);
    }

    private function targetPausedByMeta(Campaign|AdSet $target): bool
    {
        return strtoupper((string) ($target->effective_status ?: $target->status)) === 'PAUSED';
    }

    private function writableMetaTarget(Campaign|AdSet $target): bool
    {
        return ! in_array(strtoupper((string) $target->status), ['ARCHIVED', 'DELETED'], true)
            && ! in_array(strtoupper((string) $target->effective_status), ['ARCHIVED', 'DELETED'], true);
    }

    private function refreshInactiveTargetStatus(
        AutomationTask $task,
        Campaign|AdSet $target,
        MetaAdsClient $client,
        T4JamProfile $profile,
    ): Campaign|AdSet {
        $adAccountId = $target->adAccount?->external_id;

        if (! $adAccountId) {
            return $target;
        }

        $cacheKey = $task->level.':'.$adAccountId;
        if (! array_key_exists($cacheKey, $this->statusCache)) {
            try {
                $rows = $task->level === 'adset'
                    ? $client->accountAdSets($adAccountId)
                    : $client->campaigns($adAccountId);
            } catch (MetaAdsException $exception) {
                MetaFlowLog::warning('automation target status refresh failed', [
                    'profile_id' => $profile->id,
                    'automation_task_id' => $task->id,
                    'ad_account_id' => $adAccountId,
                    'level' => $task->level,
                    'http_status' => $exception->httpStatus,
                    'meta_code' => $exception->metaCode,
                ]);

                $this->statusCache[$cacheKey] = collect();

                return $target;
            }

            $this->statusCache[$cacheKey] = collect($rows)->keyBy('id');
        }

        $fresh = $this->statusCache[$cacheKey]->get($target->external_id);
        if (! is_array($fresh)) {
            return $target;
        }

        $target->update([
            'status' => $fresh['status'] ?? $fresh['effective_status'] ?? $target->status,
            'effective_status' => $fresh['effective_status'] ?? $target->effective_status,
            'daily_budget' => (int) ($fresh['daily_budget'] ?? $target->daily_budget),
        ]);

        return $target->fresh();
    }

    private function isDue(AutomationTask $task): bool
    {
        if (! $task->last_checked_at) {
            return true;
        }

        $period = max(5, (int) $task->period);

        return $task->last_checked_at->lte(now()->subMinutes($period));
    }

    private function isWithinAutomationWindow(AutomationTask $task): bool
    {
        if (! $task->use_on_off) {
            return true;
        }

        $current = now('Asia/Jakarta')->format('H:i');
        $on = substr((string) $task->on_time, 0, 5);
        $off = substr((string) $task->off_time, 0, 5);

        if ($on === $off) {
            return true;
        }

        return $on < $off
            ? $current >= $on && $current < $off
            : $current >= $on || $current < $off;
    }

    private function increaseBudgetIfEligible(
        AutomationTask $task,
        Campaign|AdSet $target,
        MetaAdsClient $client,
        T4JamProfile $profile,
        int $result,
        int $cpr,
        bool $hasFreshMetrics,
    ): string {
        if (! $hasFreshMetrics) {
            return 'metrics_not_fresh';
        }

        $lock = $this->taskMutationLock($task);
        $reason = $lock->get(function () use ($task, $target, $client, $profile, $result, $cpr): string {
            // Re-read after acquiring the task-level lock so a concurrent trigger cannot
            // calculate its next increase from a stale local budget or cooldown value.
            $task->refresh();
            $target->refresh();

            if ($task->is_active !== true) {
                return 'task_not_active';
            }

            if (! $this->writableMetaTarget($target)) {
                return 'target_not_eligible';
            }

            if (! $this->assertNoPendingMetaAction($task)) {
                return 'pending_meta_action';
            }

            if ($target->status !== 'ACTIVE' || ! config('services.meta.enable_writes')) {
                return 'target_not_eligible';
            }

            if ($result < self::MIN_RESULTS_FOR_BUDGET_INCREASE) {
                return 'minimum_results_not_met';
            }

            $cprTarget = (int) $task->cpr_cap;
            if ($cprTarget <= 0 || $cpr >= $cprTarget) {
                return 'cpr_not_below_cap';
            }

            $scaleCprLimit = (int) floor($cprTarget * self::BUDGET_SCALE_CPR_RATIO);
            if ($cpr > $scaleCprLimit) {
                return 'cpr_not_healthy_enough_for_scale';
            }

            if ($task->last_budget_changed_at?->gt(now()->subHours(self::BUDGET_INCREASE_COOLDOWN_HOURS))) {
                return 'budget_increase_cooldown';
            }

            $currentBudget = (int) $target->daily_budget > 0
                ? (int) $target->daily_budget
                : ((int) $task->current_budget > 0 ? (int) $task->current_budget : (int) $task->starting_budget);
            $maximumBudget = (int) $task->maximum_budget;

            if ($currentBudget <= 0) {
                return 'missing_current_budget';
            }

            if ($maximumBudget > 0 && $currentBudget >= $maximumBudget) {
                return 'maximum_budget_reached';
            }

            $nextBudget = (int) (round(($currentBudget * (1 + self::BUDGET_INCREASE_RATIO)) / 1000) * 1000);
            $nextBudget = max($currentBudget + 1000, $nextBudget);

            if ($maximumBudget > 0) {
                $nextBudget = min($nextBudget, $maximumBudget);
            }

            if ($nextBudget <= $currentBudget) {
                return 'maximum_budget_reached';
            }

            try {
                if ($task->level === 'adset') {
                    $client->updateAdSetBudget($target->external_id, $nextBudget);
                } else {
                    $client->updateCampaignBudget($target->external_id, $nextBudget);
                }
            } catch (MetaAdsException $exception) {
                MetaFlowLog::warning('automation budget increase failed', [
                    'profile_id' => $profile->id,
                    'automation_task_id' => $task->id,
                    'target_id' => $target->external_id,
                    'current_budget' => $currentBudget,
                    'next_budget' => $nextBudget,
                    'http_status' => $exception->httpStatus,
                    'meta_code' => $exception->metaCode,
                ]);

                return 'budget_increase_failed';
            }

            $message = sprintf(
                'Budget otomatis dinaikkan dari Rp. %s menjadi Rp. %s karena CPR Rp. %s masih di bawah atau sama dengan 80%% batas CPR Rp. %s.',
                number_format($currentBudget, 0, ',', '.'),
                number_format($nextBudget, 0, ',', '.'),
                number_format($cpr, 0, ',', '.'),
                number_format($cprTarget, 0, ',', '.'),
            );

            DB::transaction(function () use ($task, $target, $currentBudget, $nextBudget, $message): void {
                $target->update(['daily_budget' => $nextBudget]);
                $task->update([
                    'current_budget' => $nextBudget,
                    'last_budget_changed_at' => now(),
                    'last_budget_before' => $currentBudget,
                    'last_budget_action' => 'increase',
                    'last_log' => $message,
                ]);
                AutomationLog::create([
                    'automation_task_id' => $task->id,
                    'messages' => [$message],
                ]);
            });

            return 'budget_increased';
        });

        return $reason === false ? 'budget_increase_lock_unavailable' : $reason;
    }

    private function taskMutationLock(AutomationTask $task)
    {
        return $this->taskMutations->lock($task);
    }

    private function assertNoPendingMetaAction(AutomationTask $task): bool
    {
        return ! $this->hasPendingPause($task);
    }

    private function hasPendingPause(AutomationTask $task): bool
    {
        return $this->taskMutations->hasPendingPause($task);
    }

    private function retryPendingPauseUnderLock(
        AutomationTask $task,
        Campaign|AdSet $target,
        MetaAdsClient $client,
        T4JamProfile $profile,
        int $cpr,
        int &$paused,
    ): void {
        $task->refresh();
        $target->refresh();

        if (! $task->is_active || ! $this->hasPendingPause($task)
            || (int) $task->cpr_cap <= 0 || ! $task->pause_when_cpr_loss
            || $cpr < (int) $task->cpr_cap || $target->status !== 'ACTIVE'
            || ! config('services.meta.enable_writes') || ! $this->writableMetaTarget($target)) {
            return;
        }

        try {
            if ($task->level === 'adset') {
                $client->updateAdSetStatus($target->external_id, false);
            } else {
                $client->updateCampaignStatus($target->external_id, false);
            }
        } catch (MetaAdsException $exception) {
            $rateLimited = $this->isRateLimitException($exception);
            $this->recordPendingPause($task, $profile, $cpr, $task->cpr_cap, $rateLimited);

            if ($rateLimited) {
                $this->stopBatch = true;
            }

            return;
        }

        $message = sprintf(
            'Campaign otomatis dipause karena CPR Rp. %s mencapai batas Rp. %s setelah cooldown rate limit.',
            number_format($cpr, 0, ',', '.'),
            number_format((int) $task->cpr_cap, 0, ',', '.'),
        );

        DB::transaction(function () use ($task, $target, $message): void {
            $target->update([
                'status' => 'PAUSED',
                'effective_status' => 'PAUSED',
            ]);
            $task->update([
                'is_active' => false,
                'last_log' => $message,
                'last_checked_at' => now(),
                'last_budget_action' => 'pause',
                'cpr_paused_at' => now(),
            ]);
            AutomationLog::create([
                'automation_task_id' => $task->id,
                'messages' => [$message],
            ]);
        });

        $this->clearPendingPause($task);
        $paused++;
    }

    private function targetKey(AutomationTask $task, Campaign|AdSet $target): string
    {
        return $task->level.':'.$target->external_id;
    }

    private function normalizeIds(array $ids): array
    {
        return collect($ids)
            ->filter(fn ($id) => is_scalar($id) && trim((string) $id) !== '')
            ->map(fn ($id) => (string) $id)
            ->unique()
            ->values()
            ->all();
    }

    public function metricSnapshot(array $insights): ?array
    {
        if ($insights === []) {
            return null;
        }

        $actions = collect($insights['actions'] ?? []);
        $costs = collect($insights['cost_per_action_type'] ?? []);

        $results = [];
        foreach (self::CONVERSION_ACTION_TYPES as $conversion => $types) {
            $results[$conversion] = $this->actionValue($actions, $types);
        }

        $conversionCosts = [];
        foreach (self::CONVERSION_ACTION_TYPES as $conversion => $types) {
            $conversionCosts[$conversion] = $this->actionCost($costs, $types);
        }

        return [
            'spend' => (int) round((float) ($insights['spend'] ?? 0)),
            'reach' => (int) ($insights['reach'] ?? 0),
            'link_click' => (int) ($insights['inline_link_clicks'] ?? $this->actionValue($actions, ['link_click'])),
            'landing_page_view' => $this->actionValue($actions, ['landing_page_view']),
            'results' => $results,
            'costs' => $conversionCosts,
            'insights_synced_at' => now(),
            'date_stop' => $insights['date_stop'] ?? null,
        ];
    }

    public function insightPayload(array $metrics): array
    {
        return [
            'spend' => $metrics['spend'],
            'reach' => $metrics['reach'],
            'result' => $metrics['results']['purchase'] ?? 0,
            'conversion_results' => $metrics['results'],
            'link_click' => $metrics['link_click'],
            'landing_page_view' => $metrics['landing_page_view'],
            'insights_synced_at' => $metrics['insights_synced_at'],
        ];
    }

    private function actionValue(Collection $actions, array $types): int
    {
        foreach ($types as $type) {
            $value = $actions
                ->where('action_type', $type)
                ->sum(fn (array $action) => (int) ($action['value'] ?? 0));

            if ($value > 0) {
                return (int) $value;
            }
        }

        return 0;
    }

    private function actionCost(Collection $costs, array $types): ?float
    {
        foreach ($types as $type) {
            $value = $costs
                ->where('action_type', $type)
                ->first()['value'] ?? null;

            if ($value !== null) {
                return (float) $value;
            }
        }

        return null;
    }

    private function target(AutomationTask $task): Campaign|AdSet|null
    {
        if ($task->level === 'adset') {
            return $task->adSet
                ?? ($task->ad_set_external_id
                    ? AdSet::query()->where('external_id', $task->ad_set_external_id)->first()
                    : null);
        }

        return $task->campaign
            ?? ($task->campaign_external_id
                ? Campaign::query()->where('external_id', $task->campaign_external_id)->first()
                : null);
    }

    private function isRateLimited(T4JamProfile $profile): bool
    {
        return app(MetaRateLimitService::class)->isRateLimited($profile);
    }

    private function isRateLimitException(MetaAdsException $exception): bool
    {
        return $exception->metaCode === 613 || $exception->httpStatus === 429
            || in_array($exception->metaCode, [4, 17, 80000, 80001, 80002, 80003, 80004], true);
    }

    private function handlePauseFailure(
        AutomationTask $task,
        T4JamProfile $profile,
        MetaAdsException $exception,
        ?int $cpr,
        ?int $cprCap,
        ?string $targetId,
        string &$reason,
        ?string $message,
    ): void {
        $rateLimited = $this->isRateLimitException($exception);
        $this->recordPendingPause($task, $profile, $cpr, $cprCap, $rateLimited);
        $reason = $rateLimited ? 'pause_failed_rate_limited' : 'cpr_pause_status_update_pending';
        $message = $rateLimited
            ? AutomationTaskMutationService::PENDING_PAUSE_MARKER.' CPR cap terlewati, tetapi pause ke Meta ditunda karena rate limit.'
            : 'CPR cap terlewati, tetapi pause ke Meta belum berhasil dan menunggu verifikasi ulang.';

        MetaFlowLog::warning('automation cpr cap status update pending', [
            'profile_id' => $profile->id,
            'automation_task_id' => $task->id,
            'target_id' => $targetId,
            'cpr' => $cpr,
            'cpr_cap' => $cprCap,
            'http_status' => $exception->httpStatus,
            'meta_code' => $exception->metaCode,
            'retry_after_seconds' => $exception->retryAfter,
        ]);

        $task->update([
            'last_log' => $message,
            'last_checked_at' => now(),
        ]);
        AutomationLog::create([
            'automation_task_id' => $task->id,
            'messages' => [$message],
        ]);

        if ($rateLimited) {
            $this->stopBatch = true;
        }
    }

    private function recordPendingPause(AutomationTask $task, T4JamProfile $profile, ?int $cpr, ?int $cprCap, bool $rateLimited = false): void
    {
        $dueAt = $rateLimited
            ? app(MetaRateLimitService::class)->cooldownUntil($profile) ?? now()->addSeconds(60)
            : now();

        $task->update([
            'pending_meta_action' => 'pause',
            'meta_verification_due_at' => $dueAt,
            'last_log' => AutomationTaskMutationService::PENDING_PAUSE_MARKER.' CPR Rp. '.number_format($cpr ?? 0, 0, ',', '.')
                .' vs cap Rp. '.number_format($cprCap ?? 0, 0, ',', '.').'.',
            'last_checked_at' => now(),
        ]);

        AutomationLog::create([
            'automation_task_id' => $task->id,
            'messages' => [AutomationTaskMutationService::PENDING_PAUSE_MARKER],
        ]);
    }

    private function clearPendingPause(AutomationTask $task): void
    {
        $this->taskMutations->clearPendingPause($task);
    }

    private function logPendingPauseCancellation(AutomationTask $task): void
    {
        if (! $this->hasPendingPause($task)) {
            return;
        }

        $message = 'Pending pause dibatalkan karena CPR sudah kembali di bawah batas.';
        $task->update(['last_log' => $message]);
        AutomationLog::create([
            'automation_task_id' => $task->id,
            'messages' => [$message],
        ]);
    }

    public function resumePendingPauses(T4JamProfile $profile, MetaAdsClient $client, ?array $syncedTargets = null): int
    {
        if ($this->isRateLimited($profile)) {
            return 0;
        }

        // A retry runs in a later cooldown window and must not inherit a prior
        // batch's rate-limit stop signal from a shared service instance.
        $this->stopBatch = false;

        $tasks = AutomationTask::query()
            ->where('user_id', $profile->user_id)
            ->where('is_active', true)
            ->where(function ($query): void {
                $query->where('pending_meta_action', 'pause')
                    ->orWhere(function ($legacy): void {
                        $legacy->whereNull('pending_meta_action')
                            ->whereNotNull('meta_verification_due_at')
                            ->where('last_log', 'like', '%'.AutomationTaskMutationService::PENDING_PAUSE_MARKER.'%');
                    });
            })
            ->with(['campaign', 'adSet'])
            ->get();

        if ($tasks->isEmpty()) {
            return 0;
        }

        $datePreset = $this->automationInsightsDatePreset();
        $freshTargets = $this->refreshMetrics($tasks, $client, $profile, $datePreset);
        $paused = 0;

        $tasks->each(function (AutomationTask $task) use ($profile, $client, $freshTargets, &$paused): void {
            $target = $this->target($task);

            if (! $target) {
                return;
            }

            $targetKey = $this->targetKey($task, $target);
            $metrics = $freshTargets[$targetKey] ?? null;

            if ($metrics === null) {
                $this->recordPendingPause($task, $profile, null, $task->cpr_cap);

                return;
            }

            $spend = (int) ($metrics['spend'] ?? $task->current_spend);
            $result = max(0, (int) ($metrics['results'][$task->conversion] ?? $task->current_result));
            $cpr = $result > 0 ? (int) round($spend / $result) : $spend;

            $task->update([
                'current_spend' => $spend,
                'current_result' => $result,
                'current_budget' => $target->daily_budget,
                'last_metrics_synced_at' => $metrics['insights_synced_at'] ?? now(),
                'metrics_unavailable_at' => null,
                'last_checked_at' => now(),
            ]);

            if ($cpr < (int) $task->cpr_cap) {
                $this->logPendingPauseCancellation($task);
                $this->clearPendingPause($task);

                return;
            }

            if ((int) $task->cpr_cap <= 0 || ! $task->pause_when_cpr_loss) {
                $this->clearPendingPause($task);

                return;
            }

            if ($target->status !== 'ACTIVE') {
                $target = $this->refreshInactiveTargetStatus($task, $target, $client, $profile);
            }

            if ($target->status !== 'ACTIVE' || ! config('services.meta.enable_writes')) {
                $this->recordPendingPause($task, $profile, $cpr, $task->cpr_cap);

                return;
            }

            try {
                $this->taskMutationLock($task)->get(function () use ($task, $target, $client, $profile, $cpr, &$paused): void {
                    $this->retryPendingPauseUnderLock($task, $target, $client, $profile, $cpr, $paused);
                });
            } catch (MetaAdsException $exception) {
                $rateLimited = $this->isRateLimitException($exception);
                $this->recordPendingPause($task, $profile, $cpr, $task->cpr_cap, $rateLimited);

                if ($rateLimited) {
                    $this->stopBatch = true;
                }

                return;
            }
        });

        return $paused;
    }
}
