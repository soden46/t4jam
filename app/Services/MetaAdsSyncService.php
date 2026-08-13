<?php

namespace App\Services;

use App\Exceptions\MetaAdsException;
use App\Models\AdAccount;
use App\Models\AdSet;
use App\Models\Campaign;
use App\Models\T4JamProfile;

class MetaAdsSyncService
{
    private const RATE_LIMIT_ERROR_CODES = [4, 17, 613, 80000, 80001, 80002, 80003, 80004];

    public function sync(T4JamProfile $profile): array
    {
        $client = $this->client($profile);
        $counts = ['accounts' => 0, 'campaigns' => 0, 'adsets' => 0, 'insights' => 0];

        try {
            $metaUser = $client->validateToken();
            $accounts = $client->adAccounts();
        } catch (MetaAdsException $exception) {
            $this->failSync($profile, $exception);
        }

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

            try {
                $campaigns = $client->campaigns($accountData['id']);
            } catch (MetaAdsException $exception) {
                $this->failSync($profile, $exception);
            }

            foreach ($campaigns as $campaignData) {
                $campaign = $this->upsertCampaign($account, $campaignData);
                $counts['campaigns']++;

                $this->syncCampaignInsights($profile, $client, $campaign, $counts);
                $this->syncAdSets($profile, $client, $account, $campaign, $counts);
            }
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

    private function syncCampaignInsights(T4JamProfile $profile, MetaAdsClient $client, Campaign $campaign, array &$counts): void
    {
        try {
            $insights = $client->campaignInsights($campaign->external_id);
        } catch (MetaAdsException $exception) {
            $this->failSync($profile, $exception);
        }

        if ($insights) {
            $campaign->update($this->insightPayload($insights));
            $counts['insights']++;
        }
    }

    private function syncAdSets(T4JamProfile $profile, MetaAdsClient $client, AdAccount $account, Campaign $campaign, array &$counts): void
    {
        try {
            $adSets = $client->adSets($campaign->external_id);
        } catch (MetaAdsException $exception) {
            $this->failSync($profile, $exception);
        }

        foreach ($adSets as $adSetData) {
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

            try {
                $adSetInsights = $client->adSetInsights($adSet->external_id);
            } catch (MetaAdsException $exception) {
                $this->failSync($profile, $exception);
            }

            if ($adSetInsights) {
                $adSet->update($this->insightPayload($adSetInsights));
                $counts['insights']++;
            }
        }
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

    private function failSync(T4JamProfile $profile, MetaAdsException $exception): never
    {
        $message = $this->syncErrorMessage($exception);
        $profile->update(['last_meta_error' => $message]);

        throw new MetaAdsException(
            $message,
            $exception->httpStatus,
            $exception->metaCode,
            $exception->metaType,
        );
    }

    private function syncErrorMessage(MetaAdsException $exception): string
    {
        if ($this->isRateLimitError($exception)) {
            return 'Meta rate limit tercapai. Data yang sudah terbaca tetap disimpan. Tunggu beberapa menit lalu sync lagi.';
        }

        return $exception->getMessage();
    }

    private function isRateLimitError(MetaAdsException $exception): bool
    {
        return in_array($exception->metaCode, self::RATE_LIMIT_ERROR_CODES, true);
    }
}
