<?php

namespace App\Services;

use App\Exceptions\MetaAdsException;
use App\Models\AdSet;
use App\Models\AdSetup;
use App\Models\Campaign;
use App\Models\T4JamProfile;
use App\Support\MetaFlowLog;
use Illuminate\Support\Facades\Cache;

class MetaAdSetupPublisher
{
    public function __construct(private readonly MetaAdsSyncService $metaSync) {}

    public function publish(AdSetup $setup, T4JamProfile $profile): AdSetup
    {
        if ($setup->user_id !== $profile->user_id) {
            throw new MetaAdsException('Profile Meta bukan milik pemilik setup iklan.');
        }

        return Cache::lock('publish-ad-setup:'.$setup->id, 900)->block(5, function () use ($setup, $profile): AdSetup {
            $setup->refresh();
            if ($setup->status === 'published') {
                return $setup;
            }

            return $this->publishSteps($setup, $profile);
        });
    }

    private function publishSteps(AdSetup $setup, T4JamProfile $profile): AdSetup
    {
        if (! config('services.meta.enable_writes')) {
            $setup->update([
                'status' => 'ready',
                'last_error' => null,
            ]);
            MetaFlowLog::info('ad setup marked ready without meta publish', [
                'ad_setup_id' => $setup->id,
                'profile_id' => $profile->id,
            ]);

            return $setup->fresh(['adAccount']);
        }

        $client = $this->metaSync->client($profile);
        $accountId = $setup->adAccount->external_id;
        MetaFlowLog::info('ad setup publish started', [
            'ad_setup_id' => $setup->id,
            'profile_id' => $profile->id,
            'ad_account_id' => $accountId,
        ]);

        $setup->update(['status' => 'publishing', 'last_error' => null]);
        $campaign = $this->createStep($setup, 'meta_campaign_id', fn () => $client->createCampaign($accountId, [
            'name' => $setup->campaign_name,
            'objective' => $setup->campaign_objective,
            'status' => $setup->campaign_status,
            'buying_type' => 'AUCTION',
            'special_ad_categories' => json_encode($setup->special_ad_categories ?? []),
        ]));
        $localCampaign = $this->syncLocalCampaign($setup, $campaign['id'] ?? null);
        $setup->update([
            'campaign_id' => $localCampaign?->id,
            'meta_campaign_id' => $campaign['id'] ?? null,
        ]);
        MetaFlowLog::info('ad setup meta campaign created', [
            'ad_setup_id' => $setup->id,
            'ad_account_id' => $accountId,
            'campaign_id' => $campaign['id'] ?? null,
            'local_campaign_id' => $localCampaign?->id,
        ]);

        $adset = $this->createStep($setup, 'meta_adset_id', fn () => $client->createAdSet($accountId, array_filter([
            'name' => $setup->adset_name,
            'campaign_id' => $setup->meta_campaign_id,
            'daily_budget' => $setup->daily_budget,
            'billing_event' => $setup->billing_event,
            'optimization_goal' => $setup->optimization_goal,
            'bid_strategy' => $setup->bid_strategy,
            'targeting' => json_encode($setup->targeting),
            'start_time' => $setup->start_time?->toIso8601String(),
            'end_time' => $setup->end_time?->toIso8601String(),
            'status' => 'PAUSED',
        ])));
        $localAdSet = $this->syncLocalAdSet($setup, $localCampaign, $adset['id'] ?? null);
        $setup->update(['meta_adset_id' => $adset['id'] ?? null]);
        MetaFlowLog::info('ad setup meta ad set created', [
            'ad_setup_id' => $setup->id,
            'ad_account_id' => $accountId,
            'campaign_id' => $setup->meta_campaign_id,
            'ad_set_id' => $adset['id'] ?? null,
            'local_ad_set_id' => $localAdSet?->id,
        ]);

        $creative = $this->createStep($setup, 'meta_creative_id', fn () => $client->createAdCreative($accountId, [
            'name' => $setup->creative_name,
            'object_story_spec' => json_encode(array_filter([
                'page_id' => $setup->page_id,
                'instagram_actor_id' => $setup->instagram_actor_id,
                'link_data' => [
                    'message' => $setup->message,
                    'link' => $setup->link_url,
                    'name' => $setup->headline,
                    'description' => $setup->description,
                    'call_to_action' => [
                        'type' => $setup->call_to_action,
                        'value' => ['link' => $setup->link_url],
                    ],
                ],
            ])),
        ]));
        $setup->update(['meta_creative_id' => $creative['id'] ?? null]);
        MetaFlowLog::info('ad setup meta creative created', [
            'ad_setup_id' => $setup->id,
            'ad_account_id' => $accountId,
            'creative_id' => $creative['id'] ?? null,
        ]);

        $ad = $this->createStep($setup, 'meta_ad_id', fn () => $client->createAd($accountId, [
            'name' => $setup->ad_name,
            'adset_id' => $setup->meta_adset_id,
            'creative' => json_encode(['creative_id' => $setup->meta_creative_id]),
            'status' => 'PAUSED',
        ]));

        $setup->update([
            'meta_ad_id' => $ad['id'] ?? null,
            'status' => 'published',
            'last_error' => null,
            'published_at' => now(),
        ]);
        MetaFlowLog::info('ad setup meta ad created and publish finished', [
            'ad_setup_id' => $setup->id,
            'ad_account_id' => $accountId,
            'campaign_id' => $setup->meta_campaign_id,
            'ad_set_id' => $setup->meta_adset_id,
            'creative_id' => $setup->meta_creative_id,
            'ad_id' => $setup->meta_ad_id,
        ]);

        return $setup->fresh(['adAccount']);
    }

    private function createStep(AdSetup $setup, string $field, callable $create): array
    {
        if ($setup->{$field}) {
            return ['id' => $setup->{$field}];
        }
        if ($setup->pending_meta_step) {
            throw new MetaAdsException('Hasil create Meta belum dapat dipastikan. Rekonsiliasi ID Meta sebelum publish ulang.');
        }
        $setup->update(['pending_meta_step' => $field]);
        try {
            $response = $create();
        } catch (MetaAdsException $exception) {
            if ($exception->outcomeUnknown) {
                throw new MetaAdsException('Hasil create Meta belum dapat dipastikan. Rekonsiliasi ID Meta sebelum publish ulang.');
            }
            $setup->update(['pending_meta_step' => null]);
            throw $exception;
        }
        if (empty($response['id'])) {
            throw new MetaAdsException('Meta tidak mengembalikan ID. Rekonsiliasi objek sebelum publish ulang.');
        }
        // Persist the remote ID before any dependent local or remote operation.
        $setup->update([$field => (string) $response['id'], 'pending_meta_step' => null]);

        return $response;
    }

    private function syncLocalCampaign(AdSetup $setup, ?string $externalId): ?Campaign
    {
        if (! $externalId) {
            return null;
        }

        return Campaign::updateOrCreate(
            ['external_id' => $externalId],
            [
                'ad_account_id' => $setup->ad_account_id,
                'name' => $setup->campaign_name,
                'status' => $setup->campaign_status,
                'effective_status' => $setup->campaign_status,
                'budget_type' => 'campaign',
                'level' => 'campaign',
                'objective' => $setup->campaign_objective,
                'daily_budget' => $setup->daily_budget,
            ],
        );
    }

    private function syncLocalAdSet(AdSetup $setup, ?Campaign $campaign, ?string $externalId): ?AdSet
    {
        if (! $campaign || ! $externalId) {
            return null;
        }

        return AdSet::updateOrCreate(
            ['external_id' => $externalId],
            [
                'ad_account_id' => $setup->ad_account_id,
                'campaign_id' => $campaign->id,
                'name' => $setup->adset_name,
                'status' => 'PAUSED',
                'effective_status' => 'PAUSED',
                'daily_budget' => $setup->daily_budget,
            ],
        );
    }
}
