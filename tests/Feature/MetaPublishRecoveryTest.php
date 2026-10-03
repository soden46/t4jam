<?php

namespace Tests\Feature;

use App\Exceptions\MetaAdsException;
use App\Jobs\PublishMetaAdSetup;
use App\Models\AdAccount;
use App\Models\AdSetup;
use App\Models\T4JamProfile;
use App\Models\User;
use App\Services\MetaAdSetupPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MetaPublishRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $this->seed(TestDataSeeder::class);
        $profile = T4JamProfile::where('user_id', User::firstOrFail()->id)->firstOrFail();
        $profile->update(['access_token' => 'test-token']);
        config(['services.meta.enable_writes' => true]);
        $setup = AdSetup::create([
            'user_id' => $profile->user_id, 'ad_account_id' => AdAccount::firstOrFail()->id,
            'name' => 'Recovery', 'status' => 'draft', 'campaign_name' => 'Campaign',
            'campaign_objective' => 'OUTCOME_SALES', 'campaign_status' => 'PAUSED',
            'adset_name' => 'Adset', 'daily_budget' => 100000, 'billing_event' => 'IMPRESSIONS',
            'optimization_goal' => 'OFFSITE_CONVERSIONS', 'bid_strategy' => 'LOWEST_COST_WITHOUT_CAP',
            'targeting' => ['geo_locations' => ['countries' => ['ID']]],
            'ad_name' => 'Ad', 'creative_name' => 'Creative', 'page_id' => '123',
            'message' => 'Message', 'headline' => 'Headline', 'link_url' => 'https://example.com',
            'call_to_action' => 'LEARN_MORE',
        ]);

        return [$setup, $profile];
    }

    public static function steps(): array
    {
        return [['adsets', 'meta_campaign_id', 5], ['adcreatives', 'meta_adset_id', 5], ['ads', 'meta_creative_id', 5]];
    }

    #[DataProvider('steps')]
    public function test_publish_retry_preserves_successful_ids(string $failedEdge, string $savedField, int $requests): void
    {
        $this->freezeSecond();
        [$setup, $profile] = $this->fixture();
        $attempts = [];
        Http::fake(function ($request) use (&$attempts, $failedEdge) {
            $edge = basename(parse_url($request->url(), PHP_URL_PATH));
            $attempts[$edge] = ($attempts[$edge] ?? 0) + 1;
            if ($edge === $failedEdge && $attempts[$edge] === 1) {
                return Http::response(['error' => ['code' => 4, 'message' => 'Rate limit']], 429, ['Retry-After' => 600]);
            }

            return Http::response(['id' => 'remote_'.$edge]);
        });
        $publisher = app(MetaAdSetupPublisher::class);
        $job = (new PublishMetaAdSetup($setup->id, $profile->id))->withFakeQueueInteractions();
        $job->handle($publisher);
        $job->assertReleased(600);
        $this->assertNotNull($setup->fresh()->{$savedField});
        $this->travel(600)->seconds();
        $retry = (new PublishMetaAdSetup($setup->id, $profile->id))->withFakeQueueInteractions();
        $retry->handle($publisher);
        $retry->assertNotFailed()->assertNotReleased();
        $this->assertSame('published', $setup->fresh()->status);
        $this->assertSame(1, $attempts['campaigns']);
        $this->assertSame(2, $attempts[$failedEdge]);
        $publisher->publish($setup->fresh(), $profile);
        Http::assertSentCount($requests);
    }

    public function test_unknown_create_outcome_is_not_blindly_retried(): void
    {
        [$setup, $profile] = $this->fixture();
        Http::fake(['*' => Http::failedConnection()]);
        $publisher = app(MetaAdSetupPublisher::class);
        $job = (new PublishMetaAdSetup($setup->id, $profile->id))->withFakeQueueInteractions();
        $job->handle($publisher);
        $job->assertFailed()->assertNotReleased();
        $this->assertSame('meta_campaign_id', $setup->fresh()->pending_meta_step);
        Http::fake();
        try {
            $publisher->publish($setup->fresh(), $profile);
            $this->fail('An uncertain create must require reconciliation.');
        } catch (MetaAdsException $exception) {
            $this->assertStringContainsString('Rekonsiliasi', $exception->getMessage());
        }
        Http::assertNothingSent();
    }

    public function test_publisher_rejects_mismatched_profile_before_http(): void
    {
        [$setup] = $this->fixture();
        $other = T4JamProfile::create(['user_id' => User::factory()->create()->id, 'access_token' => 'other-token']);
        Http::fake();
        $job = (new PublishMetaAdSetup($setup->id, $other->id))->withFakeQueueInteractions();
        $job->handle(app(MetaAdSetupPublisher::class));
        Http::assertNothingSent();
        $this->assertSame('draft', $setup->fresh()->status);
    }

    public function test_publish_request_persists_job_without_http_even_after_response_termination(): void
    {
        [$setup, $profile] = $this->fixture();
        config(['queue.default' => 'database']);
        Http::fake();

        $this->actingAs(User::findOrFail($profile->user_id))
            ->postJson(route('ad-setups.publish', $setup))->assertOk()
            ->assertJsonPath('setup.status', 'publishing');

        $this->assertDatabaseCount('jobs', 1);
        $this->assertSame('meta', DB::table('jobs')->value('queue'));
        $this->assertSame('publishing', $setup->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_worker_expiration_marks_publish_failed_and_preserves_unknown_create_marker(): void
    {
        $this->freezeSecond();
        [$setup, $profile] = $this->fixture();
        config(['queue.default' => 'database']);
        $setup->update(['status' => 'publishing', 'meta_campaign_id' => 'saved-campaign',
            'pending_meta_step' => 'meta_adset_id']);
        PublishMetaAdSetup::dispatch($setup->id, $profile->id);
        $this->travel(4)->hours();
        $this->travel(1)->seconds();
        Http::fake();

        $this->artisan('queue:work', ['connection' => 'database', '--queue' => 'meta',
            '--once' => true, '--sleep' => 0])->assertSuccessful();

        $this->assertDatabaseCount('failed_jobs', 1);
        $this->assertSame('failed', $setup->fresh()->status);
        $this->assertSame('saved-campaign', $setup->fresh()->meta_campaign_id);
        $this->assertSame('meta_adset_id', $setup->fresh()->pending_meta_step);
        $this->assertStringContainsString('Rekonsiliasi', $setup->fresh()->last_error);
        Http::assertNothingSent();
    }
}
