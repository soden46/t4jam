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

    public function test_fresh_active_task_is_not_reconciled_again(): void
    {
        [, $task] = $this->fixture();
        $task->update(['last_metrics_synced_at' => now(), 'metrics_unavailable_at' => null, 'meta_verification_due_at' => null]);
        Http::fake();

        $this->artisan('t4jam:reconcile-meta-automation')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_stale_active_task_reconciles_its_target(): void
    {
        [, $task] = $this->fixture();
        $task->update(['last_metrics_synced_at' => now()->subMinutes(4), 'maximum_budget' => 100000]);
        $this->fakeReconciliation($task, 'ACTIVE', 100000, 10000, 1);

        $this->artisan('t4jam:reconcile-meta-automation')->assertSuccessful();

        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/'.$task->campaign_external_id.'?'));
        Http::assertSent(fn ($request) => str_contains($request->url(), '/'.$task->campaign_external_id.'/insights'));
    }

    public function test_future_verification_due_time_skips_reconciliation(): void
    {
        [, $task] = $this->fixture();
        $task->update(['last_metrics_synced_at' => null, 'meta_verification_due_at' => now()->addMinute()]);
        Http::fake();

        $this->artisan('t4jam:reconcile-meta-automation')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_overdue_verification_reconciles_even_when_metrics_are_fresh(): void
    {
        [, $task] = $this->fixture();
        $task->update(['last_metrics_synced_at' => now(), 'meta_verification_due_at' => now()->subSecond(), 'maximum_budget' => 100000]);
        $this->fakeReconciliation($task, 'ACTIVE', 100000, 10000, 1);

        $this->artisan('t4jam:reconcile-meta-automation')->assertSuccessful();

        Http::assertSentCount(2);
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

    public function test_reconciliation_resolves_pending_pause_when_meta_is_already_paused(): void
    {
        [, $task] = $this->fixture();
        $task->update([
            'pending_meta_action' => 'pause',
            'meta_verification_due_at' => now()->subMinute(),
            'last_log' => 'Pending pause (rate limited). CPR masih di atas batas.',
        ]);
        $this->fakeReconciliation($task, 'PAUSED', 100000, 75000, 1, true);

        $this->artisan('t4jam:reconcile-meta-automation')->assertSuccessful();

        $fresh = $task->fresh();
        $this->assertFalse($fresh->is_active);
        $this->assertSame('PAUSED', $task->campaign->fresh()->status);
        $this->assertNull($fresh->pending_meta_action);
        $this->assertNull($fresh->meta_verification_due_at);
        $this->assertSame('pause', $fresh->last_budget_action);
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
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
        $this->assertTrue($task->fresh()->meta_verification_due_at->gte(now()->addSeconds(59)));
    }

    public function test_webhook_fresh_metrics_skip_the_following_reconciliation_cycle(): void
    {
        [$profile, $task] = $this->fixture();
        $this->fakeReconciliation($task, 'ACTIVE', 100000, 10000, 1);

        (new SyncMetaAdsAccount($profile->id, $task->campaign->adAccount->external_id, [$task->campaign_external_id]))
            ->handle(app(MetaAdsSyncService::class));

        $this->assertNotNull($task->fresh()->last_metrics_synced_at);
        Http::fake();

        $this->artisan('t4jam:reconcile-meta-automation')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_manual_meta_change_uses_a_delayed_verification_without_an_immediate_duplicate_fetch(): void
    {
        [$profile, $task] = $this->fixture();
        config(['services.meta.automation_verify_delay_seconds' => 30]);
        $this->actingAs($profile->user);
        Http::fake(['graph.facebook.com/*/'.$task->campaign_external_id => Http::response(['success' => true])]);

        $this->postJson('/update-automation-tasks/', [
            'automation_id' => $task->id,
            'budget_funnel_lp' => 'lp_to_wa',
            'mode_automation' => 'default',
            'hold_spend' => 'onhold',
            'budget_conversion' => 'purchase',
            'starting_budget' => 120000,
            'maximum_budget' => 200000,
            'cpr_cap' => 30000,
            'period' => 10,
            'automation_activation' => 'active',
            'cpr_pause' => true,
        ])->assertOk();

        $this->assertTrue($task->fresh()->meta_verification_due_at->gte(now()->addSeconds(28)));
        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_contains($request->url(), '/'.$task->campaign_external_id)
            && ($request->data()['daily_budget'] ?? null) === 120000);

        Http::fake();
        $this->artisan('t4jam:reconcile-meta-automation')->assertSuccessful();
        Http::assertNothingSent();
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

    public function test_pending_pause_is_prioritized_and_target_limit_bounds_the_profile_batch(): void
    {
        [, $pending] = $this->fixture();
        config(['services.meta.automation_reconcile_max_targets' => 1]);
        $pending->update(['pending_meta_action' => 'pause', 'meta_verification_due_at' => now()->subSecond(), 'maximum_budget' => 100000]);
        $stale = $this->duplicateTask($pending, 'stale-campaign');
        $stale->update(['pending_meta_action' => null, 'meta_verification_due_at' => null, 'last_metrics_synced_at' => now()->subMinutes(4)]);

        $this->fakeMultipleReconciliation([$pending, $stale]);
        $this->artisan('t4jam:reconcile-meta-automation')->assertSuccessful();

        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/'.$pending->campaign_external_id));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/'.$stale->campaign_external_id));
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
        $this->assertSame(1, $fresh->meta_reconciliation_failure_count);
        $this->assertTrue($fresh->meta_verification_due_at->gte(now()->addSeconds(59)));
    }

    public function test_empty_insight_row_keeps_previous_metrics_stale_without_cpr_mutation(): void
    {
        [, $task] = $this->fixture();
        $syncedAt = now()->subMinutes(4)->startOfSecond();
        $task->update([
            'current_spend' => 25976,
            'current_result' => 1,
            'cpr_cap' => 25000,
            'last_metrics_synced_at' => $syncedAt,
            'last_checked_at' => $syncedAt,
        ]);

        $this->fakeEmptyInsightReconciliation($task);

        $this->artisan('t4jam:reconcile-meta-automation')->assertSuccessful();

        $fresh = $task->fresh();
        $this->assertSame(25976, $fresh->current_spend);
        $this->assertSame(1, $fresh->current_result);
        $this->assertTrue($fresh->last_metrics_synced_at->equalTo($syncedAt));
        $this->assertTrue($fresh->last_checked_at->equalTo($syncedAt));
        $this->assertNotNull($fresh->metrics_unavailable_at);
        $this->assertTrue($fresh->meta_verification_due_at->gte(now()->addSeconds(59)));
        $this->assertTrue($fresh->is_active);
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
    }

    public function test_explicit_zero_insight_row_is_synced_and_does_not_pause_active_task(): void
    {
        [, $task] = $this->fixture();
        $task->update(['current_spend' => 25976, 'current_result' => 1, 'cpr_cap' => 25000]);
        $this->fakeReconciliation($task, 'ACTIVE', 100000, 0, 0);

        $this->artisan('t4jam:reconcile-meta-automation')->assertSuccessful();

        $fresh = $task->fresh();
        $this->assertSame(0, $fresh->current_spend);
        $this->assertSame(0, $fresh->current_result);
        $this->assertNotNull($fresh->last_metrics_synced_at);
        $this->assertNull($fresh->metrics_unavailable_at);
        $this->assertTrue($fresh->is_active);
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
    }

    public function test_reconciliation_syncs_known_today_metric_without_pausing_below_cap(): void
    {
        [, $task] = $this->fixture();
        $task->update([
            'conversion' => 'initiate_checkout',
            'cpr_cap' => 25000,
            'current_spend' => 0,
            'current_result' => 0,
        ]);
        $this->fakeReconciliation($task, 'ACTIVE', 100000, 18259, 0);

        $this->artisan('t4jam:reconcile-meta-automation')->assertSuccessful();

        $fresh = $task->fresh();
        $this->assertSame(18259, $fresh->current_spend);
        $this->assertSame(0, $fresh->current_result);
        $this->assertSame(18259, $fresh->current_result > 0 ? (int) round($fresh->current_spend / $fresh->current_result) : $fresh->current_spend);
        $this->assertTrue($fresh->is_active);
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
    }

    public function test_paused_task_syncs_valid_current_day_metric(): void
    {
        [, $task] = $this->fixture();
        $task->update([
            'is_active' => false,
            'counter_cpr' => true,
            'last_budget_action' => 'pause',
            'pause_cpr_cap' => 10000,
            'current_spend' => 25976,
            'current_result' => 1,
            'last_metrics_synced_at' => now()->subMinutes(4),
            'metrics_unavailable_at' => now()->subMinute(),
        ]);
        $task->campaign->update(['status' => 'PAUSED', 'effective_status' => 'PAUSED']);
        $this->fakeReconciliation($task, 'PAUSED', 100000, 18259, 0);

        $this->artisan('t4jam:reconcile-meta-automation')->assertSuccessful();

        $this->assertSame(18259, $task->fresh()->current_spend);
        $this->assertSame(0, $task->fresh()->current_result);
        $this->assertNull($task->fresh()->metrics_unavailable_at);
    }

    public function test_paused_task_keeps_last_known_metric_when_meta_returns_no_row(): void
    {
        [, $task] = $this->fixture();
        $syncedAt = now()->subMinutes(4)->startOfSecond();
        $task->update([
            'is_active' => false,
            'current_spend' => 18259,
            'current_result' => 0,
            'last_metrics_synced_at' => $syncedAt,
            'metrics_unavailable_at' => now()->subMinute(),
            'pending_meta_action' => 'pause',
            'meta_verification_due_at' => now()->subSecond(),
        ]);
        $task->campaign->update(['status' => 'PAUSED', 'effective_status' => 'PAUSED']);
        $this->fakeEmptyInsightReconciliation($task, 'PAUSED');

        $this->artisan('t4jam:reconcile-meta-automation')->assertSuccessful();

        $fresh = $task->fresh();
        $this->assertSame(18259, $fresh->current_spend);
        $this->assertSame(0, $fresh->current_result);
        $this->assertTrue($fresh->last_metrics_synced_at->equalTo($syncedAt));
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

    private function fakeEmptyInsightReconciliation(AutomationTask $task, string $status = 'ACTIVE'): void
    {
        Http::fake(function ($request) use ($task, $status) {
            if (str_contains($request->url(), '/'.$task->campaign_external_id.'/insights')) {
                return Http::response(['data' => []]);
            }

            if (str_contains($request->url(), '/'.$task->campaign_external_id)) {
                return Http::response([
                    'id' => $task->campaign_external_id,
                    'status' => $status,
                    'effective_status' => $status,
                    'daily_budget' => '100000',
                ]);
            }

            return Http::response([], 404);
        });
    }

    private function duplicateTask(AutomationTask $task, string $campaignExternalId): AutomationTask
    {
        $campaign = $task->campaign->replicate();
        $campaign->external_id = $campaignExternalId;
        $campaign->name = 'Campaign '.$campaignExternalId;
        $campaign->save();

        $copy = $task->replicate();
        $copy->id = (string) str()->uuid();
        $copy->campaign_id = $campaign->id;
        $copy->campaign_external_id = $campaign->external_id;
        $copy->campaign_name = $campaign->name;
        $copy->save();

        return $copy->fresh('campaign.adAccount');
    }

    private function fakeMultipleReconciliation(array $tasks): void
    {
        Http::fake(function ($request) use ($tasks) {
            foreach ($tasks as $task) {
                if (str_contains($request->url(), '/'.$task->campaign_external_id.'/insights')) {
                    return Http::response(['data' => [[
                        'spend' => '10000',
                        'actions' => [['action_type' => 'purchase', 'value' => '1']],
                    ]]]);
                }

                if (str_contains($request->url(), '/'.$task->campaign_external_id)) {
                    return Http::response([
                        'id' => $task->campaign_external_id,
                        'status' => 'ACTIVE',
                        'effective_status' => 'ACTIVE',
                        'daily_budget' => '100000',
                    ]);
                }
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
