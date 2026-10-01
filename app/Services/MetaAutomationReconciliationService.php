<?php

namespace App\Services;

use App\Exceptions\MetaAdsException;
use App\Models\AdAccount;
use App\Models\AdSet;
use App\Models\AutomationTask;
use App\Models\Campaign;
use App\Models\T4JamProfile;
use App\Support\MetaFlowLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class MetaAutomationReconciliationService
{
    private const METRIC_RETRY_DELAYS = [1 => 60, 2 => 180, 3 => 300];

    public function __construct(
        private readonly AutomationBudgetService $automation,
        private readonly MetaAdsSyncService $metaSync,
        private readonly MetaRateLimitService $rateLimit,
        private readonly AutomationTaskMutationService $taskMutations,
    ) {}

    public function reconcileProfile(
        T4JamProfile $profile,
        ?string $adAccountExternalId = null,
        array $campaignIds = [],
        array $adSetIds = [],
        string $source = 'reconciliation',
    ): array {
        $empty = $this->emptyCounts();

        if (! $profile->hasAccessToken()) {
            return $this->logSummary($profile, $source, $empty);
        }

        if ($this->rateLimit->isRateLimited($profile)) {
            $empty['rate_limited'] = true;
            $empty['skip_reason'] = 'cooldown_active';

            return $this->logSummary($profile, $source, $empty);
        }

        return Cache::lock('meta-automation-reconcile:'.$profile->id, 55)->get(function () use ($profile, $adAccountExternalId, $campaignIds, $adSetIds, $source, $empty): array {
            $selection = $this->selectTasks($profile, $adAccountExternalId, $campaignIds, $adSetIds, $source);
            $counts = array_replace($empty, $selection['counts']);

            if ($selection['tasks']->isEmpty()) {
                return $this->logSummary($profile, $source, $counts);
            }

            $allGroups = $this->groups($selection['tasks'])->sortBy(fn (Collection $tasks) => $this->priority($tasks->first()));
            $groups = $allGroups->take($this->maxTargets());
            $counts['target_limit_reached'] = max(0, $allGroups->count() - $groups->count());
            $counts['pending_actions'] = $selection['tasks']->whereNotNull('pending_meta_action')->count();

            $client = $this->metaSync->client($profile);
            $datePreset = $this->automation->automationInsightsDatePreset();

            foreach ($groups as $group) {
                if ($this->rateLimit->isRateLimited($profile)) {
                    $counts['rate_limited'] = true;
                    $counts['skip_reason'] = 'cooldown_active';

                    break;
                }

                $counts['groups']++;
                $counts['processed_targets']++;
                $result = $this->reconcileGroup($profile, $client, $group, $datePreset, $source);
                $counts['api_calls'] += $result['api_calls'];
                $counts['updated'] += $result['updated'];
                $counts['paused'] += $result['paused'];

                if ($result['rate_limited']) {
                    $counts['rate_limited'] = true;

                    break;
                }
            }

            return $this->logSummary($profile, $source, $counts);
        }) ?? $empty;
    }

    private function selectTasks(T4JamProfile $profile, ?string $adAccountExternalId, array $campaignIds, array $adSetIds, string $source): array
    {
        $query = $this->taskQuery($profile, $adAccountExternalId, $campaignIds, $adSetIds);
        $targetedWebhook = $source === 'webhook' && ($campaignIds !== [] || $adSetIds !== []);

        if ($targetedWebhook) {
            $tasks = $query->get()->filter(fn (AutomationTask $task) => $this->target($task) !== null)->values();

            return ['tasks' => $tasks, 'counts' => ['eligible_tasks' => $tasks->count()]];
        }

        $now = now();
        $freshAfter = now()->subSeconds($this->freshSeconds());
        $notDue = (clone $query)->where('meta_verification_due_at', '>', $now)->count();
        $fresh = (clone $query)
            ->whereNull('pending_meta_action')
            ->whereNull('metrics_unavailable_at')
            ->whereNull('meta_verification_due_at')
            ->where('last_metrics_synced_at', '>', $freshAfter)
            ->get()
            ->filter(fn (AutomationTask $task) => $this->hasSyncableTarget($task))
            ->count();

        $tasks = $query
            ->where(function (Builder $due) use ($now): void {
                $due->whereNull('meta_verification_due_at')
                    ->orWhere('meta_verification_due_at', '<=', $now);
            })
            ->where(function (Builder $eligible) use ($freshAfter): void {
                $eligible->whereNotNull('pending_meta_action')
                    ->orWhereNotNull('metrics_unavailable_at')
                    ->orWhereNotNull('meta_verification_due_at')
                    ->orWhere(function (Builder $stale) use ($freshAfter): void {
                        $stale->where(function (Builder $metrics) use ($freshAfter): void {
                            $metrics->whereNull('last_metrics_synced_at')
                                ->orWhere('last_metrics_synced_at', '<=', $freshAfter);
                        });
                    });
            })
            ->orderByRaw('CASE WHEN pending_meta_action IS NOT NULL THEN 0 WHEN meta_verification_due_at IS NOT NULL THEN 1 WHEN metrics_unavailable_at IS NOT NULL THEN 2 ELSE 3 END')
            ->get()
            ->filter(fn (AutomationTask $task) => $this->hasSyncableTarget($task))
            ->values();

        return [
            'tasks' => $tasks,
            'counts' => [
                'eligible_tasks' => $tasks->count(),
                'skipped_fresh' => $fresh,
                'skipped_not_due' => $notDue,
            ],
        ];
    }

    private function taskQuery(T4JamProfile $profile, ?string $adAccountExternalId, array $campaignIds, array $adSetIds): Builder
    {
        $account = $adAccountExternalId
            ? AdAccount::query()->where('external_id', $this->normalizeAdAccountId($adAccountExternalId))->first()
            : null;

        return AutomationTask::query()
            ->with(['campaign.adAccount', 'adSet.adAccount'])
            ->where('user_id', $profile->user_id)
            ->when($adAccountExternalId, fn (Builder $query) => $account ? $query->where('ad_account_id', $account->id) : $query->whereRaw('1 = 0'))
            ->when($campaignIds !== [] || $adSetIds !== [], function (Builder $query) use ($campaignIds, $adSetIds): void {
                $query->where(function (Builder $targets) use ($campaignIds, $adSetIds): void {
                    if ($campaignIds !== []) {
                        $targets->orWhere(fn (Builder $campaigns) => $campaigns->where('level', 'campaign')->whereIn('campaign_external_id', $campaignIds));
                    }
                    if ($adSetIds !== []) {
                        $targets->orWhere(fn (Builder $adSets) => $adSets->where('level', 'adset')->whereIn('ad_set_external_id', $adSetIds));
                    }
                });
            });
    }

    private function groups(Collection $tasks): Collection
    {
        return $tasks->groupBy(function (AutomationTask $task): string {
            $target = $this->target($task);

            return $task->level.'|'.$target->external_id;
        });
    }

    private function reconcileGroup(T4JamProfile $profile, MetaAdsClient $client, Collection $tasks, string $datePreset, string $source): array
    {
        /** @var AutomationTask $first */
        $first = $tasks->first();
        $target = $this->target($first);
        $level = $first->level;
        $adAccountId = $target->adAccount->external_id;
        $targetId = $target->external_id;
        $apiCalls = 0;

        try {
            $apiCalls++;
            $status = $level === 'adset' ? $client->adSet($targetId) : $client->campaign($targetId);
        } catch (MetaAdsException $exception) {
            $this->markMetricsUnavailable($tasks, $profile, $exception);

            return $this->groupFailure($profile, $targetId, $level, $source, $exception, $apiCalls);
        }

        $updated = $this->syncStatusAndBudget($tasks, collect([$targetId => $status]));

        try {
            $apiCalls++;
            $insights = $level === 'adset'
                ? $client->adSetInsights($targetId, $datePreset)
                : $client->campaignInsights($targetId, $datePreset);
        } catch (MetaAdsException $exception) {
            $this->markMetricsUnavailable($tasks, $profile, $exception);

            return $this->groupFailure($profile, $targetId, $level, $source, $exception, $apiCalls, $updated);
        }

        if ($insights === []) {
            $this->logMetricsUnavailable($profile, $tasks, $targetId, $level, $datePreset);
            $this->markMetricsUnavailable($tasks, $profile);

            return ['api_calls' => $apiCalls, 'updated' => $updated, 'paused' => 0, 'rate_limited' => false];
        }

        $metrics = $this->automation->metricSnapshot($insights);

        if ($metrics === null) {
            $this->logMetricsUnavailable($profile, $tasks, $targetId, $level, $datePreset);
            $this->markMetricsUnavailable($tasks, $profile);

            return ['api_calls' => $apiCalls, 'updated' => $updated, 'paused' => 0, 'rate_limited' => false];
        }

        $freshTargets = [];

        DB::transaction(function () use ($tasks, $metrics, &$freshTargets): void {
            foreach ($tasks as $task) {
                $target = $this->target($task);
                $target->update($this->automation->insightPayload($metrics));
                $freshTargets[$task->level.':'.$target->external_id] = $metrics;
                $changes = [
                    'current_spend' => (int) $metrics['spend'],
                    'current_result' => max(0, (int) ($metrics['results'][$task->conversion] ?? 0)),
                    'last_metrics_synced_at' => $metrics['insights_synced_at'] ?? now(),
                    'metrics_unavailable_at' => null,
                    'meta_reconciliation_failure_count' => 0,
                    'last_checked_at' => now(),
                ];

                if ($task->pending_meta_action === null) {
                    $changes['meta_verification_due_at'] = null;
                }

                $task->update($changes);
            }
        });

        $this->logMetricsSynced($profile, $tasks, $targetId, $level, $datePreset, $insights, $metrics);

        $campaignIds = $level === 'campaign' ? $tasks->pluck('campaign_external_id')->filter()->values()->all() : [];
        $adSetIds = $level === 'adset' ? $tasks->pluck('ad_set_external_id')->filter()->values()->all() : [];
        $paused = $this->automation->reconcileTasks($profile, $client, $adAccountId, $campaignIds, $adSetIds, $freshTargets);

        return ['api_calls' => $apiCalls, 'updated' => $updated, 'paused' => $paused, 'rate_limited' => $this->rateLimit->isRateLimited($profile)];
    }

    private function syncStatusAndBudget(Collection $tasks, Collection $statusById): int
    {
        $updated = 0;

        DB::transaction(function () use ($tasks, $statusById, &$updated): void {
            foreach ($tasks as $task) {
                $target = $this->target($task);
                $remote = $statusById->get($target->external_id);

                if (! is_array($remote)) {
                    continue;
                }

                $target->update([
                    'status' => $remote['status'] ?? $remote['effective_status'] ?? $target->status,
                    'effective_status' => $remote['effective_status'] ?? $target->effective_status,
                    'daily_budget' => (int) ($remote['daily_budget'] ?? $target->daily_budget),
                ]);

                $changes = ['current_budget' => (int) $target->daily_budget];
                $active = strtoupper((string) ($target->effective_status ?: $target->status)) === 'ACTIVE';

                if ($this->taskMutations->hasPendingPause($task) && ! $active) {
                    $changes += [
                        'is_active' => false,
                        'pending_meta_action' => null,
                        'meta_verification_due_at' => null,
                        'last_budget_action' => 'pause',
                        'last_log' => 'Pending pause telah terkonfirmasi dari Meta Ads Manager.',
                    ];
                } elseif ($task->pending_meta_action === null) {
                    $changes['meta_verification_due_at'] = null;
                }

                if ($task->is_active && ! $active) {
                    $changes += [
                        'is_active' => false,
                        'last_budget_action' => 'meta_sync',
                        'last_log' => 'Status dipause dari Meta Ads Manager.',
                    ];
                }

                $task->update($changes);
                $updated++;
            }
        });

        return $updated;
    }

    private function groupFailure(T4JamProfile $profile, string $targetId, string $level, string $source, MetaAdsException $exception, int $apiCalls, int $updated = 0): array
    {
        MetaFlowLog::warning('automation reconciliation group failed', [
            'profile_id' => $profile->id,
            'target_id' => $targetId,
            'level' => $level,
            'source' => $source,
            'http_status' => $exception->httpStatus,
            'meta_code' => $exception->metaCode,
        ]);

        return [
            'api_calls' => $apiCalls,
            'updated' => $updated,
            'paused' => 0,
            'rate_limited' => $this->rateLimit->isRateLimitCode($exception->metaCode, $exception->httpStatus),
        ];
    }

    private function markMetricsUnavailable(Collection $tasks, T4JamProfile $profile, ?MetaAdsException $exception = null): void
    {
        $rateLimited = $exception !== null
            && $this->rateLimit->isRateLimitCode($exception->metaCode, $exception->httpStatus);

        $tasks->each(function (AutomationTask $task) use ($profile, $rateLimited): void {
            $attempt = max(1, (int) $task->meta_reconciliation_failure_count + 1);
            $dueAt = $rateLimited
                ? $this->rateLimit->cooldownUntil($profile) ?? now()->addSeconds(60)
                : now()->addSeconds(self::METRIC_RETRY_DELAYS[min(3, $attempt)]);

            $task->update([
                'metrics_unavailable_at' => now(),
                'meta_verification_due_at' => $dueAt,
                'meta_reconciliation_failure_count' => $rateLimited ? $task->meta_reconciliation_failure_count : min(3, $attempt),
            ]);
        });
    }

    private function logMetricsSynced(
        T4JamProfile $profile,
        Collection $tasks,
        string $targetId,
        string $level,
        string $datePreset,
        array $insights,
        array $metrics,
    ): void {
        $tasks->each(function (AutomationTask $task) use ($profile, $targetId, $level, $datePreset, $insights, $metrics): void {
            MetaFlowLog::info('automation metric synced', [
                'profile_id' => $profile->id,
                'automation_task_id' => $task->id,
                'target_id' => $targetId,
                'level' => $level,
                'date_preset' => $datePreset,
                'has_insight_row' => true,
                'raw_spend' => $insights['spend'] ?? null,
                'spend' => $metrics['spend'],
                'conversion' => $task->conversion,
                'conversion_result' => $metrics['results'][$task->conversion] ?? 0,
                'date_start' => $insights['date_start'] ?? null,
                'date_stop' => $insights['date_stop'] ?? null,
            ]);
        });
    }

    private function logMetricsUnavailable(T4JamProfile $profile, Collection $tasks, string $targetId, string $level, string $datePreset): void
    {
        $tasks->each(function (AutomationTask $task) use ($profile, $targetId, $level, $datePreset): void {
            MetaFlowLog::info('automation metric unavailable', [
                'profile_id' => $profile->id,
                'automation_task_id' => $task->id,
                'target_id' => $targetId,
                'level' => $level,
                'date_preset' => $datePreset,
                'has_insight_row' => false,
                'conversion' => $task->conversion,
            ]);
        });
    }

    private function emptyCounts(): array
    {
        return [
            'groups' => 0,
            'eligible_tasks' => 0,
            'processed_targets' => 0,
            'skipped_fresh' => 0,
            'skipped_not_due' => 0,
            'target_limit_reached' => 0,
            'pending_actions' => 0,
            'api_calls' => 0,
            'updated' => 0,
            'paused' => 0,
            'rate_limited' => false,
            'skip_reason' => null,
        ];
    }

    private function logSummary(T4JamProfile $profile, string $source, array $counts): array
    {
        $skipReasons = array_filter([
            'metrics_fresh' => $counts['skipped_fresh'],
            'verification_not_due' => $counts['skipped_not_due'],
            'target_limit_reached' => $counts['target_limit_reached'],
            'cooldown_active' => $counts['rate_limited'],
        ]);

        MetaFlowLog::info('automation reconciliation finished', [
            'profile_id' => $profile->id,
            'source' => $source,
            'skip_reasons' => $skipReasons,
            ...$counts,
        ]);

        return $counts;
    }

    private function priority(AutomationTask $task): int
    {
        if ($task->pending_meta_action !== null) {
            return 0;
        }

        if ($task->meta_verification_due_at !== null) {
            return 1;
        }

        return $task->metrics_unavailable_at !== null ? 2 : 3;
    }

    private function freshSeconds(): int
    {
        return max(1, (int) config('services.meta.automation_reconcile_fresh_seconds', 180));
    }

    private function maxTargets(): int
    {
        return max(1, (int) config('services.meta.automation_reconcile_max_targets', 25));
    }

    private function target(AutomationTask $task): Campaign|AdSet|null
    {
        return $task->level === 'adset' ? $task->adSet : $task->campaign;
    }

    private function hasSyncableTarget(AutomationTask $task): bool
    {
        $target = $this->target($task);

        return $target !== null
            && ! in_array(strtoupper((string) $target->status), ['ARCHIVED', 'DELETED'], true)
            && ! in_array(strtoupper((string) $target->effective_status), ['ARCHIVED', 'DELETED'], true);
    }

    private function normalizeAdAccountId(string $id): string
    {
        return str_starts_with($id, 'act_') ? $id : 'act_'.$id;
    }
}
