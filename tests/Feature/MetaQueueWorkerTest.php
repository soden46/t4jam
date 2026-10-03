<?php

namespace Tests\Feature;

use App\Jobs\PushMetaAutomationTaskUpdate;
use App\Jobs\SyncMetaAdsAccount;
use App\Jobs\SyncMetaAdsProfile;
use App\Models\AutomationTask;
use App\Models\T4JamProfile;
use App\Models\User;
use App\Services\MetaRateLimitService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MetaQueueWorkerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeSecond();
        config(['queue.default' => 'database', 'cache.default' => 'database']);
    }

    private function enqueue(): T4JamProfile
    {
        $profile = T4JamProfile::create(['user_id' => User::factory()->create()->id, 'access_token' => 'test-token']);
        Queue::pushOn('meta', new SyncMetaAdsProfile($profile->id));

        return $profile;
    }

    private function work(string $queue = 'meta,default'): void
    {
        $this->artisan('queue:work', [
            'connection' => 'database', '--queue' => $queue,
            '--once' => true, '--sleep' => 0, '--tries' => 3,
        ])->assertSuccessful();
    }

    public function test_default_worker_leaves_meta_job_pending(): void
    {
        $this->enqueue();
        Http::fake();
        $this->work('default');
        $this->assertDatabaseCount('jobs', 1);
        $this->assertSame(0, DB::table('jobs')->value('attempts'));
        Http::assertNothingSent();
    }

    public function test_repeated_shared_cooldowns_do_not_exhaust_real_request_retries(): void
    {
        $profile = $this->enqueue();
        Http::fake(['*' => Http::failedConnection()]);
        $rateLimit = app(MetaRateLimitService::class);
        for ($i = 0; $i < 4; $i++) {
            $rateLimit->record($profile, 300);
            $this->work();
            $this->assertDatabaseCount('jobs', 1);
            $this->assertDatabaseCount('failed_jobs', 0);
            $this->travel(300)->seconds();
        }
        Http::assertNothingSent();

        foreach ([60, 180] as $delay) {
            $this->work();
            $this->assertDatabaseCount('jobs', 1);
            $this->travel($delay)->seconds();
        }
        $this->work();
        Http::assertSentCount(3);
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('failed_jobs', 1);
    }

    public function test_three_actual_provider_failures_exhaust_retries_across_serialized_releases(): void
    {
        $this->enqueue();
        Http::fake(['*' => Http::failedConnection()]);
        foreach ([60, 180] as $delay) {
            $this->work();
            $this->assertDatabaseCount('jobs', 1);
            $this->assertSame(now()->addSeconds($delay)->getTimestamp(), DB::table('jobs')->value('available_at'));
            $this->travel($delay)->seconds();
        }
        $this->work();
        Http::assertSentCount(3);
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('failed_jobs', 1);
    }

    public function test_expired_retry_window_fails_before_any_meta_request(): void
    {
        $this->enqueue();
        Http::fake();
        $this->travel(4)->hours();
        $this->travel(1)->seconds();
        $this->work();
        Http::assertNothingSent();
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('failed_jobs', 1);
    }

    public static function retryDelays(): array
    {
        return ['immediate' => [0], 'expired' => [18000]];
    }

    #[DataProvider('retryDelays')]
    public function test_explicit_retry_resets_window_and_provider_failure_count(int $wait): void
    {
        $this->enqueue();
        Http::fake(['*' => Http::failedConnection()]);
        foreach ([60, 180] as $delay) {
            $this->work();
            $this->travel($delay)->seconds();
        }
        $this->work();
        $uuid = DB::table('failed_jobs')->value('uuid');
        $this->assertNotNull($uuid);
        $this->travel($wait)->seconds();

        $this->artisan('queue:retry', ['id' => [$uuid]])->assertSuccessful();
        $this->work();
        Http::assertSentCount(4);
        $this->assertDatabaseCount('jobs', 1);
        $this->assertDatabaseCount('failed_jobs', 0);
        $this->assertSame(now()->addSeconds(60)->getTimestamp(), DB::table('jobs')->value('available_at'));
    }

    public function test_queued_pause_survives_repeated_lock_contention_and_updates_only_after_meta_success(): void
    {
        $this->seed(TestDataSeeder::class);
        $task = AutomationTask::with('campaign')->firstOrFail();
        $profile = T4JamProfile::where('user_id', $task->user_id)->firstOrFail();
        $profile->update(['access_token' => 'test-token']);
        $task->update(['is_active' => false]);
        $task->campaign->update(['status' => 'ACTIVE', 'effective_status' => 'ACTIVE']);
        config(['services.meta.enable_writes' => true]);
        Queue::pushOn('meta', new PushMetaAutomationTaskUpdate($profile->id, $task->id, 'status', 'Pause', active: false));
        Http::fake(['*' => Http::response(['success' => true])]);
        $lock = Cache::lock('automation-task-mutation:'.$task->id, 120);
        $this->assertTrue($lock->get());
        try {
            for ($i = 0; $i < 4; $i++) {
                $this->work();
                $this->assertDatabaseCount('jobs', 1);
                $this->assertDatabaseCount('failed_jobs', 0);
                $this->assertSame('ACTIVE', $task->campaign->fresh()->status);
                $this->travel(5)->seconds();
            }
            Http::assertNothingSent();
        } finally {
            $lock->release();
        }
        $this->work();
        $this->assertDatabaseCount('jobs', 0);
        $this->assertSame('PAUSED', $task->campaign->fresh()->status);
        Http::assertSentCount(1);
    }

    public function test_queue_inspection_reports_jobs_without_exposing_payload_or_processing_them(): void
    {
        $this->enqueue();
        DB::table('jobs')->insert([
            'queue' => 'meta', 'payload' => json_encode([
                'displayName' => 'App\\Jobs\\PublishMetaAdSetup',
                'data' => ['command' => 'private-token-do-not-print'],
            ]),
            'attempts' => 0, 'reserved_at' => null,
            'available_at' => now()->getTimestamp(), 'created_at' => now()->getTimestamp(),
        ]);
        Http::fake();

        $this->assertSame(0, Artisan::call('t4jam:queue-status'));
        $output = Artisan::output();
        $status = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('default', $status['queue_name']);
        $this->assertSame('meta,default', $status['required_worker_queues']);
        $this->assertTrue($status['timing_valid']);
        $this->assertSame(2, $status['queues'][0]['total']);
        $this->assertSame('App\\Jobs\\PublishMetaAdSetup', $status['jobs'][1]['type']);
        $this->assertStringNotContainsString('private-token', $output);
        $this->assertStringNotContainsString('test-token', $output);
        $this->assertDatabaseCount('jobs', 2);
        $this->assertSame(0, DB::table('jobs')->sum('attempts'));
        Http::assertNothingSent();
    }

    public function test_queue_inspection_rejects_retry_after_at_or_below_worker_timeout(): void
    {
        config(['queue.connections.database.retry_after' => 700]);
        $this->artisan('t4jam:queue-status')->assertFailed();
    }

    public function test_after_response_cooldown_retry_is_persisted_for_worker(): void
    {
        $profile = T4JamProfile::create(['user_id' => User::factory()->create()->id,
            'access_token' => 'test-token']);
        app(MetaRateLimitService::class)->record($profile, 300);
        Http::fake();

        SyncMetaAdsAccount::dispatchAfterResponse($profile->id, 'act_test');
        app()->terminate();

        $this->assertDatabaseCount('jobs', 1);
        $this->assertSame('meta', DB::table('jobs')->value('queue'));
        $this->assertSame(now()->addSeconds(300)->getTimestamp(), DB::table('jobs')->value('available_at'));
        Http::assertNothingSent();
    }

    public function test_after_response_task_lock_retry_is_persisted_for_worker(): void
    {
        $this->seed(TestDataSeeder::class);
        $task = AutomationTask::firstOrFail();
        $profile = T4JamProfile::where('user_id', $task->user_id)->firstOrFail();
        config(['services.meta.enable_writes' => true]);
        Http::fake();
        $lock = Cache::lock('automation-task-mutation:'.$task->id, 120);
        $this->assertTrue($lock->get());
        try {
            PushMetaAutomationTaskUpdate::dispatchAfterResponse($profile->id, $task->id, 'status', 'Pause', active: false);
            app()->terminate();

            $this->assertDatabaseCount('jobs', 1);
            $this->assertSame('meta', DB::table('jobs')->value('queue'));
            $this->assertSame(now()->addSeconds(5)->getTimestamp(), DB::table('jobs')->value('available_at'));
            Http::assertNothingSent();
        } finally {
            $lock->release();
        }
    }
}
