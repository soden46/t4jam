<?php

namespace Tests\Feature;

use App\Jobs\SyncMetaAdsAccount;
use App\Models\AutomationTask;
use App\Models\Campaign;
use App\Models\T4JamProfile;
use App\Models\User;
use App\Services\AutomationBudgetService;
use App\Services\MetaAdsSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
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
            && $request['level'] === 'campaign'
            && $request['date_preset'] === 'last_7d');
    }

    public function test_relevant_campaign_webhook_sync_enforces_cpr_pause_immediately(): void
    {
        config([
            'queue.default' => 'database',
            'services.meta.webhook_sync_mode' => 'after_response',
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

    public function test_active_task_marks_manual_budget_decrease_after_manual_budget_decrease(): void
    {
        [, $task] = $this->automationFixture();
        $task->update([
            'is_active' => true,
            'last_budget_action' => 'manual',
        ]);

        $this->postManualBudgetDecrease($task)->assertOk();

        $this->assertSame('manual_budget_decrease', $task->fresh()->last_budget_action);
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
        $account = $task->campaign->adAccount;
        $campaigns = [[
            'id' => $task->campaign_external_id,
            'name' => $task->campaign->name,
            'status' => 'ACTIVE',
            'effective_status' => 'ACTIVE',
            'daily_budget' => '100000',
        ]];

        if ($extraCampaign) {
            $campaigns[] = [
                'id' => $extraCampaign->external_id,
                'name' => $extraCampaign->name,
                'status' => 'ACTIVE',
                'effective_status' => 'ACTIVE',
                'daily_budget' => '100000',
            ];
        }

        Http::fake(function ($request) use ($account, $task, $campaigns, $webhookSpend, $webhookResult) {
            $url = $request->url();

            if ($request->method() === 'POST' && str_contains($url, '/'.$task->campaign_external_id)) {
                return Http::response(['success' => true]);
            }

            if (str_contains($url, '/'.$account->external_id.'/campaigns')) {
                return Http::response(['data' => $campaigns]);
            }

            if (str_contains($url, '/'.$account->external_id.'/adsets')) {
                return Http::response(['data' => []]);
            }

            if (str_contains($url, '/'.$account->external_id.'/insights')) {
                return Http::response(['data' => $request['level'] === 'campaign' ? [[
                    'campaign_id' => $task->campaign_external_id,
                    'spend' => (string) $webhookSpend,
                    'actions' => [['action_type' => 'purchase', 'value' => (string) $webhookResult]],
                ]] : []]);
            }

            if (str_contains($url, '/'.$account->external_id)) {
                return Http::response([
                    'account_id' => $account->account_id,
                    'id' => $account->external_id,
                    'name' => $account->name,
                    'currency' => $account->currency,
                    'account_status' => 1,
                ]);
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
}
