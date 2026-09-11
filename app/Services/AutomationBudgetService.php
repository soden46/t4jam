<?php

namespace App\Services;

use App\Exceptions\MetaAdsException;
use App\Models\AdSet;
use App\Models\AutomationLog;
use App\Models\AutomationTask;
use App\Models\Campaign;
use App\Models\T4JamProfile;
use App\Support\MetaFlowLog;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class AutomationBudgetService
{
    private const BUDGET_INCREASE_RATIO = 0.15;

    private const BUDGET_INCREASE_COOLDOWN_HOURS = 72;

    private const MINIMUM_CONVERSIONS_TO_SCALE = 3;

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

    public function pauseTasksOverCprCap(T4JamProfile $profile, MetaAdsClient $client, bool $refreshMetrics = false, ?array $syncedTargets = null): int
    {
        return Cache::lock('automation-profile:'.$profile->id, 900)->get(
            fn () => $this->evaluateTasks($profile, $client, $refreshMetrics, $syncedTargets)
        ) ?: 0;
    }

    private function evaluateTasks(
        T4JamProfile $profile,
        MetaAdsClient $client,
        bool $refreshMetrics = false,
        ?array $syncedTargets = null,
    ): int {
        $paused = 0;
        $tasks = AutomationTask::query()
            ->with(['campaign', 'adSet'])
            ->where('user_id', $profile->user_id)
            ->where(function ($query): void {
                $query->where('is_active', true)
                    ->orWhere(function ($pausedQuery): void {
                        $pausedQuery
                            ->where('is_active', false)
                            ->where('counter_cpr', true)
                            ->where(function ($pauseStateQuery): void {
                                $pauseStateQuery
                                    ->where('last_budget_action', 'pause');
                            });
                    });
            })
            ->get();

        if ($refreshMetrics) {
            $tasks = $tasks
                ->filter(function (AutomationTask $task) use ($profile): bool {
                    if (! $this->isDue($task) || ! $this->isWithinAutomationWindow($task)) {
                        $this->logEvaluation($profile, $task, 'none', 'not_due_or_outside_window');

                        return false;
                    }

                    return true;
                })
                ->values();
        }

        $freshTargets = $refreshMetrics ? $this->refreshMetrics($tasks, $client, $profile) : $syncedTargets;

        $tasks
            ->each(function (AutomationTask $task) use ($profile, $client, $freshTargets, &$paused): void {
                $beforeAction = $task->last_budget_action;
                $beforeLog = $task->last_log;
                $reason = 'rules_not_met';
                try {
                    $target = $this->target($task);

                    if (! $target) {
                        $reason = 'target_missing';

                        return;
                    }

                    if (! $this->isWithinAutomationWindow($task)) {
                        return;
                    }

                    $targetKey = $this->targetKey($task, $target);

                    if ($freshTargets !== null && ! isset($freshTargets[$targetKey])) {
                        $reason = 'insight_unavailable';

                        return;
                    }

                    $metrics = $freshTargets[$targetKey] ?? null;
                    $spend = (int) ($metrics['spend'] ?? $task->current_spend);
                    $result = $metrics !== null
                        ? max(0, (int) ($metrics['results'][$task->conversion] ?? 0))
                        : max(0, (int) $task->current_result);
                    $cpr = $result > 0 ? (int) round($spend / $result) : $spend;

                    $task->update([
                        'current_spend' => $spend,
                        'current_result' => $result,
                        'current_budget' => $target->daily_budget,
                        'last_checked_at' => now(),
                    ]);

                    if (! $task->is_active) {
                        if ((int) $task->pause_cpr_cap >= (int) $task->cpr_cap) {
                            $reason = 'invalid_recovery_threshold';
                        }
                        $this->resumeTaskIfEligible($task, $target, $client, $profile, $result, $cpr);

                        return;
                    }

                    if ((int) $task->cpr_cap <= 0) {
                        return;
                    }

                    if (! config('services.meta.enable_writes')) {
                        $reason = 'writes_disabled';

                        return;
                    }

                    if ($cpr < (int) $task->cpr_cap) {
                        $this->increaseBudgetIfEligible($task, $target, $client, $profile, $result, $cpr);

                        return;
                    }

                    if (! $task->pause_when_cpr_loss) {
                        $reason = 'pause_disabled';

                        return;
                    }
                    if ($target->status !== 'ACTIVE') {
                        $reason = 'target_not_active';

                        return;
                    }
                    $targetId = $target->external_id;

                    try {
                        if ($task->level === 'adset') {
                            $client->updateAdSetStatus($targetId, false);
                        } else {
                            $client->updateCampaignStatus($targetId, false);
                        }
                    } catch (MetaAdsException $exception) {
                        $message = 'CPR cap terlewati, tetapi campaign gagal dipause di Meta.';

                        MetaFlowLog::warning('automation cpr cap status update failed', [
                            'profile_id' => $profile->id,
                            'automation_task_id' => $task->id,
                            'target_id' => $targetId,
                            'cpr' => $cpr,
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
                        ]);
                        AutomationLog::create([
                            'automation_task_id' => $task->id,
                            'messages' => [$message],
                        ]);
                    });

                    MetaFlowLog::info('automation cpr cap status update finished', [
                        'profile_id' => $profile->id,
                        'automation_task_id' => $task->id,
                        'target_id' => $targetId,
                        'cpr' => $cpr,
                        'cpr_cap' => $task->cpr_cap,
                    ]);

                    $paused++;
                } finally {
                    $action = ($task->last_budget_action !== $beforeAction || ($task->last_budget_action === 'increase' && $beforeLog !== $task->last_log)) && in_array($task->last_budget_action, ['pause', 'resume', 'increase'], true) ? $task->last_budget_action : 'none';
                    $this->logEvaluation($profile, $task, $action, $action === 'none' ? $reason : null);
                }
            });

        return $paused;
    }

    private function resumeTaskIfEligible(
        AutomationTask $task,
        Campaign|AdSet $target,
        MetaAdsClient $client,
        T4JamProfile $profile,
        int $result,
        int $cpr,
    ): void {
        $recoveryCap = (int) $task->pause_cpr_cap;

        if (! $task->counter_cpr || $task->last_budget_action !== 'pause' || $target->status !== 'PAUSED'
            || $recoveryCap >= (int) $task->cpr_cap
            || ! config('services.meta.enable_writes') || $result <= 0 || $recoveryCap <= 0 || $cpr > $recoveryCap) {
            return;
        }

        try {
            if ($task->level === 'adset') {
                $client->updateAdSetStatus($target->external_id, true);
            } else {
                $client->updateCampaignStatus($target->external_id, true);
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

        $message = sprintf(
            'Campaign otomatis diaktifkan kembali karena CPR Rp. %s sudah di bawah batas recovery Rp. %s.',
            number_format($cpr, 0, ',', '.'),
            number_format($recoveryCap, 0, ',', '.'),
        );

        DB::transaction(function () use ($task, $target, $message): void {
            $target->update([
                'status' => 'ACTIVE',
                'effective_status' => 'ACTIVE',
            ]);
            $task->update([
                'is_active' => true,
                'last_log' => $message,
                'last_checked_at' => now(),
                'last_budget_action' => 'resume',
            ]);
            AutomationLog::create([
                'automation_task_id' => $task->id,
                'messages' => [$message],
            ]);
        });
    }

    private function refreshMetrics(Collection $tasks, MetaAdsClient $client, T4JamProfile $profile): array
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

        foreach ($targets as $targetKey => $targetData) {
            /** @var Campaign|AdSet $target */
            $target = $targetData['target'];
            $task = $targetData['tasks'][0];

            try {
                $insights = $task->level === 'adset'
                    ? $client->adSetInsights($target->external_id)
                    : $client->campaignInsights($target->external_id);
            } catch (MetaAdsException $exception) {
                MetaFlowLog::warning('automation target insight sync failed', [
                    'profile_id' => $profile->id,
                    'automation_task_id' => $task->id,
                    'target_id' => $target->external_id,
                    'level' => $task->level,
                    'http_status' => $exception->httpStatus,
                    'meta_code' => $exception->metaCode,
                ]);

                continue;
            }

            $metrics = $this->metricSnapshot($insights);
            $target->update($this->insightPayload($metrics));
            $freshTargets[$targetKey] = $metrics;
        }

        return $freshTargets;
    }

    private function logEvaluation(T4JamProfile $profile, AutomationTask $task, string $action, ?string $reason): void
    {
        $result = (int) $task->current_result;
        MetaFlowLog::info('automation evaluation', [
            'profile_id' => $profile->id,
            'automation_task_id' => $task->id,
            'target_id' => $task->level === 'adset' ? $task->ad_set_external_id : $task->campaign_external_id,
            'conversion' => $task->conversion,
            'spend' => $task->current_spend,
            'result' => $result,
            'cpr' => $result > 0 ? (int) round($task->current_spend / $result) : $task->current_spend,
            'cpr_cap' => $task->cpr_cap,
            'action' => $action,
            'reason' => $reason,
        ]);
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
    ): void {
        if ($target->status !== 'ACTIVE' || ! config('services.meta.enable_writes') || $result < self::MINIMUM_CONVERSIONS_TO_SCALE) {
            return;
        }

        $cprTarget = (int) $task->cpr_cap;
        if ($cprTarget <= 0 || $cpr > (int) floor($cprTarget * 0.8)) {
            return;
        }

        if (! $task->last_budget_changed_at) {
            $task->update([
                'last_budget_changed_at' => now(),
                'last_budget_action' => 'baseline',
            ]);

            return;
        }

        if ($task->last_budget_changed_at->gt(now()->subHours(self::BUDGET_INCREASE_COOLDOWN_HOURS))) {
            return;
        }

        $currentBudget = (int) $target->daily_budget > 0
            ? (int) $target->daily_budget
            : ((int) $task->current_budget > 0 ? (int) $task->current_budget : (int) $task->starting_budget);
        $maximumBudget = (int) $task->maximum_budget;

        if ($currentBudget <= 0 || ($maximumBudget > 0 && $currentBudget >= $maximumBudget)) {
            return;
        }

        $nextBudget = (int) (round(($currentBudget * (1 + self::BUDGET_INCREASE_RATIO)) / 1000) * 1000);
        $nextBudget = max($currentBudget + 1000, $nextBudget);

        if ($maximumBudget > 0) {
            $nextBudget = min($nextBudget, $maximumBudget);
        }

        if ($nextBudget <= $currentBudget) {
            return;
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

            return;
        }

        $message = sprintf(
            'Budget otomatis dinaikkan dari Rp. %s menjadi Rp. %s karena CPR Rp. %s berada di bawah 80%% target.',
            number_format($currentBudget, 0, ',', '.'),
            number_format($nextBudget, 0, ',', '.'),
            number_format($cpr, 0, ',', '.'),
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
    }

    private function targetKey(AutomationTask $task, Campaign|AdSet $target): string
    {
        return $task->level.':'.$target->external_id;
    }

    public function metricSnapshot(array $insights): array
    {
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
}
