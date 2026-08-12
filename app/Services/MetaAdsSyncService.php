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
        $accounts = $client->adAccounts();
        $campaignsByAccount = $this->campaignsByAccount($client, $accounts);
        $counts = ['accounts' => 0, 'campaigns' => 0, 'adsets' => 0, 'insights' => 0];

        DB::transaction(function () use ($profile, $metaUser, $accounts, $campaignsByAccount, &$counts): void {
            $profile->update([
                'meta_user_id' => $metaUser['id'] ?? null,
                'meta_user_name' => $metaUser['name'] ?? null,
                'meta_connected_at' => now(),
                'last_meta_sync_at' => now(),
                'last_meta_error' => null,
            ]);

            foreach ($accounts as $accountData) {
                $account = AdAccount::updateOrCreate(
                    ['external_id' => $accountData['id']],
                    [
                        'account_id' => (string) ($accountData['account_id'] ?? str_replace('act_', '', $accountData['id'])),
                        'name' => $accountData['name'] ?? $accountData['id'],
                        'currency' => $accountData['currency'] ?? 'IDR',
                        'account_status' => $accountData['account_status'] ?? null,
                    ],
                );
                $counts['accounts']++;

                foreach ($campaignsByAccount[$accountData['id']] ?? [] as $campaignData) {
                    $campaign = Campaign::updateOrCreate(
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
                    $counts['campaigns']++;

                    $insights = $campaignData['insights'];
                    if ($insights) {
                        $campaign->update($this->insightPayload($insights));
                        $counts['insights']++;
                    }

                    foreach ($campaignData['adsets'] ?? [] as $adSetData) {
                        $adSet = AdSet::updateOrCreate(
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
                        $counts['adsets']++;

                        $adSetInsights = $adSetData['insights'];
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

    private function campaignsByAccount(MetaAdsClient $client, array $accounts): array
    {
        $campaignsByAccount = [];

        foreach ($accounts as $accountData) {
            $accountId = $accountData['id'];
            $campaignsByAccount[$accountId] = [];

            foreach ($client->campaigns($accountId) as $campaignData) {
                $campaignData['insights'] = $client->campaignInsights($campaignData['id']);
                $campaignData['adsets'] = [];

                foreach ($client->adSets($campaignData['id']) as $adSetData) {
                    $adSetData['insights'] = $client->adSetInsights($adSetData['id']);
                    $campaignData['adsets'][] = $adSetData;
                }

                $campaignsByAccount[$accountId][] = $campaignData;
            }
        }

        return $campaignsByAccount;
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
