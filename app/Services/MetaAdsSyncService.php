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
use Illuminate\Support\Facades\DB;

class MetaAdsSyncService
{
    private array $warnings = [];

    private array $freshInsightTargets = [];

    public function sync(T4JamProfile $profile): array
    {
        $this->warnings = [];
        $this->freshInsightTargets = [];
        MetaFlowLog::info('full sync started', ['profile_id' => $profile->id]);
        $client = $this->client($profile);
        $metaUser = $client->validateToken();
        $accounts = $this->prefetchAccounts($client);
        $counts = ['accounts' => 0, 'campaigns' => 0, 'adsets' => 0, 'insights' => 0];
        $campaignIds = [];
        $adSetIds = [];

        DB::transaction(function () use ($profile, $metaUser, $accounts, &$counts, &$campaignIds, &$adSetIds): void {
            $profile->update([
                'meta_user_id' => $metaUser['id'] ?? null,
                'meta_user_name' => $metaUser['name'] ?? null,
                'meta_connected_at' => now(),
                'last_meta_sync_at' => now(),
                'last_meta_error' => null,
            ]);

            foreach ($accounts as $accountData) {
                $account = $this->upsertAccount($accountData, $profile);
                $counts['accounts']++;

                foreach ($accountData['_campaigns'] ?? [] as $campaignData) {
                    $campaign = $this->upsertCampaign($account, $campaignData);
                    $counts['campaigns']++;
                    $campaignIds[] = $campaign->id;

                    foreach ($campaignData['_adsets'] ?? [] as $adSetData) {
                        $adSet = $this->upsertAdSet($account, $campaign, $adSetData);
                        $counts['adsets']++;
                        $adSetIds[] = $adSet->id;
                    }
                }
            }
        });

        $counts['insights'] = $this->syncInsights($client, $campaignIds, $adSetIds);
        $counts['automation_paused'] = app(AutomationBudgetService::class)->pauseTasksOverCprCap(
            $profile,
            $client,
            syncedTargets: $this->freshInsightTargets,
            source: 'sync',
        );

        if ($this->warnings !== []) {
            $counts['warning'] = end($this->warnings);
            $profile->update(['last_meta_error' => $counts['warning']]);
        }

        MetaFlowLog::info('full sync finished', [
            'profile_id' => $profile->id,
            'accounts' => $counts['accounts'] ?? 0,
            'campaigns' => $counts['campaigns'] ?? 0,
            'adsets' => $counts['adsets'] ?? 0,
            'insights' => $counts['insights'] ?? 0,
            'has_warning' => isset($counts['warning']),
        ]);

        return $counts;
    }

    public function client(T4JamProfile $profile): MetaAdsClient
    {
        if (! $profile->access_token) {
            throw new MetaAdsException('Access token Meta belum diisi.');
        }

        return new MetaAdsClient($profile->access_token);
    }

    private function prefetchAccounts(MetaAdsClient $client): array
    {
        return collect($client->adAccounts())
            ->map(function (array $accountData) use ($client): array {
                $accountId = $accountData['id'] ?? null;
                $campaigns = $accountId
                    ? collect($this->optionalMetaRequest(
                        fn () => $client->campaigns($accountId),
                        'Meta campaign lookup skipped',
                        ['ad_account_id' => $accountId],
                    ))
                        ->map(function (array $campaignData) use ($client): array {
                            $campaignId = $campaignData['id'] ?? null;

                            return $campaignData + [
                                '_adsets' => $campaignId ? $this->prefetchAdSets($client, $campaignId) : [],
                            ];
                        })
                        ->all()
                    : [];

                return $accountData + ['_campaigns' => $campaigns];
            })
            ->all();
    }

    private function prefetchAdSets(MetaAdsClient $client, string $campaignId): array
    {
        return collect($this->optionalMetaRequest(
            fn () => $client->adSets($campaignId),
            'Meta ad set lookup skipped',
            ['campaign_id' => $campaignId],
        ))
            ->all();
    }

    private function syncInsights(MetaAdsClient $client, array $campaignIds, array $adSetIds): int
    {
        $count = 0;

        Campaign::query()
            ->with('adAccount')
            ->whereIn('id', $campaignIds)
            ->get()
            ->groupBy(fn (Campaign $campaign) => $campaign->adAccount?->external_id)
            ->each(function ($campaigns, ?string $adAccountId) use ($client, &$count): void {
                if (! $adAccountId) {
                    return;
                }

                $insightsByCampaign = collect($this->optionalMetaRequest(
                    fn () => $client->accountCampaignInsights($adAccountId),
                    'Meta campaign insights skipped',
                    ['ad_account_id' => $adAccountId],
                ))->keyBy('campaign_id');

                foreach ($campaigns as $campaign) {
                    $insights = $insightsByCampaign->get($campaign->external_id, []);

                    if ($insights !== []) {
                        $campaign->update($this->insightPayload($insights));
                        $this->freshInsightTargets['campaign:'.$campaign->external_id] = app(AutomationBudgetService::class)->metricSnapshot($insights);
                        $count++;
                    }
                }
            });

        AdSet::query()
            ->with('adAccount')
            ->whereIn('id', $adSetIds)
            ->get()
            ->groupBy(fn (AdSet $adSet) => $adSet->adAccount?->external_id)
            ->each(function ($adSets, ?string $adAccountId) use ($client, &$count): void {
                if (! $adAccountId) {
                    return;
                }

                $insightsByAdSet = collect($this->optionalMetaRequest(
                    fn () => $client->accountAdSetInsights($adAccountId),
                    'Meta ad set insights skipped',
                    ['ad_account_id' => $adAccountId],
                ))->keyBy('adset_id');

                foreach ($adSets as $adSet) {
                    $insights = $insightsByAdSet->get($adSet->external_id, []);

                    if ($insights !== []) {
                        $adSet->update($this->insightPayload($insights));
                        $this->freshInsightTargets['adset:'.$adSet->external_id] = app(AutomationBudgetService::class)->metricSnapshot($insights);
                        $count++;
                    }
                }
            });

        return $count;
    }

    private function optionalMetaRequest(callable $callback, string $message, array $context = []): array
    {
        try {
            return $callback();
        } catch (MetaAdsException $exception) {
            if ($exception->retryable() || $exception->metaCode === 190 || $exception->httpStatus === 401) {
                throw $exception;
            }
            $this->warnings[] = $exception->getMessage();

            MetaFlowLog::warning($message, $context + [
                'http_status' => $exception->httpStatus,
                'meta_code' => $exception->metaCode,
                'meta_type' => $exception->metaType,
            ]);

            return [];
        }
    }

    private function upsertAccount(array $accountData, ?T4JamProfile $profile = null): AdAccount
    {
        $account = AdAccount::updateOrCreate(
            ['external_id' => $accountData['id']],
            [
                'account_id' => (string) ($accountData['account_id'] ?? str_replace('act_', '', $accountData['id'])),
                'name' => $accountData['name'] ?? $accountData['id'],
                'currency' => $accountData['currency'] ?? 'IDR',
                'account_status' => $accountData['account_status'] ?? null,
            ],
        );

        $profile?->adAccounts()->syncWithoutDetaching([$account->id]);

        return $account;
    }

    private function upsertCampaign(AdAccount $account, array $campaignData): Campaign
    {
        return Campaign::updateOrCreate(
            ['external_id' => $campaignData['id']],
            [
                'ad_account_id' => $account->id,
                'name' => $campaignData['name'] ?? $campaignData['id'],
                'status' => $campaignData['status'] ?? $campaignData['effective_status'] ?? 'UNKNOWN',
                'effective_status' => $campaignData['effective_status'] ?? null,
                'budget_type' => 'campaign',
                'level' => 'campaign',
                'objective' => $campaignData['objective'] ?? null,
                'daily_budget' => (int) ($campaignData['daily_budget'] ?? 0),
            ],
        );
    }

    private function upsertAdSet(AdAccount $account, Campaign $campaign, array $adSetData): AdSet
    {
        return AdSet::updateOrCreate(
            ['external_id' => $adSetData['id']],
            [
                'ad_account_id' => $account->id,
                'campaign_id' => $campaign->id,
                'name' => $adSetData['name'] ?? $adSetData['id'],
                'status' => $adSetData['status'] ?? $adSetData['effective_status'] ?? 'UNKNOWN',
                'effective_status' => $adSetData['effective_status'] ?? null,
                'daily_budget' => (int) ($adSetData['daily_budget'] ?? 0),
            ],
        );
    }

    private function markMissingCampaignsDeleted(AdAccount $account, array $campaignIds): void
    {
        $missingCampaigns = $account->campaigns()
            ->when($campaignIds !== [], fn ($query) => $query->whereNotIn('id', $campaignIds))
            ->get();

        foreach ($missingCampaigns as $campaign) {
            $campaign->update(['status' => 'DELETED', 'effective_status' => 'DELETED']);
            $campaign->adSets()->update(['status' => 'DELETED', 'effective_status' => 'DELETED']);
        }
    }

    private function markMissingAdSetsDeleted(AdAccount $account, array $adSetIds): void
    {
        $account->adSets()
            ->when($adSetIds !== [], fn ($query) => $query->whereNotIn('id', $adSetIds))
            ->update(['status' => 'DELETED', 'effective_status' => 'DELETED']);
    }

    private function insightPayload(array $insights): array
    {
        return app(AutomationBudgetService::class)->insightPayload(
            app(AutomationBudgetService::class)->metricSnapshot($insights),
        );
    }

    public function syncCampaignsForAccount(
        T4JamProfile $profile,
        string $adAccountExternalId
    ): array {
        $this->warnings = [];

        MetaFlowLog::info('selected account campaign sync started', [
            'profile_id' => $profile->id,
            'ad_account_id' => $adAccountExternalId,
        ]);

        $client = $this->client($profile);

        $accountData = $client->adAccount($adAccountExternalId);
        $campaigns = $client->campaigns($adAccountExternalId);

        $counts = [
            'accounts' => 0,
            'campaigns' => 0,
        ];

        DB::transaction(function () use (
            $profile,
            $accountData,
            $campaigns,
            &$counts
        ): void {
            $account = $this->upsertAccount($accountData, $profile);
            $campaignIdsForAccount = [];

            $counts['accounts'] = 1;

            foreach ($campaigns as $campaignData) {
                $campaign = $this->upsertCampaign($account, $campaignData);
                $campaignIdsForAccount[] = $campaign->id;
                $counts['campaigns']++;
            }

            $this->markMissingCampaignsDeleted($account, $campaignIdsForAccount);
        });

        $profile->update([
            'last_meta_sync_at' => now(),
            'last_meta_error' => null,
        ]);

        MetaFlowLog::info('selected account campaign sync finished', [
            'profile_id' => $profile->id,
            'ad_account_id' => $adAccountExternalId,
            'accounts' => $counts['accounts'],
            'campaigns' => $counts['campaigns'],
        ]);

        return $counts;
    }

    public function syncAccountFromWebhook(
        T4JamProfile $profile,
        string $adAccountExternalId,
        array $campaignIds = [],
        array $adSetIds = [],
    ): array {
        $this->warnings = [];
        $adAccountExternalId = $this->normalizeAdAccountId($adAccountExternalId);
        $client = $this->client($profile);
        $freshInsightTargets = [];

        MetaFlowLog::info('webhook account sync started', [
            'profile_id' => $profile->id,
            'ad_account_id' => $adAccountExternalId,
        ]);

        $accountData = $client->adAccount($adAccountExternalId);
        $campaigns = $client->campaigns($adAccountExternalId);
        $adSets = $client->accountAdSets($adAccountExternalId);
        $campaignInsights = collect($this->optionalMetaRequest(
            fn () => $client->accountCampaignInsights($adAccountExternalId),
            'Meta webhook campaign insights skipped',
            ['ad_account_id' => $adAccountExternalId],
        ))->keyBy('campaign_id');
        $adSetInsights = collect($this->optionalMetaRequest(
            fn () => $client->accountAdSetInsights($adAccountExternalId),
            'Meta webhook ad set insights skipped',
            ['ad_account_id' => $adAccountExternalId],
        ))->keyBy('adset_id');

        $counts = DB::transaction(function () use (
            $profile,
            $accountData,
            $campaigns,
            $adSets,
            $campaignInsights,
            $adSetInsights,
            &$freshInsightTargets,
        ): array {
            $account = $this->upsertAccount($accountData, $profile);
            $campaignModels = [];
            $campaignIds = [];
            $adSetIds = [];
            $insightCount = 0;

            foreach ($campaigns as $campaignData) {
                $campaign = $this->upsertCampaign($account, $campaignData);
                $campaignModels[$campaign->external_id] = $campaign;
                $campaignIds[] = $campaign->id;

                if ($insights = $campaignInsights->get($campaign->external_id)) {
                    $metrics = app(AutomationBudgetService::class)->metricSnapshot($insights);
                    $campaign->update(app(AutomationBudgetService::class)->insightPayload($metrics));
                    $freshInsightTargets['campaign:'.$campaign->external_id] = $metrics;
                    $insightCount++;
                }
            }

            $this->markMissingCampaignsDeleted($account, $campaignIds);

            foreach ($adSets as $adSetData) {
                $campaignExternalId = $adSetData['campaign_id'] ?? null;
                $campaign = $campaignExternalId ? ($campaignModels[$campaignExternalId] ?? null) : null;

                if (! $campaign) {
                    continue;
                }

                $adSet = $this->upsertAdSet($account, $campaign, $adSetData);
                $adSetIds[] = $adSet->id;

                if ($insights = $adSetInsights->get($adSet->external_id)) {
                    $metrics = app(AutomationBudgetService::class)->metricSnapshot($insights);
                    $adSet->update(app(AutomationBudgetService::class)->insightPayload($metrics));
                    $freshInsightTargets['adset:'.$adSet->external_id] = $metrics;
                    $insightCount++;
                }
            }

            $this->markMissingAdSetsDeleted($account, $adSetIds);
            $this->reconcileAutomationTasks($profile, $account);

            return [
                'accounts' => 1,
                'campaigns' => count($campaignIds),
                'adsets' => count($adSetIds),
                'insights' => $insightCount,
            ];
        });

        $profile->update([
            'last_meta_sync_at' => now(),
            'last_meta_error' => $this->warnings === [] ? null : end($this->warnings),
        ]);

        $counts['automation_paused'] = app(AutomationBudgetService::class)->pauseWebhookTasksOverCprCap(
            $profile,
            $client,
            $adAccountExternalId,
            $campaignIds,
            $adSetIds,
            $freshInsightTargets,
        );

        MetaFlowLog::info('webhook account sync finished', [
            'profile_id' => $profile->id,
            'ad_account_id' => $adAccountExternalId,
            'campaigns' => $counts['campaigns'],
            'adsets' => $counts['adsets'],
            'insights' => $counts['insights'],
            'automation_paused' => $counts['automation_paused'],
        ]);

        return $counts;
    }

    private function reconcileAutomationTasks(T4JamProfile $profile, AdAccount $account): void
    {
        AutomationTask::query()
            ->where('user_id', $profile->user_id)
            ->where('ad_account_id', $account->id)
            ->with(['campaign', 'adSet'])
            ->get()
            ->each(function (AutomationTask $task): void {
                $target = $task->level === 'adset' ? $task->adSet : $task->campaign;

                if (! $target) {
                    return;
                }

                $active = strtoupper((string) $target->status) === 'ACTIVE';
                $budgetChanged = (int) $task->current_budget !== (int) $target->daily_budget;
                $statusChanged = (bool) $task->is_active !== $active;

                if (! $budgetChanged && ! $statusChanged) {
                    return;
                }

                $changes = ['current_budget' => (int) $target->daily_budget];
                $messages = [];

                if ($budgetChanged) {
                    $messages[] = 'Budget disinkronkan dari Meta Ads Manager.';
                }

                if ($statusChanged) {
                    $changes += [
                        'is_active' => $active,
                        'last_budget_action' => 'meta_sync',
                    ];
                    $messages[] = $active
                        ? 'Status diaktifkan dari Meta Ads Manager.'
                        : 'Status dipause dari Meta Ads Manager.';
                }

                $message = implode(' ', $messages);
                $changes['last_log'] = $message;
                $task->update($changes);
                AutomationLog::create([
                    'automation_task_id' => $task->id,
                    'messages' => [$message],
                ]);
            });
    }

    private function normalizeAdAccountId(string $adAccountExternalId): string
    {
        return str_starts_with($adAccountExternalId, 'act_')
            ? $adAccountExternalId
            : 'act_'.$adAccountExternalId;
    }
}
