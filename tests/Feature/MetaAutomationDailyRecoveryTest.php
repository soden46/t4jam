<?php

namespace Tests\Feature;

use App\Models\AutomationTask;
use App\Models\T4JamProfile;
use App\Models\User;
use App\Services\AutomationBudgetService;
use App\Services\AutomationTaskMutationService;
use App\Services\MetaAdsSyncService;
use App\Services\MetaAutomationReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MetaAutomationDailyRecoveryTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('dailyRecoveryCases')]
    public function test_daily_recovery_respects_pause_origin_metrics_and_write_guards(array $changes, string $response, bool $resumes): void
    {
        [$profile, $task] = $this->fixture();
        $task->update($changes);
        $this->fakeMeta($task, $response);

        app(MetaAutomationReconciliationService::class)->reconcileProfile($profile);

        $fresh = $task->fresh();
        $this->assertSame($resumes, $fresh->is_active);
        $this->assertSame($resumes ? 'daily_resume' : ($changes['last_budget_action'] ?? 'pause'), $fresh->last_budget_action);
        $this->assertSame($resumes ? 'ACTIVE' : 'PAUSED', $task->campaign->fresh()->status);
        if ($resumes) {
            $this->assertSame(0, $fresh->current_spend);
            $this->assertSame(0, $fresh->current_result);
            $this->assertNull($fresh->cpr_paused_at);
            Http::assertSent(fn ($request) => $request->method() === 'POST' && $request['status'] === 'ACTIVE');
        } elseif ($response !== 'write_error') {
            Http::assertNotSent(fn ($request) => $request->method() === 'POST');
        }
    }

    public static function dailyRecoveryCases(): array
    {
        return [
            'zero today without counter CPR' => [[], 'zero', true],
            'empty today after yesterday spend' => [[], 'empty', true],
            'manual pause stays off' => [['last_budget_action' => 'manual_pause'], 'zero', false],
            'Meta pause stays off' => [['last_budget_action' => 'meta_sync'], 'zero', false],
            'schedule pause stays off during reconciliation' => [['last_budget_action' => 'schedule_pause'], 'zero', false],
            'same day CPR pause stays off' => [['cpr_paused_at' => '2026-10-02 00:00:00'], 'zero', false],
            'unknown pause date stays off' => [['cpr_paused_at' => null], 'zero', false],
            'outside ON window' => [['use_on_off' => true, 'on_time' => '09:00', 'off_time' => '17:00'], 'zero', false],
            'inside ON window' => [['use_on_off' => true, 'on_time' => '07:00', 'off_time' => '17:00'], 'zero', true],
            'pending mutation' => [['pending_meta_action' => 'budget'], 'zero', false],
            'today still over cap' => [[], 'over_cap', false],
            'read failed' => [[], 'read_error', false],
            'malformed response' => [[], 'malformed', false],
            'write failed' => [[], 'write_error', false],
        ];
    }

    public function test_scheduler_records_pause_time_and_resumes_once_on_next_day(): void
    {
        [$profile, $task] = $this->fixture();
        $task->update(['is_active' => true, 'last_budget_action' => null, 'cpr_paused_at' => null]);
        $task->campaign->update(['status' => 'ACTIVE', 'effective_status' => 'ACTIVE']);
        $this->fakeMeta($task, 'over_cap');
        $service = app(AutomationBudgetService::class);
        $client = app(MetaAdsSyncService::class)->client($profile);
        $service->pauseTasksOverCprCap($profile, $client, true);
        $pausedAt = $task->fresh()->cpr_paused_at;
        $this->assertNotNull($pausedAt);
        $this->assertFalse($task->fresh()->is_active);

        $this->travelTo(now()->addDay());
        // A metric check must not overwrite the actual CPR pause date or delay recovery.
        $task->update(['last_checked_at' => now(), 'period' => 1440]);
        $this->fakeMeta($task, 'empty');
        $service->pauseTasksOverCprCap($profile, $client, true);
        $service->pauseTasksOverCprCap($profile, $client, true);

        $this->assertTrue($task->fresh()->is_active);
        $this->assertSame('daily_resume', $task->fresh()->last_budget_action);
        $this->assertCount(1, Http::recorded(fn ($request) => $request->method() === 'POST' && $request['status'] === 'ACTIVE'));
        Http::assertSent(fn ($request) => str_contains($request->url(), '/insights') && $request['date_preset'] === 'today');
    }

    public function test_account_timezone_determines_the_day_of_recovery(): void
    {
        [$profile, $task] = $this->fixture();
        $task->adAccount->update(['timezone_name' => 'America/Los_Angeles']);
        $task->update(['cpr_paused_at' => '2026-10-01 16:00:00']);
        $this->fakeMeta($task, 'zero');
        $service = app(MetaAutomationReconciliationService::class);
        $service->reconcileProfile($profile);
        $this->assertFalse($task->fresh()->is_active);
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');

        $this->travelTo(Carbon::parse('2026-10-02 07:01:00', 'UTC'));
        $service->reconcileProfile($profile);
        $this->assertTrue($task->fresh()->is_active);
    }

    public function test_writes_disabled_keeps_daily_candidate_paused(): void
    {
        [$profile, $task] = $this->fixture();
        config(['services.meta.enable_writes' => false]);
        $this->fakeMeta($task, 'zero');
        app(MetaAutomationReconciliationService::class)->reconcileProfile($profile);
        $this->assertFalse($task->fresh()->is_active);
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
    }

    public function test_daily_resume_does_not_write_while_mutation_lock_is_held(): void
    {
        [$profile, $task] = $this->fixture();
        $this->fakeMeta($task, 'zero');
        $lock = app(AutomationTaskMutationService::class)->lock($task);
        $this->assertTrue($lock->get());
        try {
            app(MetaAutomationReconciliationService::class)->reconcileProfile($profile);
            $this->assertFalse($task->fresh()->is_active);
            Http::assertNotSent(fn ($request) => $request->method() === 'POST');
        } finally {
            $lock->release();
        }
    }

    public function test_daily_resume_retries_after_failed_write_with_empty_today_metrics(): void
    {
        [$profile, $task] = $this->fixture();
        $this->fakeMeta($task, 'write_error');
        $service = app(MetaAutomationReconciliationService::class);
        $service->reconcileProfile($profile);
        $this->assertFalse($task->fresh()->is_active);
        $this->assertNotNull($task->fresh()->cpr_paused_at);

        $this->travel(4)->minutes();
        $this->fakeMeta($task, 'empty');
        $service->reconcileProfile($profile);
        $this->assertTrue($task->fresh()->is_active);
    }

    public function test_non_daily_configuration_preserves_counter_cpr_behavior(): void
    {
        [$profile, $task] = $this->fixture();
        config(['services.meta.automation_insights_date_preset' => 'last_30d']);
        $this->fakeMeta($task, 'zero');
        app(MetaAutomationReconciliationService::class)->reconcileProfile($profile);
        $this->assertFalse($task->fresh()->is_active);
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
    }

    public function test_adset_daily_resume_only_activates_the_adset(): void
    {
        [$profile, $task] = $this->fixture();
        $adSet = $task->campaign->adSets()->firstOrFail();
        $adSet->update(['status' => 'PAUSED', 'effective_status' => 'PAUSED']);
        $task->update(['level' => 'adset', 'ad_set_id' => $adSet->id, 'ad_set_external_id' => $adSet->external_id]);
        $this->fakeMeta($task, 'zero');
        app(MetaAutomationReconciliationService::class)->reconcileProfile($profile);

        $this->assertTrue($task->fresh()->is_active);
        $this->assertSame('ACTIVE', $adSet->fresh()->status);
        $this->assertSame('PAUSED', $task->campaign->fresh()->status);
        Http::assertSent(fn ($request) => $request->method() === 'POST' && str_ends_with($request->url(), '/'.$adSet->external_id));
        Http::assertNotSent(fn ($request) => $request->method() === 'POST' && str_ends_with($request->url(), '/'.$task->campaign_external_id));
    }

    public function test_old_metrics_are_marked_stale_in_database_only_dashboard(): void
    {
        [, $task] = $this->fixture();
        $this->actingAs(User::firstOrFail());
        Http::fake();
        $this->getJson('/get-automation-task/')->assertOk()
            ->assertJsonPath('data.0.metrics_stale', true)
            ->assertJsonPath('data.0.metrics_available', false)
            ->assertJsonPath('meta_sync.reason', 'db_only');
        Http::assertNothingSent();
    }

    private function fixture(): array
    {
        $this->travelTo(Carbon::parse('2026-10-02 01:00:00', 'UTC'));
        Cache::flush();
        $this->seed(TestDataSeeder::class);
        config(['services.meta.enable_writes' => true, 'services.meta.automation_insights_date_preset' => 'today']);
        $user = User::firstOrFail();
        $profile = T4JamProfile::updateOrCreate(['user_id' => $user->id], ['access_token' => 'token']);
        $task = AutomationTask::with(['campaign.adAccount', 'adAccount'])->where('user_id', $user->id)->firstOrFail();
        AutomationTask::whereKeyNot($task->id)->delete();
        $task->campaign->update(['status' => 'PAUSED', 'effective_status' => 'PAUSED']);
        $task->update([
            'level' => 'campaign', 'conversion' => 'purchase', 'cpr_cap' => 30000,
            'maximum_budget' => 100000, 'pause_when_cpr_loss' => true, 'counter_cpr' => false,
            'use_on_off' => false, 'is_active' => false, 'last_budget_action' => 'pause',
            'cpr_paused_at' => now()->subDay(), 'last_checked_at' => now()->subDay(),
            'last_metrics_synced_at' => now()->subDay(), 'current_spend' => 90000, 'current_result' => 2,
            'pending_meta_action' => null, 'meta_verification_due_at' => null, 'metrics_unavailable_at' => null,
        ]);

        return [$profile, $task->fresh(['campaign.adAccount', 'adAccount'])];
    }

    private function fakeMeta(AutomationTask $task, string $response): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(function ($request) use ($task, $response) {
            if ($request->method() === 'POST') {
                return $response === 'write_error'
                    ? Http::response(['error' => ['code' => 100, 'message' => 'Invalid request']], 400)
                    : Http::response(['success' => true]);
            }
            if (str_contains($request->url(), '/insights')) {
                return match ($response) {
                    'read_error' => Http::response(['error' => ['code' => 100, 'message' => 'Invalid request']], 400),
                    'malformed' => Http::response(['unexpected' => true]),
                    'empty' => Http::response(['data' => []]),
                    default => Http::response(['data' => [[
                        'campaign_id' => $task->campaign_external_id,
                        'spend' => $response === 'over_cap' ? '90000' : '0',
                        'actions' => $response === 'over_cap' ? [['action_type' => 'purchase', 'value' => '2']] : [],
                    ]]]),
                };
            }

            return Http::response(['id' => $task->level === 'adset' ? $task->ad_set_external_id : $task->campaign_external_id, 'status' => 'PAUSED', 'effective_status' => 'PAUSED', 'daily_budget' => '100000']);
        });
    }
}
