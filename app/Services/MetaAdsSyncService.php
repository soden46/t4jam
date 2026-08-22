<?php

namespace App\Services;

use App\Exceptions\MetaAdsException;
use App\Models\AdAccount;
use App\Models\AdSet;
use App\Models\Campaign;
use App\Models\T4JamProfile;
use Illuminate\Support\Facades\DB;

class MetaAdsSyncService
{
    public function sync(T4JamProfile $profile): array
    {
        $client = $this->client($profile);
        $metaUser = $client->validateToken();
        $accounts = $this->prefetchAccounts($client);
        $counts = ['accounts' => 0, 'campaigns' => 0, 'adsets' => 0, 'insights' => 0];

        DB::transaction(function () use ($profile, $metaUser, $accounts, &$counts): void {
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

                    $insights = $campaignData['_insights'] ?? [];
                    if ($insights) {
                        $campaign->update($this->insightPayload($insights));
                        $counts['insights']++;
                    }

                    foreach ($campaignData['_adsets'] ?? [] as $adSetData) {
                        $adSet = $this->upsertAdSet($account, $campaign, $adSetData);
                        $counts['adsets']++;

                        $adSetInsights = $adSetData['_insights'] ?? [];
                        if ($adSetInsights) {
                            $adSet->update($this->insightPayload($adSetInsights));
                            $counts['insights']++;
                        }
                    }
                }
            }
        });

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
                    ? collect($client->campaigns($accountId))
                        ->map(function (array $campaignData) use ($client): array {
                            $campaignId = $campaignData['id'] ?? null;

                            return $campaignData + [
                                '_insights' => $campaignId ? $client->campaignInsights($campaignId) : [],
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
        return collect($client->adSets($campaignId))
            ->map(function (array $adSetData) use ($client): array {
                $adSetId = $adSetData['id'] ?? null;

                return $adSetData + [
                    '_insights' => $adSetId ? $client->adSetInsights($adSetId) : [],
                ];
            })
            ->all();
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
}
