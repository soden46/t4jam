<?php

namespace App\Services;

use App\Exceptions\MetaAdsException;
use App\Models\AdAccount;
use App\Models\AdSet;
use App\Models\AutomationTask;
use App\Models\Campaign;
use App\Models\T4JamProfile;
use App\Support\MetaFlowLog;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class MetaAutomationReconciliationService
{
    public function __construct(
        private readonly AutomationBudgetService $automation,
        private readonly MetaAdsSyncService $metaSync,
        private readonly MetaRateLimitService $rateLimit,
    ) {}

    public function reconcileProfile(
        T4JamProfile $profile,
        ?string $adAccountExternalId = null,
        array $campaignIds = [],
        array $adSetIds = [],
        string $source = 'reconciliation',
    ): array {
        $empty = ['groups' => 0, 'api_calls' => 0, 'updated' => 0, 'paused' => 0, 'rate_limited' => false];

        if (! $profile->hasAccessToken() || $this->rateLimit->isRateLimited($profile)) {
            return $empty + ['rate_limited' => $this->rateLimit->isRateLimited($profile)];
        }

        return Cache::lock('meta-automation-reconcile:'.$profile->id, 55)->get(function () use ($profile, $adAccountExternalId, $campaignIds, $adSetIds, $source, $empty): array {
            $tasks = $this->relevantTasks($profile, $adAccountExternalId, $campaignIds, $adSetIds);

            if ($tasks->isEmpty()) {
                return $empty;
            }

            $client = $this->metaSync->client($profile);
            $counts = $empty;
            $datePreset = $this->automation->automationInsightsDatePreset();

            foreach ($this->groups($tasks) as $group) {
                if ($this->rateLimit->isRateLimited($profile)) {
                    $counts['rate_limited'] = true;

                    break;
                }

                $counts['groups']++;
                $result = $this->reconcileGroup($profile, $client, $group, $datePreset, $source);
                $counts['api_calls'] += $result['api_calls'];
                $counts['updated'] += $result['updated'];
                $counts['paused'] += $result['paused'];

                if ($result['rate_limited']) {
                    $counts['rate_limited'] = true;

                    break;
                }
            }

            MetaFlowLog::info('automation reconciliation finished', [
                'profile_id' => $profile->id,
                'source' => $source,
                'groups' => $counts['groups'],
                'api_calls' => $counts['api_calls'],
                'updated' => $counts['updated'],
                'paused' => $counts['paused'],
                'rate_limited' => $counts['rate_limited'],
            ]);

            return $counts;
        }) ?? $empty;
    }

    private function relevantTasks(T4JamProfile $profile, ?string $adAccountExternalId, array $campaignIds, array $adSetIds): Collection
    {
        $account = $adAccountExternalId
            ? AdAccount::query()->where('external_id', $this->normalizeAdAccountId($adAccountExternalId))->first()
            : null;

        return AutomationTask::query()
            ->with(['campaign.adAccount', 'adSet.adAccount'])
            ->where('user_id', $profile->user_id)
            ->when($adAccountExternalId, fn ($query) => $account ? $query->where('ad_account_id', $account->id) : $query->whereRaw('1 = 0'))
            ->when($campaignIds !== [] || $adSetIds !== [], function ($query) use ($campaignIds, $adSetIds): void {
                $query->where(function ($targets) use ($campaignIds, $adSetIds): void {
                    if ($campaignIds !== []) {
                        $targets->orWhere(fn ($campaigns) => $campaigns->where('level', 'campaign')->whereIn('campaign_external_id', $campaignIds));
                    }
                    if ($adSetIds !== []) {
                        $targets->orWhere(fn ($adSets) => $adSets->where('level', 'adset')->whereIn('ad_set_external_id', $adSetIds));
                    }
                });
            })
            ->where(function ($query): void {
                $query->where('is_active', true)
                    ->orWhereNotNull('pending_meta_action')
                    ->orWhereNotNull('metrics_unavailable_at')
                    ->orWhereNotNull('meta_verification_due_at');
            })
            ->get()
            ->filter(fn (AutomationTask $task) => $this->target($task) !== null)
            ->values();
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
            $status = $level === 'adset'
                ? $client->adSet($targetId)
                : $client->campaign($targetId);
        } catch (MetaAdsException $exception) {
            $this->markMetricsUnavailable($tasks);

            return $this->groupFailure($profile, $targetId, $level, $source, $exception, $apiCalls);
        }

        $statusById = collect([$targetId => $status]);
        $updated = $this->syncStatusAndBudget($tasks, $statusById);

        try {
            $apiCalls++;
            $insights = $level === 'adset'
                ? $client->adSetInsights($targetId, $datePreset)
                : $client->campaignInsights($targetId, $datePreset);
        } catch (MetaAdsException $exception) {
            $this->markMetricsUnavailable($tasks);

            return $this->groupFailure($profile, $targetId, $level, $source, $exception, $apiCalls, $updated);
        }

        $freshTargets = [];

        DB::transaction(function () use ($tasks, $insights, &$freshTargets): void {
            foreach ($tasks as $task) {
                $target = $this->target($task);
                if (is_array($insights)) {
                    $metrics = $this->automation->metricSnapshot($insights);
                    $target->update($this->automation->insightPayload($metrics));
                    $freshTargets[$task->level.':'.$target->external_id] = $metrics;
                    $task->update([
                        'current_spend' => (int) $metrics['spend'],
                        'current_result' => max(0, (int) ($metrics['results'][$task->conversion] ?? 0)),
                        'last_metrics_synced_at' => $metrics['insights_synced_at'] ?? now(),
                        'metrics_unavailable_at' => null,
                        'last_checked_at' => now(),
                    ]);
                } else {
                    $task->update(['metrics_unavailable_at' => now()]);
                }
            }
        });

        $campaignIds = $level === 'campaign' ? $tasks->pluck('campaign_external_id')->filter()->values()->all() : [];
        $adSetIds = $level === 'adset' ? $tasks->pluck('ad_set_external_id')->filter()->values()->all() : [];
        $paused = $this->automation->reconcileTasks(
            $profile,
            $client,
            $adAccountId,
            $campaignIds,
            $adSetIds,
            $freshTargets,
        );

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
                    $task->update(['metrics_unavailable_at' => now()]);

                    continue;
                }

                $target->update([
                    'status' => $remote['status'] ?? $remote['effective_status'] ?? $target->status,
                    'effective_status' => $remote['effective_status'] ?? $target->effective_status,
                    'daily_budget' => (int) ($remote['daily_budget'] ?? $target->daily_budget),
                ]);

                $changes = [
                    'current_budget' => (int) $target->daily_budget,
                    'meta_verification_due_at' => null,
                ];
                $active = strtoupper((string) ($target->effective_status ?: $target->status)) === 'ACTIVE';

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

    private function markMetricsUnavailable(Collection $tasks): void
    {
        $tasks->each(fn (AutomationTask $task) => $task->update(['metrics_unavailable_at' => now()]));
    }

    private function target(AutomationTask $task): Campaign|AdSet|null
    {
        return $task->level === 'adset' ? $task->adSet : $task->campaign;
    }

    private function normalizeAdAccountId(string $id): string
    {
        return str_starts_with($id, 'act_') ? $id : 'act_'.$id;
    }
}
