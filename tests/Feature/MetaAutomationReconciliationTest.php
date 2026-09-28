<?php

namespace Tests\Feature;

use App\Jobs\SyncMetaAdsAccount;
use App\Models\AutomationTask;
use App\Models\T4JamProfile;
use App\Models\User;
use App\Services\MetaAdsSyncService;
use App\Services\MetaRateLimitService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MetaAutomationReconciliationTest extends TestCase
{
    use RefreshDatabase;

    public function test_webhook_change_updates_the_target_locally_without_full_sync(): void
    {
        [$profile, $task] = $this->fixture();
        $this->fakeReconciliation($task, 'PAUSED', 120000, 60000, 2);

        (new SyncMetaAdsAccount($profile->id, $task->campaign->adAccount->external_id, [$task->campaign_external_id]))
            ->handle(app(MetaAdsSyncService::class));

        $this->assertFalse($task->fresh()->is_active);
        $this->assertSame('PAUSED', $task->campaign->fresh()->status);
        $this->assertSame(120000, $task->fresh()->current_budget);
        $this->assertSame(60000, $task->fresh()->current_spend);
        $this->assertNoFullSyncRequests();
    }

    public function test_reconciliation_detects_meta_status_change_without_webhook(): void
    {
        [, $task] = $this->fixture();
        $this->fakeReconciliation($task, 'PAUSED', 100000, 10000, 1);

        $this->artisan('t4jam:reconcile-meta-automation')->assertSuccessful();

        $this->assertFalse($task->fresh()->is_active);
        $this->assertSame('PAUSED', $task->campaign->fresh()->effective_status);
    }

    public function test_reconciliation_detects_meta_budget_change_without_webhook(): void
    {
        [, $task] = $this->fixture();
        $task->update(['maximum_budget' => 120000]);
        $this->fakeReconciliation($task, 'ACTIVE', 120000, 10000, 1);

        $this->artisan('t4jam:reconcile-meta-automation')->assertSuccessful();

        $this->assertSame(120000, $task->fresh()->current_budget);
        $this->assertSame(120000, $task->campaign->fresh()->daily_budget);
    }

    public function test_reconciliation_refreshes_cpr_and_pauses_when_over_cap(): void
    {
        [, $task] = $this->fixture();
        $this->fakeReconciliation($task, 'ACTIVE', 100000, 75000, 1, true);

        $this->artisan('t4jam:reconcile-meta-automation')->assertSuccessful();

        $this->assertFalse($task->fresh()->is_active);
        $this->assertSame('pause', $task->fresh()->last_budget_action);
        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_contains($request->url(), '/'.$task->campaign_external_id)
            && $request['status'] === 'PAUSED');
    }

    public function test_pending_pause_is_retried_after_cooldown_with_fresh_metrics(): void
    {
        [$profile, $task] = $this->fixture();
        $task->update(['pending_meta_action' => 'pause', 'meta_verification_due_at' => now()->subMinute()]);
        app(MetaRateLimitService::class)->clear($profile);
        $this->fakeReconciliation($task, 'ACTIVE', 100000, 75000, 1, true);

        $this->artisan('t4jam:reconcile-meta-automation')->assertSuccessful();

        $this->assertFalse($task->fresh()->is_active);
        $this->assertNull($task->fresh()->pending_meta_action);
        $this->assertSame('pause', $task->fresh()->last_budget_action);
        $this->assertStringContainsString('Campaign otomatis dipause', $task->fresh()->last_log);
        $this->assertStringNotContainsString('campaign tetap aktif', $task->fresh()->last_log);
    }

    public function test_pending_pause_is_cancelled_when_fresh_cpr_is_healthy(): void
    {
        [, $task] = $this->fixture();
        $task->update(['pending_meta_action' => 'pause', 'meta_verification_due_at' => now()->subMinute()]);
        $this->fakeReconciliation($task, 'ACTIVE', 100000, 10000, 1);

        $this->artisan('t4jam:reconcile-meta-automation')->assertSuccessful();

        $this->assertTrue($task->fresh()->is_active);
        $this->assertNull($task->fresh()->pending_meta_action);
        $this->assertSame('Pending pause dibatalkan karena CPR sudah kembali di bawah batas.', $task->fresh()->last_log);
    }

    public function test_rate_limit_613_stops_reconciliation_profile_batch(): void
    {
        [$profile, $task] = $this->fixture();
        $task->update(['current_spend' => 50000, 'current_result' => 4]);

        Http::fake([
            'graph.facebook.com/*/'.$task->campaign_external_id.'?*' => Http::response([
                'error' => ['code' => 613, 'message' => 'Application request limit reached'],
            ], 429, ['Retry-After' => '60']),
            'graph.facebook.com/*' => Http::response(['error' => ['message' => 'must not be called']], 500),
        ]);

        $this->artisan('t4jam:reconcile-meta-automation')->assertSuccessful();

        $this->assertTrue(app(MetaRateLimitService::class)->isRateLimited($profile));
        $this->assertSame(1, Http::recorded()->count());
        $this->assertSame(50000, $task->fresh()->current_spend);
        $this->assertNotNull($task->fresh()->metrics_unavailable_at);
    }

    public function test_reconciliation_uses_no_full_sync_or_account_discovery_requests(): void
    {
        [, $task] = $this->fixture();
        $this->fakeReconciliation($task, 'ACTIVE', 100000, 10000, 1);

        $this->artisan('t4jam:reconcile-meta-automation')->assertSuccessful();

        $this->assertNoFullSyncRequests();
    }

    public function test_reconciliation_calls_meta_only_for_relevant_active_task_accounts(): void
    {
        [, $task] = $this->fixture();
        $task->update(['maximum_budget' => 100000]);
        $inactive = $task->replicate();
        $inactive->id = (string) str()->uuid();
        $inactive->campaign_external_id = 'inactive-campaign';
        $inactive->campaign_name = 'Inactive campaign';
        $inactive->is_active = false;
        $inactive->pending_meta_action = null;
        $inactive->metrics_unavailable_at = null;
        $inactive->meta_verification_due_at = null;
        $inactive->save();
        $this->fakeReconciliation($task, 'ACTIVE', 100000, 10000, 1);

        $this->artisan('t4jam:reconcile-meta-automation')->assertSuccessful();

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'inactive-campaign'));
        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/'.$task->campaign_external_id.'?'));
        Http::assertSent(fn ($request) => str_contains($request->url(), '/'.$task->campaign_external_id.'/insights'));
    }

    public function test_metric_failure_keeps_stored_metrics_and_marks_task_unavailable(): void
    {
        [, $task] = $this->fixture();
        $task->update(['current_spend' => 50000, 'current_result' => 5]);
        $account = $task->campaign->adAccount;

        Http::fake([
            'graph.facebook.com/*/'.$task->campaign_external_id.'?*' => Http::response([
                'id' => $task->campaign_external_id,
                'status' => 'ACTIVE',
                'effective_status' => 'ACTIVE',
                'daily_budget' => '100000',
            ]),
            'graph.facebook.com/*/'.$task->campaign_external_id.'/insights?*' => Http::response([
                'error' => ['message' => 'temporary failure'],
            ], 500),
        ]);

        $this->artisan('t4jam:reconcile-meta-automation')->assertSuccessful();

        $fresh = $task->fresh();
        $this->assertSame(50000, $fresh->current_spend);
        $this->assertSame(5, $fresh->current_result);
        $this->assertNotNull($fresh->metrics_unavailable_at);
    }

    public function test_existing_full_sync_and_five_minute_enforcement_schedules_remain_registered(): void
    {
        $events = collect(app(Schedule::class)->events());

        $this->assertTrue($events->contains(fn ($event) => str_contains($event->command, 't4jam:sync-meta-ads') && $event->expression === '0 0-23/5 * * *'));
        $this->assertTrue($events->contains(fn ($event) => str_contains($event->command, 't4jam:enforce-automation') && $event->expression === '*/5 * * * *'));
        $this->assertTrue($events->contains(fn ($event) => str_contains($event->command, 't4jam:reconcile-meta-automation') && $event->expression === '* * * * *'));
    }

    private function fixture(): array
    {
        Cache::flush();
        $this->seed(TestDataSeeder::class);
        config(['services.meta.enable_writes' => true]);

        $user = User::firstOrFail();
        $profile = T4JamProfile::updateOrCreate(['user_id' => $user->id], ['access_token' => 'token']);
        $task = AutomationTask::with('campaign.adAccount')->where('user_id', $user->id)->firstOrFail();
        AutomationTask::whereKeyNot($task->id)->delete();
        $task->campaign->update(['status' => 'ACTIVE', 'effective_status' => 'ACTIVE', 'daily_budget' => 100000]);
        $task->update([
            'level' => 'campaign',
            'conversion' => 'purchase',
            'current_budget' => 100000,
            'cpr_cap' => 30000,
            'maximum_budget' => 200000,
            'pause_when_cpr_loss' => true,
            'is_active' => true,
            'pending_meta_action' => null,
            'metrics_unavailable_at' => null,
            'meta_verification_due_at' => null,
        ]);

        return [$profile, $task->fresh('campaign.adAccount')];
    }

    private function fakeReconciliation(AutomationTask $task, string $status, int $budget, int $spend, int $result, bool $allowPause = false): void
    {
        $account = $task->campaign->adAccount;

        Http::fake(function ($request) use ($task, $status, $budget, $spend, $result, $allowPause) {
            $url = $request->url();

            if ($allowPause && $request->method() === 'POST' && str_contains($url, '/'.$task->campaign_external_id)) {
                return Http::response(['success' => true]);
            }

            if (str_contains($url, '/'.$task->campaign_external_id.'/insights')) {
                return Http::response(['data' => [[
                    'spend' => (string) $spend,
                    'actions' => [['action_type' => 'purchase', 'value' => (string) $result]],
                ]]]);
            }

            if (str_contains($url, '/'.$task->campaign_external_id)) {
                return Http::response([
                    'id' => $task->campaign_external_id,
                    'status' => $status,
                    'effective_status' => $status,
                    'daily_budget' => (string) $budget,
                ]);
            }

            return Http::response([], 404);
        });
    }

    private function assertNoFullSyncRequests(): void
    {
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/me/adaccounts')
            || str_contains($request->url(), '/me/businesses')
            || str_contains($request->url(), '/subscribed_apps'));
    }
}
