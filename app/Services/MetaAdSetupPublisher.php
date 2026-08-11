<?php

namespace App\Services;

use App\Models\AdSetup;
use App\Models\T4JamProfile;
use Illuminate\Support\Facades\DB;

class MetaAdSetupPublisher
{
    public function __construct(private readonly MetaAdsSyncService $metaSync)
    {
    }

    public function publish(AdSetup $setup, T4JamProfile $profile): AdSetup
    {
        if (! config('services.meta.enable_writes')) {
            $setup->update([
                'status' => 'ready',
                'last_error' => 'Meta write disabled. Set META_ADS_ENABLE_WRITES=true to publish.',
            ]);

            return $setup->fresh(['adAccount']);
        }

        $client = $this->metaSync->client($profile);
        $accountId = $setup->adAccount->external_id;

        return DB::transaction(function () use ($setup, $client, $accountId): AdSetup {
            $campaign = $client->createCampaign($accountId, [
                'name' => $setup->campaign_name,
                'objective' => $setup->campaign_objective,
                'status' => $setup->campaign_status,
                'buying_type' => 'AUCTION',
                'special_ad_categories' => json_encode($setup->special_ad_categories ?? []),
            ]);
            $setup->update(['meta_campaign_id' => $campaign['id'] ?? null]);

            $adset = $client->createAdSet($accountId, array_filter([
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
            ]));
            $setup->update(['meta_adset_id' => $adset['id'] ?? null]);

            $creative = $client->createAdCreative($accountId, [
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
            ]);
            $setup->update(['meta_creative_id' => $creative['id'] ?? null]);

            $ad = $client->createAd($accountId, [
                'name' => $setup->ad_name,
                'adset_id' => $setup->meta_adset_id,
                'creative' => json_encode(['creative_id' => $setup->meta_creative_id]),
                'status' => 'PAUSED',
            ]);

            $setup->update([
                'meta_ad_id' => $ad['id'] ?? null,
                'status' => 'published',
                'last_error' => null,
                'published_at' => now(),
            ]);

            return $setup->fresh(['adAccount']);
        });
    }
}
