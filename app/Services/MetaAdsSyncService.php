<?php

namespace App\Services;

use App\Exceptions\MetaAdsException;
use App\Models\AdAccount;
use App\Models\AdSet;
use App\Models\Campaign;
use App\Models\T4JamProfile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MetaAdsSyncService
{
    private array $warnings = [];

    public function sync(T4JamProfile $profile): array
    {
        $this->warnings = [];
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
                $account = $this->upsertAccount($accountData);
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

        if ($this->warnings !== []) {
            $counts['warning'] = end($this->warnings);
            $profile->update(['last_meta_error' => $counts['warning']]);
        }

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
            ->whereIn('id', $campaignIds)
            ->get()
            ->each(function (Campaign $campaign) use ($client, &$count): void {
                $insights = $this->optionalMetaRequest(
                    fn () => $client->campaignInsights($campaign->external_id),
                    'Meta campaign insights skipped',
                    ['campaign_id' => $campaign->external_id],
                );

                if ($insights !== []) {
                    $campaign->update($this->insightPayload($insights));
                    $count++;
                }
            });

        AdSet::query()
            ->whereIn('id', $adSetIds)
            ->get()
            ->each(function (AdSet $adSet) use ($client, &$count): void {
                $insights = $this->optionalMetaRequest(
                    fn () => $client->adSetInsights($adSet->external_id),
                    'Meta ad set insights skipped',
                    ['ad_set_id' => $adSet->external_id],
                );

                if ($insights !== []) {
                    $adSet->update($this->insightPayload($insights));
                    $count++;
                }
            });

        return $count;
    }

    private function optionalMetaRequest(callable $callback, string $message, array $context = []): array
    {
        try {
            return $callback();
        } catch (MetaAdsException $exception) {
            $this->warnings[] = $exception->getMessage();

            Log::warning($message, $context + [
                'http_status' => $exception->httpStatus,
                'meta_code' => $exception->metaCode,
                'meta_type' => $exception->metaType,
            ]);

            return [];
        }
    }

    private function upsertAccount(array $accountData): AdAccount
    {
        return AdAccount::updateOrCreate(
            ['external_id' => $accountData['id']],
            [
                'account_id' => (string) ($accountData['account_id'] ?? str_replace('act_', '', $accountData['id'])),
                'name' => $accountData['name'] ?? $accountData['id'],
                'currency' => $accountData['currency'] ?? 'IDR',
                'account_status' => $accountData['account_status'] ?? null,
            ],
        );
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

    private function insightPayload(array $insights): array
    {
        $actions = collect($insights['actions'] ?? []);
        $result = $this->actionValue($actions, ['purchase', 'lead', 'add_to_cart', 'initiate_checkout', 'contact_website', 'onsite_conversion.messaging_conversation_started_7d']);

        return [
            'spend' => (int) round((float) ($insights['spend'] ?? 0)),
            'reach' => (int) ($insights['reach'] ?? 0),
            'result' => $result,
            'link_click' => (int) ($insights['inline_link_clicks'] ?? $this->actionValue($actions, ['link_click'])),
            'landing_page_view' => $this->actionValue($actions, ['landing_page_view']),
            'insights_synced_at' => now(),
        ];
    }

    private function actionValue($actions, array $types): int
    {
        return (int) $actions
            ->whereIn('action_type', $types)
            ->sum(fn (array $action) => (int) ($action['value'] ?? 0));
    }

    public function syncCampaignsForAccount(
        T4JamProfile $profile,
        string $adAccountExternalId
    ): array {
        $this->warnings = [];

        $client = $this->client($profile);

        $accountData = $client->adAccount($adAccountExternalId);
        $campaigns = $client->campaigns($adAccountExternalId);

        $counts = [
            'accounts' => 0,
            'campaigns' => 0,
        ];

        DB::transaction(function () use (
            $accountData,
            $campaigns,
            &$counts
        ): void {
            $account = $this->upsertAccount($accountData);

            $counts['accounts'] = 1;

            foreach ($campaigns as $campaignData) {
                $this->upsertCampaign($account, $campaignData);
                $counts['campaigns']++;
            }
        });

        $profile->update([
            'last_meta_sync_at' => now(),
            'last_meta_error' => null,
        ]);

        return $counts;
    }
}
