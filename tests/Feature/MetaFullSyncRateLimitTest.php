<?php

namespace Tests\Feature;

use App\Jobs\SyncMetaAdsProfile;
use App\Models\Campaign;
use App\Models\T4JamProfile;
use App\Models\User;
use App\Services\MetaAdsSyncService;
use App\Services\MetaRateLimitService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class MetaFullSyncRateLimitTest extends TestCase
{
    use RefreshDatabase;

    private function profile(): T4JamProfile
    {
        return T4JamProfile::create(['user_id' => User::factory()->create()->id, 'access_token' => 'test-token']);
    }

    public function test_full_sync_fetches_paginated_ad_sets_per_account_and_preserves_campaign_relations(): void
    {
        $profile = $this->profile();
        Http::fake([
            '*/me?*' => Http::response(['id' => 'meta-user', 'name' => 'Meta User']),
            '*/me/adaccounts?*' => Http::response(['data' => [['id' => 'act_bulk', 'account_id' => 'bulk', 'name' => 'Bulk Account', 'currency' => 'IDR']]]),
            '*/me/businesses?*' => Http::response(['data' => []]),
            '*/act_bulk/campaigns?*' => Http::response(['data' => [
                ['id' => 'cmp_a', 'name' => 'A', 'status' => 'ACTIVE', 'daily_budget' => '100000'],
                ['id' => 'cmp_b', 'name' => 'B', 'status' => 'PAUSED', 'daily_budget' => '200000'],
                ['id' => 'cmp_empty', 'name' => 'Empty', 'status' => 'ACTIVE'],
            ]]),
            '*/act_bulk/adsets?after=page2*' => Http::response(['data' => [
                ['id' => 'as_b', 'campaign_id' => 'cmp_b', 'name' => 'B set', 'status' => 'PAUSED', 'daily_budget' => '50000'],
                ['id' => 'as_unknown', 'campaign_id' => 'cmp_unknown', 'name' => 'Unknown'],
            ]]),
            '*/act_bulk/adsets?*' => Http::response([
                'data' => [['id' => 'as_a', 'campaign_id' => 'cmp_a', 'name' => 'A set', 'status' => 'ACTIVE', 'daily_budget' => '75000']],
                'paging' => ['next' => 'https://graph.facebook.com/v21.0/act_bulk/adsets?after=page2'],
            ]),
            '*/act_bulk/insights?*' => Http::response(['data' => []]),
        ]);

        $counts = app(MetaAdsSyncService::class)->sync($profile);

        $this->assertSame(1, $counts['accounts']);
        $this->assertSame(3, $counts['campaigns']);
        $this->assertSame(2, $counts['adsets']);
        foreach (['a' => 75000, 'b' => 50000] as $suffix => $budget) {
            $campaign = Campaign::where('external_id', 'cmp_'.$suffix)->firstOrFail();
            $this->assertDatabaseHas('ad_sets', ['external_id' => 'as_'.$suffix, 'campaign_id' => $campaign->id, 'daily_budget' => $budget]);
        }
        $this->assertSame(0, Campaign::where('external_id', 'cmp_empty')->firstOrFail()->adSets()->count());
        $this->assertDatabaseMissing('ad_sets', ['external_id' => 'as_unknown']);
        Http::assertSentCount(8);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/cmp_') && str_contains($request->url(), '/adsets'));
    }

    public function test_full_sync_waits_for_shared_cooldown_without_requests_or_overwriting_provider_error(): void
    {
        $this->freezeSecond();
        $profile = $this->profile();
        $profile->update(['last_meta_error' => 'Previous provider error']);
        $service = app(MetaRateLimitService::class);
        $service->record($profile, 600);
        $this->travel(100)->seconds();
        Http::fake();
        Log::spy();

        $job = (new SyncMetaAdsProfile($profile->id))->withFakeQueueInteractions();
        $job->handle(app(MetaAdsSyncService::class));

        $job->assertReleased(500)->assertNotFailed();
        Http::assertNothingSent();
        $this->assertSame('Previous provider error', $profile->fresh()->last_meta_error);
        $this->assertSame(500, $service->remainingSeconds($profile));
        Log::shouldHaveReceived('info')->withArgs(fn ($message, $context) => str_contains($message, 'full sync job deferred during cooldown')
            && $context['attempt'] === 1 && $context['retry_after_seconds'] === 500 && filled($context['job_id']))->once();
        Log::shouldNotHaveReceived('info', [\Mockery::on(fn ($message) => str_contains($message, 'full sync job started')), \Mockery::any()]);
    }

    public function test_provider_type_rate_limit_still_counts_actual_request_failures(): void
    {
        $this->freezeSecond();
        $profile = $this->profile();
        $job = new SyncMetaAdsProfile($profile->id);
        Http::fake(['*' => Http::response(['error' => ['code' => 17, 'type' => 'RateLimit']], 400,
            ['Retry-After' => 60])]);

        foreach ([60, 180] as $delay) {
            $job->withFakeQueueInteractions()->handle(app(MetaAdsSyncService::class));
            $job->assertReleased($delay)->assertNotFailed();
            $this->travel($delay)->seconds();
        }
        $job->withFakeQueueInteractions()->handle(app(MetaAdsSyncService::class));
        $job->assertFailed()->assertNotReleased();
        Http::assertSentCount(3);
    }

    public function test_provider_limit_without_retry_after_releases_job_until_adaptive_cooldown_expires(): void
    {
        $this->freezeSecond();
        $profile = $this->profile();
        Http::fake(['*' => Http::response(['error' => ['code' => 17, 'type' => 'OAuthException']], 400)]);

        $job = (new SyncMetaAdsProfile($profile->id))->withFakeQueueInteractions();
        $job->handle(app(MetaAdsSyncService::class));
        $job->assertReleased(300)->assertNotFailed();
        $this->assertSame(300, app(MetaRateLimitService::class)->remainingSeconds($profile));

        $this->travel(300)->seconds();
        $retry = (new SyncMetaAdsProfile($profile->id))->withFakeQueueInteractions();
        $retry->job->attempts = 2;
        $retry->handle(app(MetaAdsSyncService::class));
        $retry->assertReleased(600)->assertNotFailed();
        Http::assertSentCount(2);
    }

    public function test_business_usage_uses_longest_wait_across_all_entries_instead_of_first_zero(): void
    {
        $this->freezeSecond();
        $profile = $this->profile();
        Http::fake(['*' => Http::response(['error' => ['code' => 17, 'type' => 'OAuthException']], 400, [
            'X-Business-Use-Case-Usage' => json_encode([
                'account_a' => [['estimated_time_to_regain_access' => 0], ['estimated_time_to_regain_access' => 7]],
                'account_b' => [['estimated_time_to_regain_access' => 20]],
            ]),
        ])]);

        $job = (new SyncMetaAdsProfile($profile->id))->withFakeQueueInteractions();
        $job->handle(app(MetaAdsSyncService::class));
        $job->assertReleased(1200);
        $this->assertSame(1200, app(MetaRateLimitService::class)->remainingSeconds($profile));
        Http::assertSentCount(1);
    }

    public function test_rate_limit_during_account_ad_sets_stops_prefetch_without_committing_partial_data(): void
    {
        $profile = $this->profile();
        Http::fake([
            '*/me?*' => Http::response(['id' => 'meta-user']),
            '*/me/adaccounts?*' => Http::response(['data' => [['id' => 'act_bulk', 'name' => 'Bulk']]]),
            '*/me/businesses?*' => Http::response(['data' => []]),
            '*/act_bulk/campaigns?*' => Http::response(['data' => [['id' => 'cmp_a', 'name' => 'A']]]),
            '*/act_bulk/adsets?*' => Http::response(['error' => ['code' => 17, 'type' => 'OAuthException']], 400),
        ]);

        $job = (new SyncMetaAdsProfile($profile->id))->withFakeQueueInteractions();
        $job->handle(app(MetaAdsSyncService::class));

        $job->assertReleased(300)->assertNotFailed();
        $this->assertDatabaseMissing('campaigns', ['external_id' => 'cmp_a']);
        $this->assertDatabaseMissing('ad_accounts', ['external_id' => 'act_bulk']);
        Http::assertSentCount(5);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/insights'));
    }
}
