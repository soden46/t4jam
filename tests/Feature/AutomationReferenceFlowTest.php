<?php

namespace Tests\Feature;

use App\Models\AutomationTask;
use App\Models\T4JamProfile;
use App\Models\User;
use App\Services\AutomationBudgetService;
use App\Services\MetaAdsSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AutomationReferenceFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_reference_sequence_scales_twice_in_twenty_minutes_without_reusing_results(): void
    {
        [$profile, $task] = $this->fixture();
        $this->evaluate($profile, $task, 33016, 1);
        Http::assertNothingSent();
        $this->evaluate($profile, $task, 35689, 2);
        $this->assertSame(120000, $task->fresh()->current_budget);
        $this->assertSame(2, $task->fresh()->scaled_result_count);
        $this->travel(10)->minutes();
        $this->evaluate($profile, $task, 36544, 2);
        Http::assertSentCount(1);
        $this->assertStringContainsString('Tidak ada penambahan hasil', $task->fresh()->last_log);
        $this->travel(10)->minutes();
        $this->evaluate($profile, $task, 37537, 3);
        $this->assertSame(172800, $task->fresh()->current_budget);
        Http::assertSentCount(2);
        Http::assertSent(fn ($r) => ($r->data()['daily_budget'] ?? 0) === 172800);
        $this->travel(73)->hours();
        // Replay an old snapshot after a day boundary must not scale again.
        $this->evaluate($profile, $task, 37537, 3, now()->subHours(73));
        Http::assertSentCount(2);
    }

    public function test_metrics_refresh_does_not_consume_a_new_result(): void
    {
        [$profile, $task] = $this->fixture();
        $this->evaluate($profile, $task, 20000, 2);
        $task->refresh()->update(['current_result' => 3, 'current_spend' => 30000, 'last_metrics_synced_at' => now()]);
        $this->evaluate($profile, $task, 30000, 3);
        $this->assertSame(172800, $task->fresh()->current_budget);
        Http::assertSentCount(2);
    }

    public function test_failed_or_unconfirmed_write_does_not_consume_results_and_can_retry(): void
    {
        [$profile, $task] = $this->fixture(rejectFirstBudget: true);
        $this->evaluate($profile, $task, 20000, 2);
        $this->assertNull($task->fresh()->scaled_result_count);
        $this->assertSame(75000, $task->fresh()->current_budget);
        $this->evaluate($profile, $task, 20000, 2);
        $this->assertSame(120000, $task->fresh()->current_budget);
        $this->assertSame(2, $task->fresh()->scaled_result_count);
    }

    public function test_account_local_new_day_allows_new_results_but_attribution_decreases_do_not_replay(): void
    {
        [$profile, $task] = $this->fixture();
        $task->adAccount->update(['timezone_name' => 'America/Los_Angeles']);
        $this->evaluate($profile, $task, 30000, 3);
        $this->evaluate($profile, $task, 20000, 2);
        $this->evaluate($profile, $task, 30000, 3);
        Http::assertSentCount(1);
        $this->travelTo(now('America/Los_Angeles')->addDay()->startOfDay()->addHour());
        $this->evaluate($profile, $task, 20000, 2);
        Http::assertSentCount(2);
        $this->assertSame('today:'.now('America/Los_Angeles')->toDateString(), $task->fresh()->scaling_period);
    }

    public function test_pause_threshold_is_independent_of_scaling_cap_and_takes_priority(): void
    {
        [$profile, $task] = $this->fixture();
        $task->update(['pause_cpr_limit' => 34555]);
        $this->evaluate($profile, $task, 69200, 2);
        $this->assertFalse($task->fresh()->is_active);
        Http::assertSent(fn ($r) => ($r->data()['status'] ?? null) === 'PAUSED');
        Http::assertNotSent(fn ($r) => isset($r->data()['daily_budget']));
    }

    public function test_cpr_above_scale_cap_below_pause_threshold_keeps_budget_and_status(): void
    {
        [$profile, $task] = $this->fixture();
        $task->update(['pause_cpr_limit' => 70000]);
        $this->evaluate($profile, $task, 80000, 2);
        Http::assertNothingSent();
        $this->assertTrue($task->fresh()->is_active);
        $this->assertSame(75000, $task->fresh()->current_budget);
    }

    public function test_reference_recovery_uses_cpr_cap_and_does_not_resume_into_pause_threshold(): void
    {
        [$profile, $task] = $this->fixture();
        $task->update(['pause_cpr_limit' => 34555, 'pause_cpr_cap' => 5000, 'counter_cpr' => true,
            'is_active' => false, 'last_budget_action' => 'pause', 'cpr_paused_at' => now()]);
        $task->campaign->update(['status' => 'PAUSED', 'effective_status' => 'PAUSED']);
        $this->evaluate($profile, $task, 69200, 2);
        Http::assertNothingSent();
        $this->evaluate($profile, $task, 40000, 2);
        $this->assertTrue($task->fresh()->is_active);
        Http::assertSent(fn ($r) => ($r->data()['status'] ?? null) === 'ACTIVE');
    }

    public function test_hold_modes_wait_for_spend_without_pausing_or_consuming_the_result(): void
    {
        [$profile, $task] = $this->fixture();
        $task->update(['system_flow' => 'onhold']);
        $this->evaluate($profile, $task, 30000, 3);
        Http::assertNothingSent();
        $this->assertNull($task->fresh()->scaled_result_count);
        $this->assertTrue($task->fresh()->is_active);
        $task->refresh()->update(['system_flow' => 'bypass']);
        $this->evaluate($profile, $task, 30000, 3);
        Http::assertSentCount(1);
        $this->assertSame(120000, $task->fresh()->current_budget);
    }

    public function test_hybrid_uses_manual_levels_with_maximum_floor_and_owner_checks(): void
    {
        [$profile, $task] = $this->fixture();
        $task->update(['mode' => 'hybrid', 'starting_budget' => 75000, 'maximum_budget' => 150000]);
        $this->evaluate($profile, $task, 20000, 2);
        Http::assertNothingSent();
        $this->actingAs($profile->user);
        $this->postJson('/change-hybrid-budget/', ['automation_id' => $task->id, 'direction' => 'up'])->assertOk();
        $this->assertSame(120000, $task->fresh()->current_budget);
        $this->postJson('/change-hybrid-budget/', ['automation_id' => $task->id, 'direction' => 'up'])->assertOk();
        $this->assertSame(150000, $task->fresh()->current_budget);
        $this->postJson('/change-hybrid-budget/', ['automation_id' => $task->id, 'direction' => 'down'])->assertOk();
        $this->postJson('/change-hybrid-budget/', ['automation_id' => $task->id, 'direction' => 'down'])->assertOk();
        $this->assertSame(75000, $task->fresh()->current_budget);
        $this->actingAs(User::factory()->create());
        $this->postJson('/change-hybrid-budget/', ['automation_id' => $task->id, 'direction' => 'up'])->assertNotFound();
        Http::assertSentCount(4);
    }

    public function test_new_pause_field_is_saved_without_reinterpreting_legacy_recovery(): void
    {
        [$profile, $task] = $this->fixture();
        $this->actingAs($profile->user);
        $this->postJson('/update-automation-tasks/', [
            'automation_id' => $task->id, 'starting_budget' => $task->starting_budget,
            'automation_activation' => 'active', 'budget_conversion' => 'purchase',
            'cpr_cap' => 35000, 'pause_cpr_limit' => 70000, 'counter_cpr' => true,
        ])->assertOk();
        $this->assertSame(70000, $task->fresh()->pause_cpr_limit);
        $this->assertSame(35000, $task->fresh()->recoveryCprLimit());
    }

    private function fixture(bool $rejectFirstBudget = false): array
    {
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-10-05 10:00:00', 'Asia/Jakarta'));
        $this->seed(TestDataSeeder::class);
        config(['services.meta.enable_writes' => true, 'services.meta.automation_insights_date_preset' => 'today']);
        $user = User::firstOrFail();
        $profile = T4JamProfile::updateOrCreate(['user_id' => $user->id], ['access_token' => 'test-token']);
        $task = AutomationTask::with(['campaign', 'adAccount'])->where('user_id', $user->id)->firstOrFail();
        AutomationTask::whereKeyNot($task->id)->delete();
        $task->campaign->update(['status' => 'ACTIVE', 'effective_status' => 'ACTIVE', 'daily_budget' => 75000]);
        $task->update(['conversion' => 'purchase', 'mode' => 'default', 'system_flow' => 'loss',
            'current_budget' => 75000, 'cpr_cap' => 35000, 'maximum_budget' => 0, 'is_active' => true,
            'pause_when_cpr_loss' => true, 'counter_cpr' => false, 'use_on_off' => false]);
        $profile->adAccounts()->syncWithoutDetaching([$task->ad_account_id]);
        Http::fake(['graph.facebook.com/*' => $rejectFirstBudget
            ? Http::sequence()->push(['success' => false])->push(['success' => true])
            : Http::response(['success' => true])]);

        return [$profile, $task];
    }

    private function evaluate(T4JamProfile $profile, AutomationTask $task, int $spend, int $results, $at = null): void
    {
        $task->refresh();
        $at ??= now();
        app(AutomationBudgetService::class)->pauseWebhookTasksOverCprCap(
            $profile, app(MetaAdsSyncService::class)->client($profile), $task->adAccount->external_id,
            [$task->campaign_external_id], [], ['campaign:'.$task->campaign_external_id => [
                'spend' => $spend, 'results' => [$task->conversion => $results],
                'insights_synced_at' => $at,
                'date_stop' => $at->copy()->timezone($task->adAccount->timezone_name ?: 'Asia/Jakarta')->toDateString(),
            ]],
        );
    }
}
