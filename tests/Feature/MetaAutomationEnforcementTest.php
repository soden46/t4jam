<?php

namespace Tests\Feature;

use App\Jobs\PushMetaAutomationTaskUpdate;
use App\Jobs\SyncMetaAdsAccount;
use App\Models\AutomationTask;
use App\Models\Campaign;
use App\Models\T4JamProfile;
use App\Models\User;
use App\Services\AutomationBudgetService;
use App\Services\MetaAdsSyncService;
use App\Services\MetaRateLimitService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class MetaAutomationEnforcementTest extends TestCase
{
    use RefreshDatabase;

    public function test_cpr_equal_to_cap_pauses_campaign(): void
    {
        [, $task] = $this->automationFixture();

        $this->fakeEnforcementInsights($task, 30000, 1);

        $this->artisan('t4jam:enforce-automation')->assertSuccessful();

        $this->assertFalse($task->fresh()->is_active);
        $this->assertSame('PAUSED', $task->campaign->fresh()->status);
        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_contains($request->url(), $task->campaign_external_id)
            && $request['status'] === 'PAUSED');
    }

    public function test_scheduler_falls_back_to_direct_campaign_insights_and_pauses_over_cap(): void
    {
        config(['services.meta.automation_insights_date_preset' => 'last_30d']);
        [$profile, $task] = $this->automationFixture();
        $task->update([
            'current_spend' => 75919,
            'current_result' => 1,
            'cpr_cap' => 25000,
            'last_checked_at' => now()->subMinutes(11),
        ]);
        $account = $task->campaign->adAccount;

        Http::fake([
            'graph.facebook.com/*/'.$account->external_id.'/insights?*' => Http::response([
                'data' => [[
                    'campaign_id' => 'campaign_not_requested',
                    'spend' => '1000',
                    'actions' => [],
                ]],
            ]),
            'graph.facebook.com/*/'.$task->campaign_external_id.'/insights?*' => Http::response([
                'data' => [[
                    'spend' => '75919',
                    'actions' => [['action_type' => 'purchase', 'value' => '1']],
                ]],
            ]),
            'graph.facebook.com/*/'.$task->campaign_external_id => Http::response(['success' => true]),
        ]);

        $this->artisan('t4jam:enforce-automation')->assertSuccessful();

        $fresh = $task->fresh();
        $this->assertSame(75919, $fresh->current_spend);
        $this->assertSame(1, $fresh->current_result);
        $this->assertFalse($fresh->is_active);
        $this->assertSame('pause', $fresh->last_budget_action);
        $this->assertSame('PAUSED', $task->campaign->fresh()->status);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/'.$account->external_id.'/insights')
            && $request['date_preset'] === 'last_30d');
        Http::assertSent(fn ($request) => str_contains($request->url(), '/'.$task->campaign_external_id.'/insights')
            && $request['date_preset'] === 'last_30d');
        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_contains($request->url(), $task->campaign_external_id)
            && $request['status'] === 'PAUSED');
    }

    public function test_spend_above_starting_budget_with_healthy_cpr_stays_active_and_increases_budget(): void
    {
        [, $task] = $this->automationFixture();
        $task->campaign->update(['daily_budget' => 50000]);
        $task->update([
            'starting_budget' => 50000,
            'current_budget' => 50000,
            'maximum_budget' => 100000,
            'cpr_cap' => 25000,
            'last_checked_at' => now()->subMinutes(11),
        ]);

        $this->fakeEnforcementInsights($task, 100000, 10);

        $this->artisan('t4jam:enforce-automation')->assertSuccessful();

        $fresh = $task->fresh();
        $this->assertTrue($fresh->is_active);
        $this->assertSame('ACTIVE', $task->campaign->fresh()->status);
        $this->assertSame(58000, $fresh->current_budget);
        $this->assertSame(58000, $task->campaign->fresh()->daily_budget);
        $this->assertSame('increase', $fresh->last_budget_action);
        $this->assertStringContainsString('CPR Rp. 10.000 masih di bawah atau sama dengan 80% batas CPR Rp. 25.000', $fresh->last_log);
        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_contains($request->url(), $task->campaign_external_id)
            && (int) $request['daily_budget'] === 58000);
        Http::assertNotSent(fn ($request) => $request->method() === 'POST'
            && ($request->data()['status'] ?? null) === 'PAUSED');
    }

    public function test_high_spend_pauses_for_cpr_not_for_budget(): void
    {
        [, $task] = $this->automationFixture();
        $task->campaign->update(['daily_budget' => 50000]);
        $task->update([
            'starting_budget' => 50000,
            'current_budget' => 50000,
            'cpr_cap' => 25000,
            'last_checked_at' => now()->subMinutes(11),
        ]);

        $this->fakeEnforcementInsights($task, 100000, 2);

        $this->artisan('t4jam:enforce-automation')->assertSuccessful();

        $fresh = $task->fresh();
        $this->assertFalse($fresh->is_active);
        $this->assertSame('PAUSED', $task->campaign->fresh()->status);
        $this->assertSame('pause', $fresh->last_budget_action);
        $this->assertSame('Campaign otomatis dipause karena CPR Rp. 50.000 mencapai batas Rp. 25.000.', $fresh->last_log);
    }

    public function test_maximum_budget_stops_increase_without_pausing_a_healthy_campaign(): void
    {
        [, $task] = $this->automationFixture();
        $task->campaign->update(['daily_budget' => 100000]);
        $task->update([
            'current_budget' => 100000,
            'maximum_budget' => 100000,
            'cpr_cap' => 25000,
            'last_checked_at' => now()->subMinutes(11),
        ]);

        $this->fakeEnforcementInsights($task, 200000, 20, includeStatusPost: false);

        $this->artisan('t4jam:enforce-automation')->assertSuccessful();

        $fresh = $task->fresh();
        $this->assertTrue($fresh->is_active);
        $this->assertSame('ACTIVE', $task->campaign->fresh()->status);
        $this->assertSame(100000, $fresh->current_budget);
        $this->assertSame(100000, $task->campaign->fresh()->daily_budget);
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
    }

    public function test_cpr_below_cap_but_above_scale_margin_keeps_campaign_active_without_increase(): void
    {
        [, $task] = $this->automationFixture();
        $task->campaign->update(['daily_budget' => 50000]);
        $task->update([
            'current_budget' => 50000,
            'maximum_budget' => 100000,
            'cpr_cap' => 25000,
            'last_budget_changed_at' => null,
            'last_checked_at' => now()->subMinutes(11),
        ]);

        $this->fakeEnforcementInsights($task, 66000, 3, includeStatusPost: false);

        $this->artisan('t4jam:enforce-automation')->assertSuccessful();

        $this->assertTrue($task->fresh()->is_active);
        $this->assertSame(50000, $task->fresh()->current_budget);
        $this->assertSame('ACTIVE', $task->campaign->fresh()->status);
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
    }

    public function test_healthy_cpr_with_too_few_results_does_not_increase_budget(): void
    {
        [, $task] = $this->automationFixture();
        $task->campaign->update(['daily_budget' => 50000]);
        $task->update([
            'current_budget' => 50000,
            'maximum_budget' => 100000,
            'cpr_cap' => 25000,
            'last_budget_changed_at' => null,
            'last_checked_at' => now()->subMinutes(11),
        ]);

        $this->fakeEnforcementInsights($task, 30000, 2, includeStatusPost: false);

        $this->artisan('t4jam:enforce-automation')->assertSuccessful();

        $this->assertTrue($task->fresh()->is_active);
        $this->assertSame(50000, $task->fresh()->current_budget);
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
    }

    public function test_healthy_cpr_with_minimum_results_increases_budget_once(): void
    {
        [, $task] = $this->automationFixture();
        $task->campaign->update(['daily_budget' => 50000]);
        $task->update([
            'current_budget' => 50000,
            'maximum_budget' => 100000,
            'cpr_cap' => 25000,
            'last_budget_changed_at' => null,
            'last_checked_at' => now()->subMinutes(11),
        ]);

        $this->fakeEnforcementInsights($task, 45000, 3);

        $this->artisan('t4jam:enforce-automation')->assertSuccessful();

        $fresh = $task->fresh();
        $this->assertTrue($fresh->is_active);
        $this->assertSame(58000, $fresh->current_budget);
        $this->assertSame(58000, $task->campaign->fresh()->daily_budget);
        $this->assertSame('increase', $fresh->last_budget_action);
    }

    public function test_budget_increase_cooldown_keeps_healthy_campaign_active(): void
    {
        [, $task] = $this->automationFixture();
        $changedAt = now()->subHour()->startOfSecond();
        $task->campaign->update(['daily_budget' => 50000]);
        $task->update([
            'current_budget' => 50000,
            'maximum_budget' => 100000,
            'cpr_cap' => 25000,
            'last_budget_changed_at' => $changedAt,
            'last_budget_action' => 'increase',
            'last_checked_at' => now()->subMinutes(11),
        ]);

        $this->fakeEnforcementInsights($task, 45000, 3, includeStatusPost: false);

        $this->artisan('t4jam:enforce-automation')->assertSuccessful();

        $fresh = $task->fresh();
        $this->assertTrue($fresh->is_active);
        $this->assertSame(50000, $fresh->current_budget);
        $this->assertTrue($fresh->last_budget_changed_at->equalTo($changedAt));
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
    }

    public function test_budget_increase_cooldown_allows_scale_after_72_hours(): void
    {
        [, $task] = $this->automationFixture();
        $task->campaign->update(['daily_budget' => 50000]);
        $task->update([
            'current_budget' => 50000,
            'maximum_budget' => 100000,
            'cpr_cap' => 25000,
            'last_budget_changed_at' => now()->subHours(73),
            'last_checked_at' => now()->subMinutes(11),
        ]);

        $this->fakeEnforcementInsights($task, 45000, 3);

        $this->artisan('t4jam:enforce-automation')->assertSuccessful();

        $this->assertSame(58000, $task->fresh()->current_budget);
        $this->assertSame('ACTIVE', $task->campaign->fresh()->status);
    }

    public function test_budget_increase_is_capped_at_maximum_budget_without_pausing(): void
    {
        [, $task] = $this->automationFixture();
        $task->campaign->update(['daily_budget' => 95000]);
        $task->update([
            'current_budget' => 95000,
            'maximum_budget' => 100000,
            'cpr_cap' => 25000,
            'last_budget_changed_at' => null,
            'last_checked_at' => now()->subMinutes(11),
        ]);

        $this->fakeEnforcementInsights($task, 45000, 3);

        $this->artisan('t4jam:enforce-automation')->assertSuccessful();

        $this->assertTrue($task->fresh()->is_active);
        $this->assertSame(100000, $task->fresh()->current_budget);
        $this->assertSame(100000, $task->campaign->fresh()->daily_budget);
    }

    public function test_failed_budget_update_does_not_change_local_budget_or_cooldown(): void
    {
        [, $task] = $this->automationFixture();
        $changedAt = now()->subHours(73)->startOfSecond();
        $task->campaign->update(['daily_budget' => 50000]);
        $task->update([
            'current_budget' => 50000,
            'maximum_budget' => 100000,
            'cpr_cap' => 25000,
            'last_budget_changed_at' => $changedAt,
            'last_budget_action' => 'manual_budget_decrease',
            'last_checked_at' => now()->subMinutes(11),
        ]);

        Http::fake([
            'graph.facebook.com/*/'.$task->campaign->adAccount->external_id.'/insights?*' => Http::response([
                'data' => [[
                    'campaign_id' => $task->campaign_external_id,
                    'spend' => '45000',
                    'actions' => [['action_type' => 'purchase', 'value' => '3']],
                ]],
            ]),
            'graph.facebook.com/*/'.$task->campaign_external_id => Http::response([
                'error' => ['code' => 100, 'message' => 'Invalid budget'],
            ], 400),
        ]);

        $this->artisan('t4jam:enforce-automation')->assertSuccessful();

        $fresh = $task->fresh();
        $this->assertSame(50000, $fresh->current_budget);
        $this->assertSame(50000, $task->campaign->fresh()->daily_budget);
        $this->assertTrue($fresh->last_budget_changed_at->equalTo($changedAt));
        $this->assertSame('manual_budget_decrease', $fresh->last_budget_action);
    }

    public function test_budget_increase_lock_skips_duplicate_concurrent_trigger(): void
    {
        [$profile, $task] = $this->automationFixture();
        $task->campaign->update(['daily_budget' => 50000]);
        $task->update([
            'current_budget' => 50000,
            'maximum_budget' => 100000,
            'cpr_cap' => 25000,
            'last_budget_changed_at' => null,
            'last_checked_at' => now()->subMinutes(11),
        ]);

        $this->fakeEnforcementInsights($task, 45000, 3, includeStatusPost: false);

        Cache::lock('automation-task-mutation:'.$task->id, 120)->get(function () use ($profile): void {
            app(AutomationBudgetService::class)->pauseTasksOverCprCap(
                $profile,
                app(MetaAdsSyncService::class)->client($profile),
                true,
            );
        });

        $this->assertTrue($task->fresh()->is_active);
        $this->assertSame(50000, $task->fresh()->current_budget);
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
    }

    public function test_cpr_over_cap_pauses_even_when_spend_is_below_starting_budget(): void
    {
        [, $task] = $this->automationFixture();
        $task->campaign->update(['daily_budget' => 50000]);
        $task->update([
            'starting_budget' => 50000,
            'current_budget' => 50000,
            'cpr_cap' => 25000,
            'last_checked_at' => now()->subMinutes(11),
        ]);

        $this->fakeEnforcementInsights($task, 30000, 1);

        $this->artisan('t4jam:enforce-automation')->assertSuccessful();

        $fresh = $task->fresh();
        $this->assertFalse($fresh->is_active);
        $this->assertSame('PAUSED', $task->campaign->fresh()->status);
        $this->assertSame('Campaign otomatis dipause karena CPR Rp. 30.000 mencapai batas Rp. 25.000.', $fresh->last_log);
    }

    public function test_manual_activation_immediately_pauses_when_fresh_cpr_is_over_cap(): void
    {
        [, $task] = $this->automationFixture();
        $this->prepareManualActivation($task);
        $this->fakeManualActivationInsights($task, 185172, 3);

        $response = $this->activateTask($task)
            ->assertOk()
            ->assertJsonPath('data.automation_status', 'pause')
            ->assertJsonPath('data.meta_status', 'PAUSED')
            ->assertJsonPath('data.current_spend', 185172)
            ->assertJsonPath('data.current_hasil', 3)
            ->assertJsonPath('data.current_cpr', 61724);

        $fresh = $task->fresh();
        $this->assertFalse($fresh->is_active);
        $this->assertSame('pause', $fresh->last_budget_action);
        $this->assertSame('Campaign otomatis dipause karena CPR Rp. 61.724 mencapai batas Rp. 25.000.', $fresh->last_log);
        $this->assertSame($fresh->last_log, $response->json('text'));
        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_contains($request->url(), $task->campaign_external_id)
            && $request['status'] === 'ACTIVE');
        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_contains($request->url(), $task->campaign_external_id)
            && $request['status'] === 'PAUSED');
    }

    public function test_manual_activation_stays_active_when_fresh_cpr_is_below_cap(): void
    {
        [, $task] = $this->automationFixture();
        $this->prepareManualActivation($task);
        $this->fakeManualActivationInsights($task, 20000, 1);

        $this->activateTask($task)
            ->assertOk()
            ->assertJsonPath('data.automation_status', 'active')
            ->assertJsonPath('data.meta_status', 'ACTIVE')
            ->assertJsonPath('data.current_cpr', 20000);

        $this->assertTrue($task->fresh()->is_active);
        $this->assertSame('manual_resume', $task->fresh()->last_budget_action);
        Http::assertNotSent(fn ($request) => $request->method() === 'POST'
            && ($request->data()['status'] ?? null) === 'PAUSED');
    }

    public function test_manual_activation_stays_active_with_high_spend_when_cpr_is_healthy(): void
    {
        [, $task] = $this->automationFixture();
        $this->prepareManualActivation($task);
        $this->fakeManualActivationInsights($task, 500000, 50);

        $this->activateTask($task)
            ->assertOk()
            ->assertJsonPath('data.automation_status', 'active')
            ->assertJsonPath('data.meta_status', 'ACTIVE')
            ->assertJsonPath('data.current_spend', 500000)
            ->assertJsonPath('data.current_hasil', 50)
            ->assertJsonPath('data.current_cpr', 10000);

        $this->assertTrue($task->fresh()->is_active);
        $this->assertSame('ACTIVE', $task->campaign->fresh()->status);
        Http::assertNotSent(fn ($request) => $request->method() === 'POST'
            && ($request->data()['status'] ?? null) === 'PAUSED');
    }

    public function test_manual_activation_keeps_active_when_fresh_metrics_are_unavailable(): void
    {
        [, $task] = $this->automationFixture();
        $this->prepareManualActivation($task);
        $task->update([
            'current_spend' => 185172,
            'current_result' => 3,
            'last_metrics_synced_at' => now()->subHour(),
        ]);

        Http::fake([
            'graph.facebook.com/*/'.$task->campaign->adAccount->external_id.'/insights?*' => Http::response([
                'data' => [['campaign_id' => 'campaign_not_requested', 'spend' => '1000', 'actions' => []]],
            ]),
            'graph.facebook.com/*/'.$task->campaign_external_id.'/insights?*' => Http::response([
                'error' => ['code' => 17, 'message' => 'Rate limit'],
            ], 500),
            'graph.facebook.com/*/'.$task->campaign_external_id => Http::response(['success' => true]),
        ]);

        $this->activateTask($task)
            ->assertOk()
            ->assertJsonPath('data.automation_status', 'active')
            ->assertJsonPath('data.meta_status', 'ACTIVE')
            ->assertJsonPath('data.metrics_available', false)
            ->assertJsonPath('data.metrics_stale', true)
            ->assertJsonPath('data.current_spend', 185172)
            ->assertJsonPath('data.current_hasil', 3)
            ->assertJsonPath('data.current_cpr', 61724)
            ->assertJsonPath('text', 'Status automation berhasil diperbarui; Meta berhasil diupdate. Verifikasi CPR belum dapat diselesaikan karena metrik Meta tidak tersedia; campaign tetap aktif dan akan diperiksa scheduler.');

        $fresh = $task->fresh();
        $this->assertTrue($fresh->is_active);
        $this->assertNotNull($fresh->metrics_unavailable_at);
        $this->assertStringContainsString('Verifikasi CPR belum dapat diselesaikan', $fresh->last_log);
        Http::assertNotSent(fn ($request) => $request->method() === 'POST'
            && ($request->data()['status'] ?? null) === 'PAUSED');
    }

    public function test_manual_activation_only_evaluates_the_selected_task(): void
    {
        [, $task] = $this->automationFixture();
        $this->prepareManualActivation($task);
        $otherCampaign = Campaign::create([
            'ad_account_id' => $task->campaign->ad_account_id,
            'external_id' => 'campaign_not_selected',
            'name' => 'Campaign Not Selected',
            'status' => 'PAUSED',
            'effective_status' => 'PAUSED',
            'daily_budget' => 100000,
        ]);
        $otherTask = $task->replicate();
        $otherTask->id = (string) Str::uuid();
        $otherTask->campaign_id = $otherCampaign->id;
        $otherTask->campaign_external_id = $otherCampaign->external_id;
        $otherTask->campaign_name = $otherCampaign->name;
        $otherTask->is_active = false;
        $otherTask->last_budget_action = 'manual_pause';
        $otherTask->last_checked_at = now()->subHour();
        $otherTask->save();

        $this->fakeManualActivationInsights($task, 20000, 1);

        $this->activateTask($task)->assertOk()->assertJsonPath('data.id', $task->id);

        $this->assertTrue($task->fresh()->is_active);
        $this->assertFalse($otherTask->fresh()->is_active);
        $this->assertSame('manual_pause', $otherTask->fresh()->last_budget_action);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), $otherCampaign->external_id));
    }

    public function test_writes_disabled_logs_skip_without_meta_status_write(): void
    {
        [, $task] = $this->automationFixture(writesEnabled: false);

        $this->fakeEnforcementInsights($task, 75000, 1, includeStatusPost: false);

        $this->artisan('t4jam:enforce-automation')->assertSuccessful();

        $fresh = $task->fresh();
        $this->assertTrue($fresh->is_active);
        $this->assertSame('ACTIVE', $task->campaign->fresh()->status);
        $this->assertStringContainsString('META_ADS_ENABLE_WRITES=false', $fresh->last_log);
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
    }

    public function test_display_metric_refresh_does_not_advance_last_checked_at(): void
    {
        Cache::flush();
        config(['services.meta.automation_insights_date_preset' => 'last_7d']);
        [$profile, $task] = $this->automationFixture();
        $checkedAt = now()->subMinutes(3)->startOfSecond();
        $task->update(['last_checked_at' => $checkedAt, 'last_metrics_synced_at' => null]);

        Http::fake([
            'graph.facebook.com/*/'.$task->campaign->adAccount->external_id.'/insights?*' => Http::response([
                'data' => [[
                    'campaign_id' => $task->campaign_external_id,
                    'spend' => '60000',
                    'actions' => [['action_type' => 'purchase', 'value' => '2']],
                ]],
            ]),
        ]);

        $updated = app(AutomationBudgetService::class)->refreshTaskMetricsForDisplay(
            $profile,
            app(MetaAdsSyncService::class)->client($profile),
            collect([$task])
        );

        $fresh = $task->fresh();
        $this->assertSame(1, $updated);
        $this->assertTrue($fresh->last_checked_at->equalTo($checkedAt));
        $this->assertNotNull($fresh->last_metrics_synced_at);
        $this->assertSame($profile->id, T4JamProfile::where('user_id', User::firstOrFail()->id)->value('id'));
        Http::assertSent(fn ($request) => str_contains($request->url(), '/'.$task->campaign->adAccount->external_id.'/insights')
            && $request['date_preset'] === 'last_7d');
    }

    public function test_display_and_scheduler_use_the_same_automation_insights_date_preset(): void
    {
        Cache::flush();
        config([
            'services.meta.enable_writes' => false,
            'services.meta.insights_date_preset' => 'last_30d',
            'services.meta.automation_insights_date_preset' => 'last_7d',
        ]);
        [$profile, $task] = $this->automationFixture(writesEnabled: false);
        $task->update(['last_checked_at' => now()->subMinutes(11)]);
        $account = $task->campaign->adAccount;

        Http::fake([
            'graph.facebook.com/*/'.$account->external_id.'/insights?*' => Http::response([
                'data' => [[
                    'campaign_id' => $task->campaign_external_id,
                    'spend' => '1000',
                    'actions' => [['action_type' => 'purchase', 'value' => '1']],
                ]],
            ]),
        ]);

        app(AutomationBudgetService::class)->refreshTaskMetricsForDisplay(
            $profile,
            app(MetaAdsSyncService::class)->client($profile),
            collect([$task]),
        );
        $this->artisan('t4jam:enforce-automation')->assertSuccessful();

        $insightRequests = Http::recorded(fn ($request) => str_contains($request->url(), '/'.$account->external_id.'/insights'));

        $this->assertCount(2, $insightRequests);
        $this->assertTrue($insightRequests->every(
            fn (array $record) => $record[0]['date_preset'] === 'last_7d',
        ));
    }

    public function test_relevant_campaign_webhook_sync_enforces_cpr_pause_immediately(): void
    {
        config([
            'queue.default' => 'database',
            'services.meta.webhook_sync_mode' => 'after_response',
            'services.meta.automation_insights_date_preset' => 'last_7d',
        ]);
        [$profile, $task] = $this->automationFixture();
        $task->update(['last_checked_at' => now()]);
        $this->fakeWebhookAccountSync($task, webhookSpend: 75000, webhookResult: 1);

        $body = $this->signedWebhookBody($profile, $task->campaign->adAccount->external_id, [
            ['field' => 'campaigns', 'value' => ['id' => $task->campaign_external_id]],
        ]);

        $this->call('POST', '/meta/webhook/', [], [], [], $body['server'], $body['content'])
            ->assertOk()
            ->assertJsonPath('queued', 1);

        $this->assertFalse($task->fresh()->is_active);
        $this->assertSame('PAUSED', $task->campaign->fresh()->status);
        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_contains($request->url(), $task->campaign_external_id)
            && $request['status'] === 'PAUSED');
        Http::assertSent(fn ($request) => str_contains($request->url(), '/'.$task->campaign_external_id.'/insights'));
    }

    public function test_unrelated_campaign_webhook_does_not_pause_other_task(): void
    {
        [$profile, $task] = $this->automationFixture();
        $unrelated = Campaign::create([
            'ad_account_id' => $task->campaign->ad_account_id,
            'external_id' => 'cmp_unrelated',
            'name' => 'Unrelated Campaign',
            'status' => 'ACTIVE',
            'effective_status' => 'ACTIVE',
            'daily_budget' => 100000,
        ]);
        $this->fakeWebhookAccountSync($task, webhookSpend: 75000, webhookResult: 1, extraCampaign: $unrelated);

        $body = $this->signedWebhookBody($profile, $task->campaign->adAccount->external_id, [
            ['field' => 'campaigns', 'value' => ['id' => $unrelated->external_id]],
        ]);

        $this->call('POST', '/meta/webhook/', [], [], [], $body['server'], $body['content'])
            ->assertOk()
            ->assertJsonPath('queued', 1);

        $this->assertTrue($task->fresh()->is_active);
        $this->assertSame('ACTIVE', $task->campaign->fresh()->status);
        Http::assertNotSent(fn ($request) => $request->method() === 'POST'
            && str_contains($request->url(), $task->campaign_external_id));
    }

    public function test_adset_level_task_pauses_adset_not_campaign(): void
    {
        [, $task] = $this->automationFixture(level: 'adset');
        $adSet = $task->adSet;

        Http::fake([
            'graph.facebook.com/*/'.$adSet->adAccount->external_id.'/insights?*' => Http::response([
                'data' => [[
                    'adset_id' => $adSet->external_id,
                    'spend' => '75000',
                    'actions' => [['action_type' => 'purchase', 'value' => '1']],
                ]],
            ]),
            'graph.facebook.com/*/'.$adSet->external_id => Http::response(['success' => true]),
        ]);

        $this->artisan('t4jam:enforce-automation')->assertSuccessful();

        $this->assertFalse($task->fresh()->is_active);
        $this->assertSame('PAUSED', $adSet->fresh()->status);
        $this->assertSame('ACTIVE', $task->campaign->fresh()->status);
        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_contains($request->url(), $adSet->external_id)
            && $request['status'] === 'PAUSED');
        Http::assertNotSent(fn ($request) => $request->method() === 'POST'
            && str_ends_with($request->url(), '/'.$task->campaign_external_id));
    }

    public function test_adset_webhook_payload_does_not_target_parent_campaign_task(): void
    {
        Queue::fake();
        config(['services.meta.webhook_sync_mode' => 'queue']);
        [$profile, $task] = $this->automationFixture(level: 'adset');
        $adSet = $task->adSet;

        $body = $this->signedWebhookBody($profile, $task->campaign->adAccount->external_id, [
            ['field' => 'adsets', 'value' => [
                'id' => $adSet->external_id,
                'campaign_id' => $task->campaign_external_id,
            ]],
        ]);

        $this->call('POST', '/meta/webhook/', [], [], [], $body['server'], $body['content'])
            ->assertOk()
            ->assertJsonPath('queued', 1);

        Queue::assertPushed(SyncMetaAdsAccount::class, fn (SyncMetaAdsAccount $job) => $job->campaignIds === []
            && $job->adSetIds === [$adSet->external_id]);
    }

    public function test_duplicate_webhook_enforcement_does_not_repeat_pause_write_after_local_pause(): void
    {
        [$profile, $task] = $this->automationFixture();
        $client = app(MetaAdsSyncService::class)->client($profile);
        $metrics = app(AutomationBudgetService::class)->metricSnapshot([
            'spend' => '75000',
            'actions' => [['action_type' => 'purchase', 'value' => '1']],
        ]);

        Http::fake(['graph.facebook.com/*/'.$task->campaign_external_id => Http::response(['success' => true])]);

        app(AutomationBudgetService::class)->pauseWebhookTasksOverCprCap(
            $profile,
            $client,
            $task->campaign->adAccount->external_id,
            [$task->campaign_external_id],
            [],
            ['campaign:'.$task->campaign_external_id => $metrics],
        );
        app(AutomationBudgetService::class)->pauseWebhookTasksOverCprCap(
            $profile,
            $client,
            $task->campaign->adAccount->external_id,
            [$task->campaign_external_id],
            [],
            ['campaign:'.$task->campaign_external_id => $metrics],
        );

        Http::assertSentCount(1);
        $this->assertFalse($task->fresh()->is_active);
    }

    public function test_failed_meta_pause_does_not_mark_local_target_paused(): void
    {
        [, $task] = $this->automationFixture();

        Http::fake([
            'graph.facebook.com/*/'.$task->campaign->adAccount->external_id.'/insights?*' => Http::response([
                'data' => [[
                    'campaign_id' => $task->campaign_external_id,
                    'spend' => '75000',
                    'actions' => [['action_type' => 'purchase', 'value' => '1']],
                ]],
            ]),
            'graph.facebook.com/*/'.$task->campaign_external_id => Http::response([
                'error' => ['message' => 'Permission denied', 'code' => 200],
            ], 403),
        ]);

        $this->artisan('t4jam:enforce-automation')->assertSuccessful();

        $this->assertTrue($task->fresh()->is_active);
        $this->assertSame('ACTIVE', $task->campaign->fresh()->status);
        $this->assertNotSame('pause', $task->fresh()->last_budget_action);
    }

    public function test_insight_unavailable_does_not_pause_based_on_stale_metrics(): void
    {
        [, $task] = $this->automationFixture();
        $syncedAt = now()->subHour()->startOfSecond();
        $checkedAt = now()->subMinutes(11)->startOfSecond();
        $task->update([
            'current_spend' => 75000,
            'current_result' => 1,
            'last_metrics_synced_at' => $syncedAt,
            'metrics_unavailable_at' => null,
            'last_checked_at' => $checkedAt,
        ]);

        Http::fake([
            'graph.facebook.com/*/'.$task->campaign->adAccount->external_id.'/insights?*' => Http::response([
                'data' => [['campaign_id' => 'campaign_not_requested', 'spend' => '1000', 'actions' => []]],
            ]),
            'graph.facebook.com/*/'.$task->campaign_external_id.'/insights?*' => Http::response([
                'error' => ['code' => 17, 'message' => 'Rate limit'],
            ], 500),
        ]);

        $this->artisan('t4jam:enforce-automation')->assertSuccessful();

        $fresh = $task->fresh();
        $this->assertTrue($fresh->is_active);
        $this->assertSame('ACTIVE', $task->campaign->fresh()->status);
        $this->assertTrue($fresh->last_metrics_synced_at->equalTo($syncedAt));
        $this->assertTrue($fresh->last_checked_at->equalTo($checkedAt));
        $this->assertNotNull($fresh->metrics_unavailable_at);
        $this->assertSame(75000, $fresh->current_spend);
        $this->assertSame(1, $fresh->current_result);
        $this->actingAs(User::firstOrFail());
        $row = collect($this
            ->getJson('/get-automation-task/?acc=all&level=all&funnel=all&local=1')
            ->assertOk()
            ->json('data'))
            ->firstWhere('id', $task->id);
        $this->assertTrue($row['metrics_stale']);
        $this->assertFalse($row['metrics_available']);
        $this->assertNotNull($row['metrics_synced_at']);
        Http::assertNotSent(fn ($request) => $request->method() === 'POST'
            && str_contains($request->url(), $task->campaign_external_id));
    }

    public function test_already_paused_meta_target_does_not_issue_duplicate_pause(): void
    {
        [, $task] = $this->automationFixture();
        $task->campaign->update(['status' => 'PAUSED', 'effective_status' => 'PAUSED']);

        Http::fake([
            'graph.facebook.com/*/'.$task->campaign->adAccount->external_id.'/insights?*' => Http::response([
                'data' => [[
                    'campaign_id' => $task->campaign_external_id,
                    'spend' => '75000',
                    'actions' => [['action_type' => 'purchase', 'value' => '1']],
                ]],
            ]),
            'graph.facebook.com/*/'.$task->campaign->adAccount->external_id.'/campaigns?*' => Http::response([
                'data' => [[
                    'id' => $task->campaign_external_id,
                    'name' => $task->campaign->name,
                    'status' => 'PAUSED',
                    'effective_status' => 'PAUSED',
                    'daily_budget' => '100000',
                ]],
            ]),
        ]);

        $this->artisan('t4jam:enforce-automation')->assertSuccessful();

        $this->assertFalse($task->fresh()->is_active);
        $this->assertSame('PAUSED', $task->campaign->fresh()->status);
        $this->assertSame('meta_sync', $task->fresh()->last_budget_action);
        Http::assertNotSent(fn ($request) => $request->method() === 'POST'
            && str_contains($request->url(), $task->campaign_external_id));
    }

    public function test_schedule_pause_ignores_period_and_resumes_within_window(): void
    {
        Cache::flush();
        config(['services.meta.enable_writes' => true]);
        [$profile, $task] = $this->automationFixture();

        $task->update([
            'use_on_off' => true,
            'on_time' => '00:00',
            'off_time' => '23:59',
            'period' => 10,
            'is_active' => false,
            'last_budget_action' => 'schedule_pause',
            'last_checked_at' => now()->subMinutes(4),
            'counter_cpr' => false,
        ]);

        Http::fake([
            'graph.facebook.com/*/'.$task->campaign->adAccount->external_id.'/insights?*' => Http::response([
                'data' => [[
                    'campaign_id' => $task->campaign_external_id,
                    'spend' => '1000',
                    'actions' => [['action_type' => 'purchase', 'value' => '1']],
                ]],
            ]),
            'graph.facebook.com/*/'.$task->campaign_external_id => Http::response(['success' => true]),
        ]);

        $this->artisan('t4jam:enforce-automation')->assertSuccessful();

        $fresh = $task->fresh();
        $this->assertTrue($fresh->is_active);
        $this->assertSame('ACTIVE', $fresh->campaign->fresh()->status);
        $this->assertSame('schedule_resume', $fresh->last_budget_action);
    }

    public function test_manual_pause_resumes_at_on_time_ignoring_period(): void
    {
        Cache::flush();
        config(['services.meta.enable_writes' => true]);
        [$profile, $task] = $this->automationFixture();

        $task->update([
            'use_on_off' => true,
            'on_time' => '00:00',
            'off_time' => '23:59',
            'period' => 10,
            'is_active' => false,
            'last_budget_action' => 'manual_pause',
            'last_checked_at' => now()->subMinutes(4),
            'counter_cpr' => false,
        ]);

        Http::fake([
            'graph.facebook.com/*/'.$task->campaign->adAccount->external_id.'/insights?*' => Http::response([
                'data' => [[
                    'campaign_id' => $task->campaign_external_id,
                    'spend' => '1000',
                    'actions' => [['action_type' => 'purchase', 'value' => '1']],
                ]],
            ]),
            'graph.facebook.com/*/'.$task->campaign_external_id => Http::response(['success' => true]),
        ]);

        $this->artisan('t4jam:enforce-automation')->assertSuccessful();

        $fresh = $task->fresh();
        $this->assertTrue($fresh->is_active);
        $this->assertSame('ACTIVE', $fresh->campaign->fresh()->status);
        $this->assertSame('schedule_resume', $fresh->last_budget_action);
    }

    public function test_inactive_manual_pause_keeps_action_after_manual_budget_decrease(): void
    {
        [, $task] = $this->automationFixture();
        $task->update([
            'is_active' => false,
            'last_budget_action' => 'manual_pause',
        ]);

        $this->postManualBudgetDecrease($task)->assertOk();

        $this->assertSame('manual_pause', $task->fresh()->last_budget_action);
    }

    public function test_inactive_schedule_pause_keeps_action_after_manual_budget_decrease(): void
    {
        [, $task] = $this->automationFixture();
        $task->update([
            'is_active' => false,
            'last_budget_action' => 'schedule_pause',
        ]);

        $this->postManualBudgetDecrease($task)->assertOk();

        $this->assertSame('schedule_pause', $task->fresh()->last_budget_action);
    }

    public function test_inactive_cpr_pause_keeps_action_after_manual_budget_decrease(): void
    {
        [, $task] = $this->automationFixture();
        $task->update([
            'is_active' => false,
            'last_budget_action' => 'pause',
        ]);

        $this->postManualBudgetDecrease($task)->assertOk();

        $this->assertSame('pause', $task->fresh()->last_budget_action);
    }

    public function test_manual_budget_noop_preserves_action_when_target_already_has_requested_budget(): void
    {
        [, $task] = $this->automationFixture();
        $task->update([
            'is_active' => true,
            'last_budget_action' => 'manual',
        ]);

        $this->postManualBudgetDecrease($task)->assertOk();

        $this->assertSame('manual', $task->fresh()->last_budget_action);
    }

    public function test_schedule_pause_ignores_period_when_outside_window(): void
    {
        Cache::flush();
        config(['services.meta.enable_writes' => true]);
        [$profile, $task] = $this->automationFixture();

        $task->update([
            'use_on_off' => true,
            'on_time' => '23:00',
            'off_time' => '23:01',
            'period' => 10,
            'is_active' => true,
            'last_budget_action' => null,
            'last_checked_at' => now()->subMinutes(4),
            'counter_cpr' => false,
        ]);

        Http::fake([
            'graph.facebook.com/*/'.$task->campaign->adAccount->external_id.'/insights?*' => Http::response([
                'data' => [[
                    'campaign_id' => $task->campaign_external_id,
                    'spend' => '1000',
                    'actions' => [['action_type' => 'purchase', 'value' => '1']],
                ]],
            ]),
            'graph.facebook.com/*/'.$task->campaign_external_id => Http::response(['success' => true]),
        ]);

        $this->artisan('t4jam:enforce-automation')->assertSuccessful();

        $fresh = $task->fresh();
        $this->assertFalse($fresh->is_active);
        $this->assertSame('PAUSED', $fresh->campaign->fresh()->status);
        $this->assertSame('schedule_pause', $fresh->last_budget_action);
    }

    public function test_cpr_evaluation_respects_period(): void
    {
        Cache::flush();
        config(['services.meta.enable_writes' => true]);
        [$profile, $task] = $this->automationFixture();

        $task->update([
            'use_on_off' => false,
            'period' => 10,
            'is_active' => true,
            'last_checked_at' => now()->subMinutes(4),
            'last_budget_action' => null,
        ]);

        app(AutomationBudgetService::class)->pauseTasksOverCprCap($profile, app(MetaAdsSyncService::class)->client($profile), true);

        $fresh = $task->fresh();
        $this->assertTrue($fresh->is_active);
        $this->assertSame(now()->subMinutes(4)->startOfSecond()->format('Y-m-d H:i:s'), $fresh->last_checked_at->format('Y-m-d H:i:s'));
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
    }

    public function test_cpr_paused_task_not_resumed_by_schedule(): void
    {
        Cache::flush();
        config(['services.meta.enable_writes' => true]);
        [$profile, $task] = $this->automationFixture();

        $task->update([
            'use_on_off' => true,
            'on_time' => '00:00',
            'off_time' => '23:59',
            'period' => 10,
            'is_active' => false,
            'last_budget_action' => 'pause',
            'counter_cpr' => true,
            'last_checked_at' => now()->subMinutes(15),
            'pause_cpr_cap' => 50000,
        ]);

        $task->campaign->update(['status' => 'PAUSED', 'effective_status' => 'PAUSED']);

        Http::fake([
            'graph.facebook.com/*/'.$task->campaign->adAccount->external_id.'/insights?*' => Http::response([
                'data' => [[
                    'campaign_id' => $task->campaign_external_id,
                    'spend' => '1000',
                    'actions' => [['action_type' => 'purchase', 'value' => '1']],
                ]],
            ]),
        ]);

        $this->artisan('t4jam:enforce-automation')->assertSuccessful();

        $fresh = $task->fresh();
        $this->assertFalse($fresh->is_active);
        $this->assertSame('pause', $fresh->last_budget_action);
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
    }

    public function test_initial_list_load_does_not_call_meta(): void
    {
        [$profile, $task] = $this->automationFixture();
        $task->update([
            'current_spend' => 31980,
            'current_result' => 1,
            'current_budget' => 75000,
            'last_metrics_synced_at' => now()->subMinutes(10),
            'metrics_unavailable_at' => null,
        ]);

        Http::fake([
            'graph.facebook.com/*' => Http::response(['error' => ['message' => 'should not be called']]),
        ]);

        $response = $this->actingAs(User::firstOrFail())
            ->getJson('/get-automation-task/?acc=all&level=all&funnel=all&page=1&per_page=10');

        $response->assertOk()
            ->assertJsonPath('meta_sync.reason', 'db_only')
            ->assertJsonPath('meta_sync.attempted', false);

        $row = collect($response->json('data'))->firstWhere('id', $task->id);
        $this->assertNotNull($row);
        $this->assertSame(31980, $row['current_spend']);
        $this->assertSame(1, $row['current_hasil']);
        $this->assertSame(100000, $row['current_budget']);
        $this->assertTrue($row['metrics_available']);
        $this->assertFalse($row['metrics_stale']);

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'graph.facebook.com'));
    }

    public function test_pagination_does_not_refresh_all_tasks(): void
    {
        [$profile, $task] = $this->automationFixture();
        $task->update([
            'current_spend' => 10000,
            'current_result' => 1,
            'current_budget' => 50000,
            'last_metrics_synced_at' => now()->subMinutes(10),
            'metrics_unavailable_at' => null,
        ]);

        Http::fake([
            'graph.facebook.com/*' => Http::response(['error' => ['message' => 'should not be called']]),
        ]);

        $response = $this->actingAs(User::firstOrFail())
            ->getJson('/get-automation-task/?acc=all&level=all&funnel=all&page=1&per_page=10');

        $response->assertOk();
        $this->assertSame(10, $response->json('pagination.per_page'));
        $this->assertNotNull(collect($response->json('data'))->firstWhere('id', $task->id));

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'graph.facebook.com'));
    }

    public function test_manual_status_mutation_skips_when_task_lock_is_held(): void
    {
        [, $task] = $this->automationFixture();
        $this->prepareManualActivation($task);
        Http::fake(['*' => Http::response(['success' => true])]);

        Cache::lock('automation-task-mutation:'.$task->id, 120)->get(function () use ($task): void {
            $this->activateTask($task)->assertStatus(409);
        });

        $this->assertFalse($task->fresh()->is_active);
        $this->assertSame('PAUSED', $task->campaign->fresh()->status);
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
    }

    public function test_manual_budget_mutation_skips_when_task_lock_is_held(): void
    {
        [, $task] = $this->automationFixture();
        $task->campaign->update(['daily_budget' => 100000]);
        $task->update(['starting_budget' => 50000, 'current_budget' => 100000]);
        Http::fake(['*' => Http::response(['success' => true])]);

        Cache::lock('automation-task-mutation:'.$task->id, 120)->get(function () use ($task): void {
            $this->postManualBudgetDecrease($task)->assertStatus(409);
        });

        $this->assertSame(100000, $task->campaign->fresh()->daily_budget);
        $this->assertSame(100000, $task->fresh()->current_budget);
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
    }

    public function test_manual_resume_preserves_pending_pause_safety_state(): void
    {
        [, $task] = $this->automationFixture();
        $this->prepareManualActivation($task);
        $task->update([
            'pending_meta_action' => 'pause',
            'last_log' => 'Pending pause (rate limited). CPR masih di atas batas.',
        ]);
        Http::fake(['*' => Http::response(['success' => true])]);

        $this->activateTask($task)->assertStatus(422);

        $fresh = $task->fresh();
        $this->assertFalse($fresh->is_active);
        $this->assertSame('pause', $fresh->pending_meta_action);
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
    }

    public function test_duplicate_manual_status_requests_do_not_post_to_meta(): void
    {
        [, $task] = $this->automationFixture();
        Http::fake(['*' => Http::response(['success' => true])]);
        $this->actingAs(User::firstOrFail())->postJson('/update-status-automation-tasks/', [
            'automation_id' => $task->id,
            'status' => 'true',
        ])->assertOk();

        $task->campaign->update(['status' => 'PAUSED', 'effective_status' => 'PAUSED']);
        $task->update(['is_active' => false]);
        $this->actingAs(User::firstOrFail())->postJson('/update-status-automation-tasks/', [
            'automation_id' => $task->id,
            'status' => 'false',
        ])->assertOk();

        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
    }

    public function test_duplicate_manual_budget_request_does_not_post_to_meta(): void
    {
        [, $task] = $this->automationFixture();
        $task->campaign->update(['daily_budget' => 100000]);
        $task->update(['starting_budget' => 100000, 'current_budget' => 100000]);
        Http::fake(['*' => Http::response(['success' => true])]);

        $this->postManualBudgetDecrease($task)->assertOk()->assertJsonPath('text', 'Budget Meta sudah sesuai.');

        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
    }

    public function test_stale_queued_budget_mutation_cannot_overwrite_newer_budget(): void
    {
        [$profile, $task] = $this->automationFixture();
        $task->campaign->update(['daily_budget' => 80000]);
        $task->update(['starting_budget' => 80000, 'current_budget' => 80000]);
        Http::fake(['*' => Http::response(['success' => true])]);

        (new PushMetaAutomationTaskUpdate($profile->id, $task->id, 'budget', 'Update', budget: 60000))
            ->handle(app(MetaAdsSyncService::class));

        $this->assertSame(80000, $task->campaign->fresh()->daily_budget);
        $this->assertSame(80000, $task->fresh()->current_budget);
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
    }

    private function automationFixture(bool $writesEnabled = true, string $level = 'campaign'): array
    {
        Cache::flush();
        $this->seed(TestDataSeeder::class);
        config(['services.meta.enable_writes' => $writesEnabled]);

        $user = User::firstOrFail();
        $profile = T4JamProfile::updateOrCreate(['user_id' => $user->id], [
            'access_token' => 'token',
            'app_secret' => 'webhook-secret',
        ]);
        $task = AutomationTask::with(['campaign.adAccount', 'campaign.adSets'])->where('user_id', $user->id)->firstOrFail();
        AutomationTask::whereKeyNot($task->id)->delete();
        $adSet = $task->campaign->adSets()->first();

        $task->campaign->update(['status' => 'ACTIVE', 'effective_status' => 'ACTIVE', 'daily_budget' => 100000]);
        $task->update([
            'level' => $level,
            'ad_set_id' => $level === 'adset' ? $adSet?->id : null,
            'ad_set_external_id' => $level === 'adset' ? $adSet?->external_id : null,
            'conversion' => 'purchase',
            'cpr_cap' => 30000,
            'maximum_budget' => 100000,
            'pause_when_cpr_loss' => true,
            'counter_cpr' => false,
            'is_active' => true,
            'last_checked_at' => now()->subMinutes(11),
            'last_budget_action' => null,
        ]);
        $profile->adAccounts()->syncWithoutDetaching([$task->campaign->adAccount->id]);

        return [$profile, $task->fresh(['campaign.adAccount', 'adSet'])];
    }

    private function postManualBudgetDecrease(AutomationTask $task)
    {
        $this->actingAs(User::firstOrFail());

        Http::fake([
            'graph.facebook.com/*/'.$task->campaign_external_id => Http::response(['success' => true]),
        ]);

        return $this->postJson('/turun-budget-manual/', [
            'automation_id' => $task->id,
        ]);
    }

    private function prepareManualActivation(AutomationTask $task): void
    {
        $task->campaign->update(['status' => 'PAUSED', 'effective_status' => 'PAUSED', 'daily_budget' => 100000]);
        $task->update([
            'is_active' => false,
            'last_budget_action' => 'manual_pause',
            'cpr_cap' => 25000,
            'pause_when_cpr_loss' => true,
            'maximum_budget' => 100000,
            'last_checked_at' => now(),
        ]);
    }

    private function activateTask(AutomationTask $task)
    {
        $this->actingAs(User::firstOrFail());

        return $this->postJson('/update-status-automation-tasks/', [
            'automation_id' => $task->id,
            'status' => 'true',
        ]);
    }

    private function fakeManualActivationInsights(AutomationTask $task, int $spend, int $result): void
    {
        Http::fake([
            'graph.facebook.com/*/'.$task->campaign->adAccount->external_id.'/insights?*' => Http::response([
                'data' => [[
                    'campaign_id' => $task->campaign_external_id,
                    'spend' => (string) $spend,
                    'actions' => [['action_type' => 'purchase', 'value' => (string) $result]],
                ]],
            ]),
            'graph.facebook.com/*/'.$task->campaign_external_id => Http::response(['success' => true]),
        ]);
    }

    private function fakeEnforcementInsights(AutomationTask $task, int $spend, int $result, bool $includeStatusPost = true): void
    {
        $fakes = [
            'graph.facebook.com/*/'.$task->campaign->adAccount->external_id.'/insights?*' => Http::response([
                'data' => [[
                    'campaign_id' => $task->campaign_external_id,
                    'spend' => (string) $spend,
                    'actions' => [['action_type' => 'purchase', 'value' => (string) $result]],
                ]],
            ]),
        ];

        if ($includeStatusPost) {
            $fakes['graph.facebook.com/*/'.$task->campaign_external_id] = Http::response(['success' => true]);
        }

        Http::fake($fakes);
    }

    private function fakeWebhookAccountSync(
        AutomationTask $task,
        int $webhookSpend,
        int $webhookResult,
        ?Campaign $extraCampaign = null,
    ): void {
        $targetId = $task->level === 'adset' ? $task->ad_set_external_id : $task->campaign_external_id;
        $target = [
            'id' => $targetId,
            'name' => $task->campaign->name,
            'status' => 'ACTIVE',
            'effective_status' => 'ACTIVE',
            'daily_budget' => '100000',
        ];

        Http::fake(function ($request) use ($targetId, $target, $webhookSpend, $webhookResult) {
            $url = $request->url();

            if ($request->method() === 'POST' && str_contains($url, '/'.$targetId)) {
                return Http::response(['success' => true]);
            }

            if (str_contains($url, '/'.$targetId.'/insights')) {
                return Http::response(['data' => [[
                    'spend' => (string) $webhookSpend,
                    'actions' => [['action_type' => 'purchase', 'value' => (string) $webhookResult]],
                ]]]);
            }

            if (str_contains($url, '/'.$targetId)) {
                return Http::response($target);
            }

            return Http::response([], 404);
        });
    }

    private function signedWebhookBody(T4JamProfile $profile, string $accountId, array $changes): array
    {
        $content = json_encode([
            'object' => 'ad_account',
            'entry' => [[
                'id' => str_replace('act_', '', $accountId),
                'changes' => $changes,
            ]],
        ], JSON_THROW_ON_ERROR);

        return [
            'content' => $content,
            'server' => [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $content, (string) $profile->app_secret),
            ],
        ];
    }

    public function test_cpr_recovery_resumes_a_campaign_paused_by_cpr(): void
    {
        [$profile, $task] = $this->automationFixture();
        $task->campaign->update(['status' => 'PAUSED', 'effective_status' => 'PAUSED']);
        $task->update([
            'is_active' => false,
            'last_budget_action' => 'pause',
            'counter_cpr' => true,
            'cpr_cap' => 30000,
            'pause_cpr_cap' => 20000,
            'last_checked_at' => now()->subMinutes(11),
        ]);
        $this->fakeEnforcementInsights($task, 45000, 3);

        app(AutomationBudgetService::class)->pauseTasksOverCprCap(
            $profile,
            app(MetaAdsSyncService::class)->client($profile),
            true,
        );

        $fresh = $task->fresh();
        $this->assertTrue($fresh->is_active);
        $this->assertSame('resume', $fresh->last_budget_action);
        $this->assertSame('ACTIVE', $task->campaign->fresh()->status);
        $this->assertSame('ACTIVE', $task->campaign->fresh()->effective_status);
        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_contains($request->url(), $task->campaign_external_id)
            && $request['status'] === 'ACTIVE');
    }

    public function test_rate_limit_613_opens_shared_cooldown(): void
    {
        [$profile, $task] = $this->automationFixture();
        $task->update([
            'current_spend' => 75000,
            'current_result' => 1,
            'current_budget' => 50000,
            'last_checked_at' => now()->subMinutes(11),
        ]);

        Http::fake([
            'graph.facebook.com/*/'.$task->campaign->adAccount->external_id.'/insights?*' => Http::response([
                'data' => [[
                    'campaign_id' => $task->campaign_external_id,
                    'spend' => '75000',
                    'actions' => [['action_type' => 'purchase', 'value' => '1']],
                ]],
            ]),
            'graph.facebook.com/*/'.$task->campaign_external_id => Http::response([
                'error' => ['code' => 613, 'message' => 'Application request limit reached'],
            ], 429, ['Retry-After' => '30']),
        ]);

        $this->artisan('t4jam:enforce-automation')->assertSuccessful();

        $fresh = $task->fresh();
        $this->assertTrue($fresh->is_active);
        $this->assertSame('ACTIVE', $fresh->campaign->fresh()->status);
        $this->assertSame('pause', $fresh->pending_meta_action);
        $this->assertNotNull($fresh->meta_verification_due_at);
        $this->assertNotSame('pause', $fresh->last_budget_action);
        $this->assertStringContainsString('Pending pause (rate limited).', $fresh->last_log);

        $rateLimit = app(MetaRateLimitService::class);
        $this->assertTrue($rateLimit->isRateLimited($profile));
    }

    public function test_request_during_cooldown_is_not_sent(): void
    {
        [$profile, $task] = $this->automationFixture();
        $task->update([
            'current_spend' => 75000,
            'current_result' => 1,
            'current_budget' => 50000,
            'last_checked_at' => now()->subMinutes(11),
        ]);

        $rateLimit = app(MetaRateLimitService::class);
        $rateLimit->record($profile, 60);

        Http::fake([
            'graph.facebook.com/*' => Http::response(['error' => ['message' => 'should not be called']]),
        ]);

        $this->artisan('t4jam:enforce-automation')->assertSuccessful();

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'graph.facebook.com'));
        $this->assertTrue($task->fresh()->is_active);
    }

    public function test_over_cap_with_613_keeps_active_with_pending_pause(): void
    {
        [$profile, $task] = $this->automationFixture();
        $task->update([
            'current_spend' => 75000,
            'current_result' => 1,
            'current_budget' => 50000,
            'cpr_cap' => 25000,
            'last_checked_at' => now()->subMinutes(11),
        ]);

        Http::fake([
            'graph.facebook.com/*/'.$task->campaign->adAccount->external_id.'/insights?*' => Http::response([
                'data' => [[
                    'campaign_id' => $task->campaign_external_id,
                    'spend' => '75000',
                    'actions' => [['action_type' => 'purchase', 'value' => '1']],
                ]],
            ]),
            'graph.facebook.com/*/'.$task->campaign_external_id => Http::response([
                'error' => ['code' => 613, 'message' => 'Application request limit reached'],
            ], 429),
        ]);

        $this->artisan('t4jam:enforce-automation')->assertSuccessful();

        $fresh = $task->fresh();
        $this->assertTrue($fresh->is_active);
        $this->assertSame('ACTIVE', $fresh->campaign->fresh()->status);
        $this->assertNotSame('pause', $fresh->last_budget_action);
        $this->assertStringContainsString('Pending pause (rate limited).', $fresh->last_log);
    }

    public function test_resume_pending_pause_when_cooldown_expired_and_still_over_cap(): void
    {
        [$profile, $task] = $this->automationFixture();
        $task->update([
            'current_spend' => 75000,
            'current_result' => 1,
            'current_budget' => 50000,
            'cpr_cap' => 25000,
            'last_checked_at' => now()->subMinutes(11),
            'pending_meta_action' => 'pause',
            'last_log' => 'Pending pause (rate limited). CPR Rp. 75.000 vs cap Rp. 25.000.',
        ]);

        $rateLimit = app(MetaRateLimitService::class);
        $rateLimit->clear($profile);

        Http::fake([
            'graph.facebook.com/*/'.$task->campaign->adAccount->external_id.'/insights?*' => Http::response([
                'data' => [[
                    'campaign_id' => $task->campaign_external_id,
                    'spend' => '75000',
                    'actions' => [['action_type' => 'purchase', 'value' => '1']],
                ]],
            ]),
            'graph.facebook.com/*/'.$task->campaign_external_id => Http::response(['success' => true]),
        ]);

        $paused = app(AutomationBudgetService::class)->resumePendingPauses(
            $profile,
            app(MetaAdsSyncService::class)->client($profile),
        );

        $this->assertSame(1, $paused);
        $fresh = $task->fresh();
        $this->assertFalse($fresh->is_active);
        $this->assertSame('PAUSED', $fresh->campaign->fresh()->status);
        $this->assertSame('PAUSED', $fresh->campaign->fresh()->effective_status);
        $this->assertNull($fresh->pending_meta_action);
        $this->assertNull($fresh->meta_verification_due_at);
        $this->assertSame('pause', $fresh->last_budget_action);
        Http::assertSentCount(2);
    }

    public function test_resume_pending_pause_clears_when_cpr_healthy(): void
    {
        [$profile, $task] = $this->automationFixture();
        $task->update([
            'current_spend' => 10000,
            'current_result' => 1,
            'current_budget' => 50000,
            'cpr_cap' => 25000,
            'last_checked_at' => now()->subMinutes(11),
            'last_log' => 'Pending pause (rate limited). CPR Rp. 10.000 vs cap Rp. 25.000.',
        ]);

        $rateLimit = app(MetaRateLimitService::class);
        $rateLimit->clear($profile);

        Http::fake([
            'graph.facebook.com/*/'.$task->campaign->adAccount->external_id.'/insights?*' => Http::response([
                'data' => [[
                    'campaign_id' => $task->campaign_external_id,
                    'spend' => '10000',
                    'actions' => [['action_type' => 'purchase', 'value' => '1']],
                ]],
            ]),
        ]);

        $paused = app(AutomationBudgetService::class)->resumePendingPauses(
            $profile,
            app(MetaAdsSyncService::class)->client($profile),
        );

        $this->assertSame(0, $paused);
        $this->assertTrue($task->fresh()->is_active);
        $this->assertSame('ACTIVE', $task->campaign->fresh()->status);
        $this->assertSame('Pending pause dibatalkan karena CPR sudah kembali di bawah batas.', $task->fresh()->last_log);
    }
}
