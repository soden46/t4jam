<?php

namespace Tests\Feature;

use App\Jobs\PublishMetaAdSetup;
use App\Jobs\SyncMetaAdsAccount;
use App\Jobs\SyncMetaAdsProfile;
use App\Models\AdAccount;
use App\Models\AdSet;
use App\Models\AdSetup;
use App\Models\AutomationTask;
use App\Models\Campaign;
use App\Models\T4JamProfile;
use App\Models\User;
use App\Services\AutomationBudgetService;
use App\Services\MetaAdsSyncService;
use App\Support\MetaFlowLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_root_redirects_to_login_flow(): void
    {
        $this->get('/')->assertRedirect('/dashboard/');
        $this->get('/dashboard/')->assertRedirect('/login/');
        $this->get('/login/')->assertOk()->assertSee('Masuk Dashboard');
    }

    public function test_authenticated_user_can_open_clone_pages_and_api(): void
    {
        $this->seed(TestDataSeeder::class);
        $user = User::firstOrFail();

        $this->actingAs($user);

        $this->get('/dashboard/')->assertOk()->assertSee('Campaign Overview');
        $this->get('/automation-task/')->assertOk()->assertSee('Automation Budget Monitoring');
        $this->get('/interest/')->assertOk()->assertSee('Interest Explore Tools');
        $this->get('/riset-produk-toped/')->assertOk()->assertSee('Riset Produk Toped');
        $this->get('/setup-iklan/')->assertOk()->assertSee('Setup Iklan');
        $this->getJson('/api/get-ad-account/')->assertOk()->assertJsonPath('status', 200);
        $this->getJson('/get-automation-task/?acc=all&level=all&funnel=all')->assertOk()->assertJsonStructure(['data']);
    }

    public function test_seeded_admin_can_login_with_standard_credentials(): void
    {
        $this->seed(TestDataSeeder::class);

        $this->post('/login/', [
            'email' => 'admin@t4jam.local',
            'password' => 'password',
        ])->assertRedirect('/dashboard/');

        $this->assertAuthenticated();
    }

    public function test_create_automation_task_persists_dynamic_data(): void
    {
        $this->seed(TestDataSeeder::class);
        config(['services.meta.enable_writes' => true]);
        $user = User::firstOrFail();
        $this->actingAs($user);
        T4JamProfile::updateOrCreate(['user_id' => $user->id], ['access_token' => 'token']);

        $before = AutomationTask::count();
        $campaign = Campaign::with('adAccount')->firstOrFail();
        Http::fake(['graph.facebook.com/*/'.$campaign->external_id => Http::response(['success' => true])]);

        $this->postJson('/create-automation-tasks/', [
            'ad_account' => $campaign->adAccount->external_id,
            'campaign_id' => $campaign->external_id,
            'budget_funnel_lp' => 'lp_to_form',
            'mode_automation' => 'hybrid',
            'hold_spend' => 'loss',
            'budget_conversion' => 'lead',
            'starting_budget' => 125000,
            'maximum_budget' => 300000,
            'cpr_cap' => 25000,
            'period' => 10,
        ])->assertOk()->assertJsonPath('status', 200);

        $this->assertSame($before + 1, AutomationTask::count());
        $this->assertDatabaseHas('campaigns', ['id' => $campaign->id, 'daily_budget' => 125000]);

        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_contains($request->url(), $campaign->external_id)
            && ($request->data()['daily_budget'] ?? null) === 125000);
    }

    public function test_dashboard_campaign_data_is_scoped_to_selected_ad_account_and_campaigns(): void
    {
        $this->seed(TestDataSeeder::class);
        $user = User::firstOrFail();
        $this->actingAs($user);

        $account = AdAccount::with('campaigns')->whereHas('campaigns')->get()->last();
        $campaign = $account->campaigns->first();

        $accountResponse = $this
            ->withSession(['selected_ad_account' => $account->external_id])
            ->getJson('/api/get-ad-account/')
            ->assertOk()
            ->assertJsonPath('selected', $account->external_id)
            ->json('fix_campaign_list');

        $this->assertNotEmpty($accountResponse);
        $this->assertSame(
            $account->campaigns->pluck('external_id')->sort()->values()->all(),
            collect($accountResponse)->pluck('id')->sort()->values()->all()
        );

        $insights = $this
            ->withSession([
                'selected_ad_account' => $account->external_id,
                'selected_campaigns' => [$campaign->external_id],
            ])
            ->getJson('/api/get-ad-insight/?ad_account='.$account->external_id)
            ->assertOk()
            ->json('summery');

        $this->assertSame([$campaign->external_id], collect($insights)->pluck('campaign_id')->all());
    }

    public function test_dashboard_adset_level_returns_adsets_for_selected_ad_account(): void
    {
        $this->seed(TestDataSeeder::class);
        $user = User::firstOrFail();
        $this->actingAs($user);

        $account = AdAccount::with('adSets')->whereHas('adSets')->firstOrFail();
        $adSet = $account->adSets->first();

        $insights = $this
            ->withSession(['selected_ad_account' => $account->external_id])
            ->getJson('/api/get-ad-insight/?ad_account='.$account->external_id.'&level=adset')
            ->assertOk()
            ->json('summery');

        $this->assertContains($adSet->external_id, collect($insights)->pluck('campaign_id')->all());
        $this->assertSame('adset', collect($insights)->firstWhere('campaign_id', $adSet->external_id)['level']);
    }

    public function test_deleted_meta_targets_are_hidden_from_automation_target_lists(): void
    {
        $this->seed(TestDataSeeder::class);
        $user = User::firstOrFail();
        $this->actingAs($user);

        $campaign = Campaign::with(['adAccount', 'adSets'])->whereHas('adSets')->firstOrFail();
        $adSet = $campaign->adSets->first();
        $campaign->update(['status' => 'DELETED', 'effective_status' => 'DELETED']);
        $adSet->update(['status' => 'DELETED', 'effective_status' => 'DELETED']);

        $response = $this
            ->withSession(['selected_ad_account' => $campaign->adAccount->external_id])
            ->getJson('/api/get-ad-account/')
            ->assertOk();

        $account = collect($response->json('adaccount'))->firstWhere('id', $campaign->adAccount->external_id);
        $this->assertNotContains($campaign->external_id, collect($account['campaigns']['data'])->pluck('id'));
        $this->assertNotContains($adSet->external_id, collect($account['adsets']['data'])->pluck('id'));
        $this->assertNotContains($campaign->external_id, collect($response->json('fix_campaign_list'))->pluck('id'));
    }

    public function test_dashboard_meta_lists_show_newest_data_first(): void
    {
        $this->seed(TestDataSeeder::class);
        $user = User::firstOrFail();
        $this->actingAs($user);

        $olderAccount = AdAccount::create([
            'account_id' => 'old-acc',
            'external_id' => 'act_oldest',
            'name' => 'Oldest Account',
            'currency' => 'IDR',
            'updated_at' => now()->subDays(3),
        ]);
        Campaign::create([
            'ad_account_id' => $olderAccount->id,
            'external_id' => 'cmp_oldest',
            'name' => 'Oldest Campaign',
            'status' => 'ACTIVE',
            'effective_status' => 'ACTIVE',
            'updated_at' => now()->subDays(3),
        ]);

        $newerAccount = AdAccount::create([
            'account_id' => 'new-acc',
            'external_id' => 'act_newest',
            'name' => 'Newest Account',
            'currency' => 'IDR',
            'updated_at' => now(),
        ]);
        $olderCampaign = Campaign::create([
            'ad_account_id' => $newerAccount->id,
            'external_id' => 'cmp_nested_oldest',
            'name' => 'Nested Oldest Campaign',
            'status' => 'ACTIVE',
            'effective_status' => 'ACTIVE',
            'updated_at' => now()->subDays(2),
        ]);
        $newerCampaign = Campaign::create([
            'ad_account_id' => $newerAccount->id,
            'external_id' => 'cmp_nested_newest',
            'name' => 'Nested Newest Campaign',
            'status' => 'ACTIVE',
            'effective_status' => 'ACTIVE',
            'updated_at' => now(),
        ]);
        AdSet::create([
            'ad_account_id' => $newerAccount->id,
            'campaign_id' => $olderCampaign->id,
            'external_id' => 'adset_oldest',
            'name' => 'Oldest Ad Set',
            'status' => 'ACTIVE',
            'effective_status' => 'ACTIVE',
            'updated_at' => now()->subDays(2),
        ]);
        AdSet::create([
            'ad_account_id' => $newerAccount->id,
            'campaign_id' => $newerCampaign->id,
            'external_id' => 'adset_newest',
            'name' => 'Newest Ad Set',
            'status' => 'ACTIVE',
            'effective_status' => 'ACTIVE',
            'updated_at' => now(),
        ]);

        $response = $this
            ->withSession(['selected_ad_account' => $newerAccount->external_id])
            ->getJson('/api/get-ad-account/')
            ->assertOk();

        $this->assertSame('act_newest', $response->json('adaccount.0.id'));
        $this->assertSame('cmp_nested_newest', $response->json('adaccount.0.campaigns.data.0.id'));
        $this->assertSame('adset_newest', $response->json('adaccount.0.adsets.data.0.id'));
        $this->assertSame('cmp_nested_newest', $response->json('fix_campaign_list.0.id'));
    }

    public function test_dashboard_reload_syncs_only_selected_account_campaigns(): void
    {
        $this->seed(TestDataSeeder::class);
        $user = User::firstOrFail();
        $this->actingAs($user);

        T4JamProfile::updateOrCreate(
            ['user_id' => $user->id],
            ['access_token' => 'token']
        );
        $staleAccount = AdAccount::create([
            'external_id' => 'act_901',
            'account_id' => '901',
            'name' => 'Stale Account',
            'currency' => 'IDR',
            'account_status' => 1,
        ]);
        $staleCampaign = Campaign::create([
            'ad_account_id' => $staleAccount->id,
            'external_id' => 'cmp_deleted',
            'name' => 'Deleted Meta Campaign',
            'status' => 'ACTIVE',
            'effective_status' => 'ACTIVE',
            'daily_budget' => 100000,
        ]);

        Http::fake([
            'graph.facebook.com/*/act_901/campaigns?*' => Http::response([
                'data' => [
                    [
                        'id' => 'cmp_901',
                        'name' => 'Testing Tools Campaign',
                        'status' => 'ACTIVE',
                        'effective_status' => 'ACTIVE',
                        'daily_budget' => '250000',
                        'objective' => 'OUTCOME_TRAFFIC',
                    ],
                ],
            ]),

            'graph.facebook.com/*/act_901?*' => Http::response([
                'account_id' => '901',
                'id' => 'act_901',
                'name' => 'Reloaded Account',
                'currency' => 'IDR',
                'account_status' => 1,
            ]),
        ]);

        $this->postJson('/api/reload-ad-account/', [
            'ad_account' => 'act_901',
        ])
            ->assertOk()
            ->assertJsonPath('status', 200)
            ->assertJsonPath(
                'text',
                'Reload selesai. 1 campaign diperbarui.'
            );

        $this->assertDatabaseHas('ad_accounts', [
            'external_id' => 'act_901',
            'name' => 'Reloaded Account',
        ]);

        $this->assertDatabaseHas('campaigns', [
            'external_id' => 'cmp_901',
            'name' => 'Testing Tools Campaign',
        ]);
        $this->assertSame('DELETED', $staleCampaign->fresh()->status);

        // Membuktikan Reload tidak melakukan full sync, adset lookup, atau insights.
        Http::assertSentCount(2);
    }

    public function test_dashboard_reload_rejects_another_users_token(): void
    {
        $this->seed(TestDataSeeder::class);
        T4JamProfile::where('user_id', User::firstOrFail()->id)->firstOrFail()->update(['access_token' => 'shared-token']);
        $this->actingAs(User::factory()->create());
        Http::fake();
        $this->postJson('/api/reload-ad-account/', ['ad_account' => 'act_902'])
            ->assertUnprocessable()->assertJsonPath('text', 'Access token Meta belum diisi.');
        Http::assertNothingSent();
    }

    public function test_create_automation_requires_campaign_from_selected_ad_account(): void
    {
        $this->seed(TestDataSeeder::class);
        $user = User::firstOrFail();
        $this->actingAs($user);

        $accounts = AdAccount::with('campaigns')->whereHas('campaigns')->take(2)->get();
        $firstAccount = $accounts->first();
        $otherCampaign = $accounts->last()->campaigns->first();

        $this->postJson('/create-automation-tasks/', [
            'ad_account' => $firstAccount->external_id,
            'campaign_id' => $otherCampaign->external_id,
        ])->assertStatus(422)->assertJsonPath('text', 'Pilih campaign dari ad account yang aktif dulu.');
    }

    public function test_create_automation_rejects_deleted_campaign_before_meta_write(): void
    {
        $this->seed(TestDataSeeder::class);
        config(['services.meta.enable_writes' => true]);
        $user = User::firstOrFail();
        $this->actingAs($user);
        T4JamProfile::updateOrCreate(['user_id' => $user->id], ['access_token' => 'token']);

        $campaign = Campaign::with('adAccount')->firstOrFail();
        $campaign->update(['status' => 'DELETED', 'effective_status' => 'DELETED']);

        Http::fake();
        $this->postJson('/create-automation-tasks/', [
            'ad_account' => $campaign->adAccount->external_id,
            'campaign_id' => $campaign->external_id,
            'starting_budget' => 100000,
        ])->assertUnprocessable()
            ->assertJsonPath('text', 'Campaign ini sudah dihapus/diarsipkan di Meta. Klik Reload lalu pilih campaign aktif.');

        Http::assertNothingSent();
    }

    public function test_update_automation_task_pushes_budget_to_meta_campaign(): void
    {
        $this->seed(TestDataSeeder::class);
        config(['services.meta.enable_writes' => true]);
        $user = User::firstOrFail();
        $this->actingAs($user);
        T4JamProfile::updateOrCreate(['user_id' => $user->id], ['access_token' => 'token']);

        $task = AutomationTask::with('campaign')->firstOrFail();
        Http::fake(['graph.facebook.com/*/'.$task->campaign->external_id => Http::response(['success' => true])]);

        $this->postJson('/update-automation-tasks/', [
            'automation_id' => $task->id,
            'budget_funnel_lp' => 'lp_to_wa',
            'mode_automation' => 'default',
            'hold_spend' => 'onhold',
            'budget_conversion' => 'purchase',
            'starting_budget' => 1500000,
            'maximum_budget' => 0,
            'cpr_cap' => 7000,
            'period' => 10,
        ])->assertOk()
            ->assertJsonPath('text', 'Automation strategy berhasil diupdate dan budget Meta berhasil diupdate.');

        $task->refresh();

        $this->assertSame(1500000, $task->current_budget);
        $this->assertDatabaseHas('campaigns', ['id' => $task->campaign_id, 'daily_budget' => 1500000]);
        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_contains($request->url(), $task->campaign->external_id)
            && ($request->data()['daily_budget'] ?? null) === 1500000);
    }

    public function test_update_automation_rejects_another_users_task(): void
    {
        $this->seed(TestDataSeeder::class);
        config(['services.meta.enable_writes' => true]);
        T4JamProfile::where('user_id', User::firstOrFail()->id)->firstOrFail()->update(['access_token' => 'shared-token']);
        $this->actingAs(User::factory()->create());
        Http::fake();
        $this->postJson('/update-automation-tasks/', ['automation_id' => AutomationTask::firstOrFail()->id, 'starting_budget' => 1500000])->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_dashboard_insights_reflect_budget_after_automation_update(): void
    {
        $this->seed(TestDataSeeder::class);
        config(['services.meta.enable_writes' => true]);
        $user = User::firstOrFail();
        $this->actingAs($user);
        T4JamProfile::updateOrCreate(['user_id' => $user->id], ['access_token' => 'token']);

        $task = AutomationTask::with(['adAccount', 'campaign'])->firstOrFail();
        Http::fake(['graph.facebook.com/*/'.$task->campaign->external_id => Http::response(['success' => true])]);

        $this->postJson('/update-automation-tasks/', [
            'automation_id' => $task->id,
            'starting_budget' => 250000,
        ])->assertOk();

        $rows = $this
            ->withSession([
                'selected_ad_account' => $task->adAccount->external_id,
                'selected_campaigns' => [$task->campaign->external_id],
            ])
            ->getJson('/api/get-ad-insight/?ad_account='.$task->adAccount->external_id)
            ->assertOk()
            ->json('summery');

        $this->assertSame(250000, collect($rows)->firstWhere('campaign_id', $task->campaign->external_id)['budget']);
    }

    public function test_automation_budget_metrics_use_task_snapshot_metrics_and_separate_meta_status(): void
    {
        $this->seed(TestDataSeeder::class);
        $user = User::firstOrFail();
        $this->actingAs($user);

        $task = AutomationTask::with(['adAccount', 'campaign'])->firstOrFail();
        $campaignMetrics = app(AutomationBudgetService::class)->metricSnapshot(['spend' => 35372, 'actions' => [
            ['action_type' => 'purchase', 'value' => 1],
            ['action_type' => 'initiate_checkout', 'value' => 3],
        ]]);
        $task->campaign->update([
            'daily_budget' => 55000,
            'status' => 'PAUSED',
            'effective_status' => 'PAUSED',
        ] + app(AutomationBudgetService::class)->insightPayload($campaignMetrics));
        $task->update([
            'conversion' => 'initiate_checkout',
            'is_active' => true,
            'current_budget' => 55000,
            'current_spend' => 42074,
            'current_result' => 1,
            'last_metrics_synced_at' => now(),
        ]);

        $row = collect($this
            ->getJson('/get-automation-task/?acc=all&level=all&funnel=all&local=1')
            ->assertOk()
            ->json('data'))
            ->firstWhere('id', $task->id);

        $this->assertSame(55000, $row['current_budget']);
        $this->assertSame(42074, $row['current_spend']);
        $this->assertSame(1, $row['current_hasil']);
        $this->assertSame(42074, $row['current_cpr']);
        $this->assertSame('active', $row['automation_status']);
        $this->assertSame('PAUSED', $row['meta_status']);
        $this->assertSame('PAUSED', $row['meta_effective_status']);
        $this->assertTrue($row['metrics_available']);
        $this->assertFalse($row['metrics_stale']);
    }

    public function test_automation_task_endpoint_exposes_configured_cpr_cap(): void
    {
        $this->seed(TestDataSeeder::class);
        $user = User::firstOrFail();
        $this->actingAs($user);

        $task = AutomationTask::firstOrFail();
        $task->update(['cpr_cap' => 25000]);

        $row = collect($this
            ->getJson('/get-automation-task/?acc=all&level=all&funnel=all&local=1')
            ->assertOk()
            ->json('data'))
            ->firstWhere('id', $task->id);

        $this->assertSame(25000, $row['cpr_cap']);
    }

    public function test_automation_task_endpoint_returns_local_metrics_without_meta_refresh(): void
    {
        $this->seed(TestDataSeeder::class);
        Cache::flush();
        $user = User::firstOrFail();
        $this->actingAs($user);
        T4JamProfile::updateOrCreate(['user_id' => $user->id], ['access_token' => 'token']);

        $task = AutomationTask::with(['campaign.adAccount'])->firstOrFail();
        AutomationTask::whereKeyNot($task->id)->delete();
        $task->campaign->update([
            'spend' => 0,
            'result' => 0,
            'conversion_results' => [],
        ]);
        $task->update([
            'conversion' => 'purchase',
            'current_spend' => 42000,
            'current_result' => 2,
            'last_checked_at' => null,
            'last_metrics_synced_at' => now(),
        ]);

        Http::fake(['*' => Http::response(['error' => ['message' => 'Meta unavailable']], 500)]);

        $response = $this
            ->getJson('/get-automation-task/?acc=all&level=all&funnel=all')
            ->assertOk();

        $row = collect($response->json('data'))->firstWhere('id', $task->id);

        $this->assertSame('local_only', $response->json('meta_sync.reason'));
        $this->assertSame(0, $response->json('meta_sync.updated'));
        $this->assertSame(42000, $row['current_spend']);
        $this->assertSame(2, $row['current_hasil']);
        $this->assertSame(21000, $row['current_cpr']);
        $this->assertTrue($row['metrics_available']);
        $this->assertFalse($row['metrics_stale']);
        $this->assertSame(42000, $task->fresh()->current_spend);
        $this->assertSame(2, $task->fresh()->current_result);
        Http::assertNothingSent();
    }

    public function test_automation_task_endpoint_filters_local_rows_without_meta_calls(): void
    {
        $this->seed(TestDataSeeder::class);
        $user = User::firstOrFail();
        $this->actingAs($user);
        $tasks = AutomationTask::with('adAccount')->take(2)->get();
        $target = $tasks->first();
        $other = $tasks->last();
        $target->update(['level' => 'campaign', 'event_flow' => 'lp_to_wa']);
        $other->update(['level' => 'adset', 'event_flow' => 'lp_to_form']);
        Http::fake();

        $rows = $this
            ->getJson('/get-automation-task/?acc='.$target->adAccount->external_id.'&level=campaign&funnel=lp_to_wa')
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $rows);
        $this->assertSame($target->id, $rows[0]['id']);
        Http::assertNothingSent();
    }

    public function test_automation_budget_meta_status_resolves_legacy_task_by_external_campaign_id(): void
    {
        $this->seed(TestDataSeeder::class);
        $user = User::firstOrFail();
        $this->actingAs($user);

        $task = AutomationTask::with('campaign')->firstOrFail();
        $task->campaign->update([
            'daily_budget' => 55000,
            'status' => 'PAUSED',
            'effective_status' => 'PAUSED',
            'spend' => 35372,
            'result' => 1,
        ]);
        $task->update([
            'campaign_id' => null,
            'conversion' => 'purchase',
            'current_budget' => 55000,
            'current_spend' => 24000,
            'current_result' => 2,
        ]);

        $row = collect($this
            ->getJson('/get-automation-task/?acc=all&level=all&funnel=all&local=1')
            ->assertOk()
            ->json('data'))
            ->firstWhere('id', $task->id);

        $this->assertSame(55000, $row['current_budget']);
        $this->assertSame(24000, $row['current_spend']);
        $this->assertSame(2, $row['current_hasil']);
        $this->assertSame(12000, $row['current_cpr']);
        $this->assertSame('PAUSED', $row['meta_status']);
    }

    public function test_update_automation_task_pushes_budget_to_meta_adset(): void
    {
        $this->seed(TestDataSeeder::class);
        config(['services.meta.enable_writes' => true]);
        $user = User::firstOrFail();
        $this->actingAs($user);
        T4JamProfile::updateOrCreate(['user_id' => $user->id], ['access_token' => 'token']);

        $adSet = AdSet::with(['adAccount', 'campaign'])->firstOrFail();
        $task = AutomationTask::create([
            'user_id' => $user->id,
            'id' => (string) str()->uuid(),
            'ad_account_id' => $adSet->ad_account_id,
            'campaign_id' => $adSet->campaign_id,
            'ad_set_id' => $adSet->id,
            'campaign_external_id' => $adSet->campaign->external_id,
            'ad_set_external_id' => $adSet->external_id,
            'campaign_name' => $adSet->name,
            'ad_account_name' => $adSet->adAccount->name,
            'level' => 'adset',
            'current_budget' => $adSet->daily_budget,
        ]);
        Http::fake(['graph.facebook.com/*/'.$adSet->external_id => Http::response(['success' => true])]);

        $this->postJson('/update-automation-tasks/', [
            'automation_id' => $task->id,
            'budget_funnel_lp' => 'lp_to_wa',
            'mode_automation' => 'default',
            'hold_spend' => 'onhold',
            'budget_conversion' => 'purchase',
            'starting_budget' => 20000,
            'maximum_budget' => 0,
            'cpr_cap' => 7000,
            'period' => 10,
        ])->assertOk()
            ->assertJsonPath('text', 'Automation strategy berhasil diupdate dan budget Meta berhasil diupdate.');

        $this->assertDatabaseHas('ad_sets', ['id' => $adSet->id, 'daily_budget' => 20000]);
        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_contains($request->url(), $adSet->external_id)
            && ($request->data()['daily_budget'] ?? null) === 20000);
    }

    public function test_budget_update_fails_clearly_when_meta_write_mode_is_disabled(): void
    {
        $this->seed(TestDataSeeder::class);
        config(['services.meta.enable_writes' => false]);
        $user = User::firstOrFail();
        $this->actingAs($user);

        $task = AutomationTask::firstOrFail();
        $originalBudget = $task->current_budget;

        $this->postJson('/update-automation-tasks/', [
            'automation_id' => $task->id,
            'starting_budget' => 20000,
        ])->assertStatus(422)
            ->assertJsonPath('text', 'Budget belum dikirim ke Meta karena write mode belum aktif.');

        $this->assertSame($originalBudget, $task->fresh()->current_budget);
    }

    public function test_budget_update_surfaces_meta_provider_message_without_local_change(): void
    {
        $this->seed(TestDataSeeder::class);
        config(['services.meta.enable_writes' => true]);
        $user = User::firstOrFail();
        $this->actingAs($user);
        T4JamProfile::updateOrCreate(['user_id' => $user->id], ['access_token' => 'token']);

        $task = AutomationTask::with('campaign')->firstOrFail();
        $originalBudget = $task->current_budget;
        Http::fake([
            'graph.facebook.com/*/'.$task->campaign->external_id => Http::response([
                'error' => [
                    'message' => 'Invalid parameter: daily_budget is too low for this campaign.',
                    'type' => 'OAuthException',
                    'code' => 100,
                    'error_subcode' => 1815755,
                ],
            ], 400),
        ]);

        $this->postJson('/update-automation-tasks/', [
            'automation_id' => $task->id,
            'starting_budget' => 7000,
        ])->assertUnprocessable()
            ->assertJsonPath('text', 'Meta menolak update: Invalid parameter: daily_budget is too low for this campaign.');

        $this->assertSame($originalBudget, $task->fresh()->current_budget);
    }

    public function test_deleted_meta_campaign_response_marks_local_target_deleted(): void
    {
        $this->seed(TestDataSeeder::class);
        config(['services.meta.enable_writes' => true]);
        $user = User::firstOrFail();
        $this->actingAs($user);
        T4JamProfile::updateOrCreate(['user_id' => $user->id], ['access_token' => 'token']);

        $task = AutomationTask::with('campaign')->firstOrFail();
        Http::fake([
            'graph.facebook.com/*/'.$task->campaign->external_id => Http::response([
                'error' => [
                    'message' => 'Kampanye ini sudah dihapus sehingga Anda hanya bisa mengedit nama.',
                    'type' => 'OAuthException',
                    'code' => 100,
                    'error_subcode' => 1487566,
                ],
            ], 400),
        ]);

        $this->postJson('/update-automation-tasks/', [
            'automation_id' => $task->id,
            'starting_budget' => 7000,
        ])->assertUnprocessable()
            ->assertJsonPath('text', 'Meta menolak update: Kampanye ini sudah dihapus sehingga Anda hanya bisa mengedit nama.');

        $this->assertSame('DELETED', $task->campaign->fresh()->status);
    }

    public function test_delete_automation_task_removes_only_owners_strategy(): void
    {
        $this->seed(TestDataSeeder::class);
        $user = User::firstOrFail();
        $this->actingAs(User::factory()->create());
        $task = AutomationTask::firstOrFail();

        $this->postJson('/delete-automation-tasks/', ['automation_id' => $task->id])
            ->assertNotFound();
        $this->assertDatabaseHas('automation_tasks', ['id' => $task->id]);

        $this->actingAs($user);
        $this->postJson('/delete-automation-tasks/', ['automation_id' => $task->id])
            ->assertOk()
            ->assertJsonPath('status', 200);

        $this->assertDatabaseMissing('automation_tasks', ['id' => $task->id]);
        $this->assertDatabaseHas('campaigns', ['id' => $task->campaign_id]);
    }

    public function test_rule_update_without_budget_change_does_not_require_meta_write_mode(): void
    {
        $this->seed(TestDataSeeder::class);
        config(['services.meta.enable_writes' => false]);
        $user = User::firstOrFail();
        $this->actingAs($user);

        $task = AutomationTask::firstOrFail();
        $originalBudget = $task->current_budget;

        $this->postJson('/update-automation-tasks/', [
            'automation_id' => $task->id,
            'budget_funnel_lp' => 'lp_to_form',
            'mode_automation' => 'hybrid',
            'hold_spend' => 'loss',
            'budget_conversion' => 'lead',
            'starting_budget' => $task->starting_budget,
            'maximum_budget' => 350000,
            'cpr_cap' => 45000,
            'period' => 15,
            'automation_activation' => 'active',
        ])->assertOk()
            ->assertJsonPath('text', 'Automation strategy berhasil diupdate.');

        $task->refresh();

        $this->assertSame($originalBudget, $task->current_budget);
        $this->assertSame(45000, $task->cpr_cap);
        $this->assertSame(15, $task->period);
        $this->assertSame('lead', $task->conversion);
    }

    public function test_rule_update_refreshes_meta_budget_when_writes_are_enabled(): void
    {
        $this->seed(TestDataSeeder::class);
        config(['services.meta.enable_writes' => true]);
        $user = User::firstOrFail();
        $this->actingAs($user);
        T4JamProfile::updateOrCreate(['user_id' => $user->id], ['access_token' => 'token']);

        $task = AutomationTask::with('campaign')->firstOrFail();
        $task->update([
            'starting_budget' => 999988,
            'current_budget' => 999988,
        ]);
        $task->campaign->update(['daily_budget' => 999988]);
        Http::fake(['graph.facebook.com/*/'.$task->campaign->external_id => Http::response(['success' => true])]);

        $this->postJson('/update-automation-tasks/', [
            'automation_id' => $task->id,
            'budget_funnel_lp' => 'lp_to_form',
            'mode_automation' => 'hybrid',
            'hold_spend' => 'loss',
            'budget_conversion' => 'lead',
            'starting_budget' => 999988,
            'maximum_budget' => 350000,
            'cpr_cap' => 45000,
            'period' => 15,
            'automation_activation' => 'active',
        ])->assertOk()
            ->assertJsonPath('text', 'Automation strategy berhasil diupdate dan budget Meta berhasil diupdate.');

        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_contains($request->url(), $task->campaign->external_id)
            && ($request->data()['daily_budget'] ?? null) === 999988);
    }

    public function test_update_automation_task_updates_meta_immediately(): void
    {
        $this->seed(TestDataSeeder::class);
        config(['services.meta.enable_writes' => true]);
        $user = User::firstOrFail();
        $this->actingAs($user);
        T4JamProfile::updateOrCreate(['user_id' => $user->id], ['access_token' => 'token']);

        $task = AutomationTask::with('campaign')->firstOrFail();
        Http::fake(['graph.facebook.com/*/'.$task->campaign->external_id => Http::response(['success' => true])]);

        $this->postJson('/update-automation-tasks/', [
            'automation_id' => $task->id,
            'starting_budget' => 200000,
        ])->assertOk()
            ->assertJsonPath('text', 'Automation strategy berhasil diupdate dan budget Meta berhasil diupdate.');

        $this->assertSame(200000, $task->fresh()->current_budget);
        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_contains($request->url(), $task->campaign->external_id)
            && ($request->data()['daily_budget'] ?? null) === 200000);
    }

    public function test_google_sign_in_button_logs_in_with_local_fallback(): void
    {
        $this->get('/social-auth/login/google-oauth2/')
            ->assertRedirect('/dashboard/');

        $this->assertAuthenticated();
        $this->assertDatabaseHas('users', ['email' => 'google-demo@t4jam.local']);
    }

    public function test_meta_ads_sync_persists_accounts_campaigns_and_insights(): void
    {
        Queue::fake();
        $this->seed(TestDataSeeder::class);
        $user = User::firstOrFail();
        $this->actingAs($user);

        Http::fake([
            'graph.facebook.com/*/oauth/access_token?*' => Http::response(['access_token' => 'long-token']),
            'graph.facebook.com/*/me?*' => Http::response(['id' => 'meta-user-1', 'name' => 'Meta Tester']),
            'graph.facebook.com/*/me/adaccounts?*' => Http::response([
                'data' => [
                    ['account_id' => '123', 'id' => 'act_123', 'name' => 'Meta Account', 'currency' => 'IDR', 'account_status' => 1],
                ],
            ]),
            'graph.facebook.com/*/me/businesses?*' => Http::response(['data' => []]),
            'graph.facebook.com/*/act_123/campaigns?*' => Http::response([
                'data' => [
                    ['id' => 'cmp_1', 'name' => 'Meta Campaign', 'status' => 'ACTIVE', 'effective_status' => 'ACTIVE', 'daily_budget' => '150000', 'objective' => 'OUTCOME_SALES'],
                ],
            ]),
            'graph.facebook.com/*/cmp_1/adsets?*' => Http::response(['data' => []]),
            'graph.facebook.com/*/act_123/insights?*' => Http::response([
                'data' => [[
                    'campaign_id' => 'cmp_1',
                    'spend' => '45000',
                    'reach' => '3000',
                    'inline_link_clicks' => '120',
                    'actions' => [
                        ['action_type' => 'purchase', 'value' => '3'],
                        ['action_type' => 'landing_page_view', 'value' => '90'],
                    ],
                ]],
            ]),
        ]);

        $this->get('/profile/')->assertOk();

        $this->post('/profile/access-token/', [
            'id_aplikasi' => 'app-id',
            'kunci_rahasia' => 'secret',
            'access_token_app' => 'token',
        ])->assertRedirect('/profile/')
            ->assertSessionHas('status', 'Access token tersimpan. Sync Meta Ads masuk antrean queue.');

        $profile = T4JamProfile::where('user_id', $user->id)->firstOrFail();
        Queue::assertPushed(SyncMetaAdsProfile::class, fn (SyncMetaAdsProfile $job) => $job->queue === 'meta');
        $this->runMetaSyncJob($profile);

        $this->postJson('/profile/sync-meta-ads/')
            ->assertOk()
            ->assertJsonPath('status', 200)
            ->assertJsonPath('text', 'Sync Meta Ads masuk antrean queue.');

        $this->assertDatabaseHas('ad_accounts', ['external_id' => 'act_123', 'name' => 'Meta Account']);
        $this->assertDatabaseHas('campaigns', ['external_id' => 'cmp_1', 'spend' => 45000, 'result' => 3, 'landing_page_view' => 90]);
        $this->assertDatabaseHas('t4jam_profiles', ['user_id' => $user->id, 'meta_user_name' => 'Meta Tester', 'last_meta_error' => null]);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/act_123/insights')
            && $request['level'] === 'campaign'
            && $request['date_preset'] === 'last_30d');
    }

    public function test_manual_meta_ads_sync_is_queued_and_persists_data_in_job(): void
    {
        Queue::fake();
        $this->seed(TestDataSeeder::class);
        $user = User::firstOrFail();
        $this->actingAs($user);

        $profile = T4JamProfile::updateOrCreate(['user_id' => $user->id], ['access_token' => 'token']);

        Http::fake([
            'graph.facebook.com/*/me?*' => Http::response(['id' => 'meta-user-1', 'name' => 'Meta Tester']),
            'graph.facebook.com/*/me/adaccounts?*' => Http::response([
                'data' => [
                    ['account_id' => '456', 'id' => 'act_456', 'name' => 'Manual Account', 'currency' => 'IDR', 'account_status' => 1],
                ],
            ]),
            'graph.facebook.com/*/me/businesses?*' => Http::response(['data' => []]),
            'graph.facebook.com/*/act_456/campaigns?*' => Http::response([
                'data' => [
                    ['id' => 'cmp_456', 'name' => 'Manual Campaign', 'status' => 'ACTIVE', 'daily_budget' => '250000'],
                ],
            ]),
            'graph.facebook.com/*/cmp_456/adsets?*' => Http::response(['data' => []]),
            'graph.facebook.com/*/act_456/insights?*' => Http::response(['data' => []]),
        ]);

        $this->postJson('/profile/sync-meta-ads/')
            ->assertOk()
            ->assertJsonPath('status', 200)
            ->assertJsonPath('text', 'Sync Meta Ads masuk antrean queue.');
        Queue::assertPushed(SyncMetaAdsProfile::class, fn (SyncMetaAdsProfile $job) => $job->queue === 'meta');
        Http::assertNothingSent();
        $this->assertDatabaseMissing('ad_accounts', ['external_id' => 'act_456']);

        $this->runMetaSyncJob($profile);

        $this->assertDatabaseHas('ad_accounts', ['external_id' => 'act_456']);
        $this->assertDatabaseHas('campaigns', ['external_id' => 'cmp_456', 'name' => 'Manual Campaign']);
    }

    public function test_meta_webhook_verification_requires_matching_token(): void
    {
        config(['services.meta.webhook_verify_token' => 'verify-me']);

        $this->get('/meta/webhook/?hub_mode=subscribe&hub_verify_token=wrong&hub_challenge=123')
            ->assertForbidden();
        $this->get('/meta/webhook/?hub_mode=subscribe&hub_verify_token=verify-me&hub_challenge=123')
            ->assertOk()
            ->assertSeeText('123');
    }

    public function test_signed_meta_webhook_queues_account_sync_for_linked_profile(): void
    {
        Queue::fake();
        $this->seed(TestDataSeeder::class);
        $user = User::firstOrFail();
        $profile = T4JamProfile::updateOrCreate(['user_id' => $user->id], [
            'access_token' => 'token',
            'app_secret' => 'webhook-secret',
        ]);
        $account = AdAccount::firstOrFail();
        $profile->adAccounts()->syncWithoutDetaching([$account->id]);
        $body = json_encode([
            'object' => 'ad_account',
            'entry' => [[
                'id' => str_replace('act_', '', $account->external_id),
                'changes' => [['field' => 'campaigns'], ['field' => 'adsets']],
            ]],
        ], JSON_THROW_ON_ERROR);
        $signature = 'sha256='.hash_hmac('sha256', $body, 'webhook-secret');

        $this->call('POST', '/meta/webhook/', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => $signature,
        ], $body)
            ->assertOk()
            ->assertJson(['status' => 'received', 'queued' => 1]);

        Queue::assertPushed(SyncMetaAdsAccount::class, fn (SyncMetaAdsAccount $job) => $job->profileId === $profile->id
            && $job->adAccountExternalId === $account->external_id
            && $job->queue === 'meta');
    }

    public function test_meta_webhook_rejects_invalid_signature_without_queueing(): void
    {
        Queue::fake();
        config(['services.meta.webhook_app_secret' => 'webhook-secret']);

        $this->withHeader('X-Hub-Signature-256', 'sha256=invalid')
            ->postJson('/meta/webhook/', ['object' => 'ad_account', 'entry' => []])
            ->assertUnauthorized();

        Queue::assertNothingPushed();
    }

    public function test_webhook_account_sync_reconciles_meta_budget_and_status_locally(): void
    {
        $this->seed(TestDataSeeder::class);
        $user = User::firstOrFail();
        $profile = T4JamProfile::updateOrCreate(['user_id' => $user->id], ['access_token' => 'token']);
        $task = AutomationTask::query()->where('user_id', $user->id)->where('level', 'campaign')->with(['campaign.adAccount'])->firstOrFail();
        $campaign = $task->campaign;
        $account = $campaign->adAccount;
        $task->update(['is_active' => true, 'current_budget' => 100000, 'last_budget_action' => 'manual']);

        Http::fake(function ($request) use ($account, $campaign) {
            $url = $request->url();

            if (str_contains($url, '/'.$account->external_id.'/campaigns')) {
                return Http::response(['data' => [[
                    'id' => $campaign->external_id,
                    'name' => $campaign->name,
                    'status' => 'PAUSED',
                    'effective_status' => 'PAUSED',
                    'daily_budget' => '210000',
                    'objective' => 'OUTCOME_SALES',
                ]]]);
            }

            if (str_contains($url, '/'.$account->external_id.'/adsets')) {
                return Http::response(['data' => []]);
            }

            if (str_contains($url, '/'.$account->external_id.'/insights')) {
                return Http::response(['data' => $request['level'] === 'campaign' ? [[
                    'campaign_id' => $campaign->external_id,
                    'spend' => '45000',
                    'reach' => '1000',
                    'inline_link_clicks' => '20',
                    'actions' => [['action_type' => 'purchase', 'value' => '3']],
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

        (new SyncMetaAdsAccount($profile->id, $account->external_id))
            ->handle(app(MetaAdsSyncService::class));

        $this->assertDatabaseHas('campaigns', [
            'id' => $campaign->id,
            'status' => 'PAUSED',
            'daily_budget' => 210000,
            'spend' => 45000,
            'result' => 3,
        ]);
        $this->assertFalse($task->fresh()->is_active);
        $this->assertSame(210000, $task->fresh()->current_budget);
        $this->assertSame('meta_sync', $task->fresh()->last_budget_action);
        $this->assertTrue($profile->fresh()->adAccounts->contains($account));
        Http::assertSentCount(5);
    }

    public function test_automation_background_refresh_reads_local_database_only(): void
    {
        $this->seed(TestDataSeeder::class);
        $user = User::firstOrFail();
        $this->actingAs($user);
        T4JamProfile::updateOrCreate(['user_id' => $user->id], ['access_token' => 'token']);
        Http::fake();

        $this->getJson('/get-automation-task/?acc=all&level=all&funnel=all&local=1')
            ->assertOk()
            ->assertJsonPath('meta_sync.reason', 'local_only');

        Http::assertNothingSent();
    }

    public function test_configure_meta_webhook_command_registers_app_and_ad_account(): void
    {
        $this->seed(TestDataSeeder::class);
        $user = User::firstOrFail();
        $account = AdAccount::firstOrFail();
        $profile = T4JamProfile::updateOrCreate(['user_id' => $user->id], [
            'app_id' => 'app-123',
            'app_secret' => 'secret-123',
            'access_token' => 'token-123',
        ]);
        config([
            'services.meta.webhook_verify_token' => 'verify-123',
            'services.meta.webhook_callback_url' => 'https://example.test/meta/webhook/',
            'services.meta.webhook_fields' => ['campaigns', 'adsets', 'ads'],
        ]);
        Http::fake([
            'graph.facebook.com/*/app-123/subscriptions' => Http::response(['success' => true]),
            'graph.facebook.com/*/me/adaccounts?*' => Http::response(['data' => [[
                'account_id' => $account->account_id,
                'id' => $account->external_id,
                'name' => $account->name,
            ]]]),
            'graph.facebook.com/*/me/businesses?*' => Http::response(['data' => []]),
            'graph.facebook.com/*/'.$account->external_id.'/subscribed_apps' => Http::response(['success' => true]),
        ]);

        $this->artisan('t4jam:configure-meta-webhook', ['--profile_id' => $profile->id])
            ->expectsOutput('Webhook Meta aktif untuk 1 ad account.')
            ->assertExitCode(0);

        $this->assertTrue($profile->fresh()->adAccounts->contains($account));
        Http::assertSent(fn ($request) => str_contains($request->url(), '/app-123/subscriptions')
            && $request['object'] === 'ad_account'
            && $request['fields'] === 'campaigns,adsets,ads');
        Http::assertSent(fn ($request) => str_contains($request->url(), '/'.$account->external_id.'/subscribed_apps')
            && $request['app_id'] === 'app-123');
    }

    public function test_configure_meta_webhook_skips_ad_accounts_rejected_by_meta(): void
    {
        Log::spy();
        $this->seed(TestDataSeeder::class);
        $user = User::firstOrFail();
        $allowed = AdAccount::firstOrFail();
        $rejected = AdAccount::create([
            'account_id' => '999',
            'external_id' => 'act_999',
            'name' => 'Rejected Account',
            'currency' => 'IDR',
            'account_status' => 1,
        ]);
        $profile = T4JamProfile::updateOrCreate(['user_id' => $user->id], [
            'app_id' => 'app-123',
            'app_secret' => 'secret-123',
            'access_token' => 'token-123',
        ]);
        config([
            'services.meta.webhook_verify_token' => 'verify-123',
            'services.meta.webhook_callback_url' => 'https://example.test/meta/webhook/',
            'services.meta.webhook_fields' => ['with_issues_ad_objects'],
        ]);
        Http::fake(function ($request) use ($allowed, $rejected) {
            $url = $request->url();

            if (str_contains($url, '/app-123/subscriptions')) {
                return Http::response(['success' => true]);
            }

            if (str_contains($url, '/me/adaccounts')) {
                return Http::response(['data' => [
                    ['account_id' => $allowed->account_id, 'id' => $allowed->external_id, 'name' => $allowed->name],
                    ['account_id' => $rejected->account_id, 'id' => $rejected->external_id, 'name' => $rejected->name],
                ]]);
            }

            if (str_contains($url, '/me/businesses')) {
                return Http::response(['data' => []]);
            }

            if (str_contains($url, '/'.$allowed->external_id.'/subscribed_apps')) {
                return Http::response(['success' => true]);
            }

            if (str_contains($url, '/'.$rejected->external_id.'/subscribed_apps')) {
                return Http::response([
                    'error' => [
                        'message' => 'User does not have permission',
                        'type' => 'OAuthException',
                        'code' => 200,
                    ],
                ], 403);
            }

            return Http::response([], 404);
        });

        $this->artisan('t4jam:configure-meta-webhook', ['--profile_id' => $profile->id])
            ->expectsOutput('Ad account '.$rejected->external_id.' dilewati: Permission Meta tidak mencukupi.')
            ->expectsOutput('Webhook Meta aktif untuk 1 ad account.')
            ->expectsOutput('1 ad account dilewati karena Meta menolak subscribe webhook.')
            ->assertExitCode(0);

        $freshProfile = $profile->fresh();
        $this->assertTrue($freshProfile->adAccounts->contains($allowed));
        $this->assertFalse($freshProfile->adAccounts->contains($rejected));
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context = []) => str_contains($message, 'meta webhook ad account subscription skipped')
            && ($context['ad_account_id'] ?? null) === $rejected->external_id
            && ($context['meta_code'] ?? null) === 200);
    }

    public function test_meta_flow_logs_use_searchable_tag(): void
    {
        Log::spy();
        Queue::fake();
        $this->seed(TestDataSeeder::class);
        $user = User::firstOrFail();
        $this->actingAs($user);
        T4JamProfile::updateOrCreate(['user_id' => $user->id], ['access_token' => 'own-token']);
        $this->postJson('/profile/sync-meta-ads/')->assertOk();
        Log::shouldHaveReceived('info')->withArgs(fn (string $message, array $context = []) => str_starts_with($message, MetaFlowLog::TAG) && str_contains($message, 'manual full sync queued')
            && ($context['user_id'] ?? null) === $user->id && ($context['queue'] ?? null) === 'meta')->once();
    }

    public function test_base_url_does_not_trigger_meta_sync(): void
    {
        Http::fake();

        $this->get('/')
            ->assertRedirect('/dashboard/');

        Http::assertNothingSent();
    }

    public function test_meta_ads_sync_command_persists_data_for_token_profiles(): void
    {
        $this->seed(TestDataSeeder::class);
        $user = User::firstOrFail();
        $profile = T4JamProfile::updateOrCreate(['user_id' => $user->id], ['access_token' => 'token']);

        Http::fake([
            'graph.facebook.com/*/me?*' => Http::response(['id' => 'meta-user-1', 'name' => 'Meta Tester']),
            'graph.facebook.com/*/me/adaccounts?*' => Http::response([
                'data' => [
                    ['account_id' => '321', 'id' => 'act_321', 'name' => 'Scheduled Account', 'currency' => 'IDR', 'account_status' => 1],
                ],
            ]),
            'graph.facebook.com/*/me/businesses?*' => Http::response(['data' => []]),
            'graph.facebook.com/*/act_321/campaigns?*' => Http::response([
                'data' => [
                    ['id' => 'cmp_321', 'name' => 'Scheduled Campaign', 'status' => 'ACTIVE', 'daily_budget' => '250000'],
                ],
            ]),
            'graph.facebook.com/*/cmp_321/adsets?*' => Http::response(['data' => []]),
            'graph.facebook.com/*/act_321/insights?*' => Http::response(['data' => []]),
        ]);

        $this->artisan('t4jam:sync-meta-ads')
            ->expectsOutput("Profile {$profile->id} synced: 1 ad account, 1 campaign, 0 ad set, 0 insight.")
            ->assertExitCode(0);

        $this->assertDatabaseHas('ad_accounts', ['external_id' => 'act_321']);
        $this->assertDatabaseHas('campaigns', ['external_id' => 'cmp_321', 'name' => 'Scheduled Campaign']);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/act_321/insights')
            && $request['level'] === 'campaign');
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/cmp_321/insights'));
    }

    public function test_meta_sync_pauses_campaign_when_active_cpr_cap_is_reached(): void
    {
        $this->seed(TestDataSeeder::class);
        config(['services.meta.enable_writes' => true]);
        $user = User::firstOrFail();
        $this->actingAs($user);
        $profile = T4JamProfile::updateOrCreate(['user_id' => $user->id], ['access_token' => 'token']);
        $task = AutomationTask::with('campaign')->firstOrFail();
        $task->update([
            'cpr_cap' => 20000,
            'pause_when_cpr_loss' => true,
            'is_active' => true,
        ]);

        Http::fake([
            'graph.facebook.com/*/me?*' => Http::response(['id' => 'meta-user-1', 'name' => 'Meta Tester']),
            'graph.facebook.com/*/me/adaccounts?*' => Http::response([
                'data' => [
                    ['account_id' => '321', 'id' => $task->campaign->adAccount->external_id, 'name' => 'Automation Account', 'currency' => 'IDR', 'account_status' => 1],
                ],
            ]),
            'graph.facebook.com/*/me/businesses?*' => Http::response(['data' => []]),
            'graph.facebook.com/*/'.$task->campaign->adAccount->external_id.'/campaigns?*' => Http::response([
                'data' => [
                    ['id' => $task->campaign->external_id, 'name' => $task->campaign->name, 'status' => 'ACTIVE', 'daily_budget' => '75000'],
                ],
            ]),
            'graph.facebook.com/*/'.$task->campaign->external_id.'/adsets?*' => Http::response(['data' => []]),
            'graph.facebook.com/*/'.$task->campaign->adAccount->external_id.'/insights?*' => Http::response([
                'data' => [[
                    'campaign_id' => $task->campaign->external_id,
                    'spend' => '40000',
                    'reach' => '1000',
                    'inline_link_clicks' => '10',
                    'actions' => [['action_type' => 'purchase', 'value' => '1']],
                ]],
            ]),
            'graph.facebook.com/*/'.$task->campaign->external_id => Http::response(['success' => true]),
        ]);

        $this->runMetaSyncJob($profile);

        $task->refresh();
        $campaign = $task->campaign->fresh();

        $this->assertTrue(config('services.meta.enable_writes'));
        $this->assertTrue($task->pause_when_cpr_loss);
        $this->assertSame(40000, $campaign->spend);
        $this->assertSame(1, $campaign->result);
        $this->assertFalse($task->is_active);
        $this->assertSame('PAUSED', $campaign->status);
        $this->assertSame('PAUSED', $campaign->effective_status);
        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_contains($request->url(), $campaign->external_id)
            && $request['status'] === 'PAUSED');
    }

    public function test_automation_enforcement_command_pauses_using_synced_metrics(): void
    {
        $this->seed(TestDataSeeder::class);
        config(['services.meta.enable_writes' => true]);
        $user = User::firstOrFail();
        $profile = T4JamProfile::updateOrCreate(['user_id' => $user->id], ['access_token' => 'token']);
        $task = AutomationTask::with('campaign')->firstOrFail();
        $task->campaign->update(['spend' => 0, 'result' => 0]);
        $task->update([
            'cpr_cap' => 20000,
            'pause_when_cpr_loss' => true,
            'is_active' => true,
            'last_checked_at' => now()->subMinutes(11),
        ]);

        Http::fake([
            'graph.facebook.com/*/'.$task->campaign->adAccount->external_id.'/insights?*' => Http::response([
                'data' => [[
                    'campaign_id' => $task->campaign->external_id,
                    'spend' => '40000',
                    'reach' => '1000',
                    'inline_link_clicks' => '10',
                    'actions' => [['action_type' => 'purchase', 'value' => '1']],
                ]],
            ]),
            'graph.facebook.com/*/'.$task->campaign->external_id => Http::response(['success' => true]),
        ]);

        $this->artisan('t4jam:enforce-automation')
            ->expectsOutput("Profile {$profile->id}: 1 automation campaign dipause.")
            ->assertExitCode(0);

        $this->assertFalse($task->fresh()->is_active);
        $this->assertSame(40000, $task->campaign->fresh()->spend);
        $this->assertSame(1, $task->campaign->fresh()->result);
        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_contains($request->url(), $task->campaign->external_id)
            && $request['status'] === 'PAUSED');
    }

    public function test_automation_enforcement_bulk_fetches_campaign_insights_once_per_account(): void
    {
        $this->seed(TestDataSeeder::class);
        config([
            'services.meta.enable_writes' => false,
            'services.meta.automation_insights_date_preset' => 'last_7d',
        ]);
        $user = User::firstOrFail();
        T4JamProfile::updateOrCreate(['user_id' => $user->id], ['access_token' => 'token']);
        $tasks = AutomationTask::with('campaign.adAccount')->take(2)->get();
        $account = $tasks[0]->campaign->adAccount;

        $tasks->each(function (AutomationTask $task) use ($account, $user): void {
            $task->campaign->update(['ad_account_id' => $account->id]);
            $task->update([
                'user_id' => $user->id,
                'pause_when_cpr_loss' => false,
                'is_active' => true,
                'last_checked_at' => now()->subMinutes(11),
            ]);
        });

        Http::fake([
            'graph.facebook.com/*/'.$account->external_id.'/insights?*' => Http::response([
                'data' => $tasks->map(fn (AutomationTask $task) => [
                    'campaign_id' => $task->campaign->external_id,
                    'spend' => '30000',
                    'actions' => [['action_type' => $task->conversion, 'value' => '3']],
                ])->all(),
            ]),
        ]);

        $this->artisan('t4jam:enforce-automation')->assertExitCode(0);

        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/'.$account->external_id.'/insights')
            && $request['level'] === 'campaign'
            && $request['date_preset'] === 'last_7d');
        $tasks->each(fn (AutomationTask $task) => $this->assertSame(3, $task->fresh()->current_result));
    }

    public function test_cpr_uses_the_task_conversion_instead_of_summing_other_meta_actions(): void
    {
        $this->seed(TestDataSeeder::class);
        config(['services.meta.enable_writes' => true]);
        $user = User::firstOrFail();
        $profile = T4JamProfile::updateOrCreate(['user_id' => $user->id], ['access_token' => 'token']);
        $task = AutomationTask::with('campaign')->firstOrFail();
        $task->update([
            'conversion' => 'initiate_checkout',
            'cpr_cap' => 25000,
            'maximum_budget' => (int) $task->campaign->daily_budget,
            'pause_when_cpr_loss' => true,
            'is_active' => true,
            'last_checked_at' => now()->subMinutes(11),
        ]);

        Http::fake([
            'graph.facebook.com/*/'.$task->campaign->adAccount->external_id.'/insights?*' => Http::response([
                'data' => [[
                    'campaign_id' => $task->campaign->external_id,
                    'spend' => '40000',
                    'actions' => [
                        ['action_type' => 'purchase', 'value' => '1'],
                        ['action_type' => 'add_to_cart', 'value' => '4'],
                        ['action_type' => 'initiate_checkout', 'value' => '2'],
                    ],
                    'cost_per_action_type' => [
                        ['action_type' => 'initiate_checkout', 'value' => '30000'],
                    ],
                ]],
            ]),
            'graph.facebook.com/*/'.$task->campaign->external_id => Http::response(['success' => true]),
        ]);

        $this->artisan('t4jam:enforce-automation')->assertExitCode(0);

        $this->assertTrue($task->fresh()->is_active);
        $this->assertSame(2, $task->fresh()->current_result);
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
    }

    public function test_failed_meta_insight_does_not_advance_automation_check_time(): void
    {
        $this->seed(TestDataSeeder::class);
        config(['services.meta.enable_writes' => true]);
        $user = User::firstOrFail();
        $profile = T4JamProfile::updateOrCreate(['user_id' => $user->id], ['access_token' => 'token']);
        $task = AutomationTask::with('campaign')->firstOrFail();
        $checkedAt = now()->subMinutes(11)->startOfSecond();
        $task->update([
            'is_active' => true,
            'pause_when_cpr_loss' => true,
            'last_checked_at' => $checkedAt,
        ]);

        Http::fake([
            'graph.facebook.com/*/'.$task->campaign->adAccount->external_id.'/insights?*' => Http::response([
                'error' => ['message' => 'Token tidak memiliki akses campaign', 'code' => 100],
            ], 400),
        ]);

        $this->artisan('t4jam:enforce-automation')->assertExitCode(0);

        $this->assertTrue($task->fresh()->last_checked_at->equalTo($checkedAt));
        $this->assertTrue($task->fresh()->is_active);
    }

    public function test_automation_scales_budget_by_fifteen_percent_when_cpr_is_under_cap(): void
    {
        $this->seed(TestDataSeeder::class);
        config(['services.meta.enable_writes' => true]);
        $user = User::firstOrFail();
        $profile = T4JamProfile::updateOrCreate(['user_id' => $user->id], ['access_token' => 'token']);
        $task = AutomationTask::with('campaign')->firstOrFail();
        AutomationTask::query()->where('id', '!=', $task->id)->update(['is_active' => false]);
        $task->campaign->update(['daily_budget' => 100000]);
        $task->update([
            'conversion' => 'purchase',
            'cpr_cap' => 25000,
            'maximum_budget' => 150000,
            'pause_when_cpr_loss' => true,
            'is_active' => true,
            'last_checked_at' => now()->subMinutes(11),
            'last_budget_changed_at' => null,
        ]);

        Http::fake([
            'graph.facebook.com/*/'.$task->campaign->adAccount->external_id.'/insights?*' => Http::response([
                'data' => [[
                    'campaign_id' => $task->campaign->external_id,
                    'spend' => '31929',
                    'actions' => [['action_type' => 'purchase', 'value' => '2']],
                    'cost_per_action_type' => [['action_type' => 'purchase', 'value' => '15965']],
                ]],
            ]),
            'graph.facebook.com/*/'.$task->campaign->external_id => Http::response(['success' => true]),
        ]);

        $this->artisan('t4jam:enforce-automation')
            ->expectsOutput("Profile {$profile->id}: 0 automation campaign dipause.")
            ->assertExitCode(0);

        $this->assertSame(115000, $task->campaign->fresh()->daily_budget);
        $this->assertSame(115000, $task->fresh()->current_budget);
        $this->assertSame('increase', $task->fresh()->last_budget_action);
        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_contains($request->url(), $task->campaign->external_id)
            && ($request->data()['daily_budget'] ?? null) === 115000);
    }

    public function test_counter_cpr_resumes_only_an_automation_paused_campaign(): void
    {
        $this->seed(TestDataSeeder::class);
        config(['services.meta.enable_writes' => true]);
        $user = User::firstOrFail();
        $profile = T4JamProfile::updateOrCreate(['user_id' => $user->id], ['access_token' => 'token']);
        $task = AutomationTask::with('campaign')->firstOrFail();
        AutomationTask::query()->where('id', '!=', $task->id)->update(['is_active' => false]);
        $task->campaign->update(['status' => 'PAUSED', 'effective_status' => 'PAUSED']);
        $task->update([
            'conversion' => 'purchase',
            'pause_cpr_cap' => 20000,
            'counter_cpr' => true,
            'is_active' => false,
            'last_budget_action' => 'pause',
            'last_checked_at' => now()->subMinutes(11),
        ]);

        Http::fake([
            'graph.facebook.com/*/'.$task->campaign->adAccount->external_id.'/insights?*' => Http::response([
                'data' => [[
                    'campaign_id' => $task->campaign->external_id,
                    'spend' => '10000',
                    'actions' => [['action_type' => 'purchase', 'value' => '1']],
                    'cost_per_action_type' => [['action_type' => 'purchase', 'value' => '10000']],
                ]],
            ]),
            'graph.facebook.com/*/'.$task->campaign->external_id => Http::response(['success' => true]),
        ]);

        $this->artisan('t4jam:enforce-automation')->assertExitCode(0);

        $this->assertTrue($task->fresh()->is_active);
        $this->assertSame('ACTIVE', $task->campaign->fresh()->status);
        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_contains($request->url(), $task->campaign->external_id)
            && ($request->data()['status'] ?? null) === 'ACTIVE');
    }

    public function test_on_off_window_pauses_and_resumes_campaign_on_schedule(): void
    {
        $this->seed(TestDataSeeder::class);
        config(['services.meta.enable_writes' => true]);
        $user = User::firstOrFail();
        $profile = T4JamProfile::updateOrCreate(['user_id' => $user->id], ['access_token' => 'token']);
        $task = AutomationTask::with('campaign')->firstOrFail();
        AutomationTask::query()->where('id', '!=', $task->id)->update(['is_active' => false]);
        $outsideStart = now('Asia/Jakarta')->addHour()->format('H:i');
        $outsideEnd = now('Asia/Jakarta')->addHours(2)->format('H:i');
        $task->update([
            'use_on_off' => true,
            'on_time' => $outsideStart,
            'off_time' => $outsideEnd,
            'last_checked_at' => now()->subMinutes(11),
        ]);

        Http::fake([
            'graph.facebook.com/*/'.$task->campaign->external_id => Http::response(['success' => true]),
        ]);

        $this->artisan('t4jam:enforce-automation')->assertExitCode(0);

        $this->assertFalse($task->fresh()->is_active);
        $this->assertSame('schedule_pause', $task->fresh()->last_budget_action);
        $this->assertSame('PAUSED', $task->campaign->fresh()->status);
        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_contains($request->url(), $task->campaign->external_id)
            && $request['status'] === 'PAUSED');

        $this->travel(11)->minutes();
        $task->refresh()->update([
            'on_time' => now('Asia/Jakarta')->subHour()->format('H:i'),
            'off_time' => now('Asia/Jakarta')->addHour()->format('H:i'),
        ]);

        Http::fake([
            'graph.facebook.com/*/'.$task->campaign->external_id => Http::response(['success' => true]),
            'graph.facebook.com/*/'.$task->campaign->adAccount->external_id.'/insights?*' => Http::response([
                'data' => [[
                    'campaign_id' => $task->campaign->external_id,
                    'spend' => '10000',
                    'actions' => [['action_type' => $task->conversion, 'value' => '1']],
                ]],
            ]),
        ]);

        $this->artisan('t4jam:enforce-automation')->assertExitCode(0);

        $this->assertTrue($task->fresh()->is_active);
        $this->assertSame('ACTIVE', $task->campaign->fresh()->status);
        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_contains($request->url(), $task->campaign->external_id)
            && ($request->data()['status'] ?? null) === 'ACTIVE');
    }

    public function test_meta_conversion_metric_is_synced_even_when_pause_action_is_off(): void
    {
        $this->seed(TestDataSeeder::class);
        config(['services.meta.enable_writes' => true]);
        $user = User::firstOrFail();
        $profile = T4JamProfile::updateOrCreate(['user_id' => $user->id], ['access_token' => 'token']);
        $task = AutomationTask::with('campaign')->firstOrFail();
        $task->update([
            'conversion' => 'add_to_cart',
            'pause_when_cpr_loss' => false,
            'is_active' => true,
            'last_checked_at' => now()->subMinutes(11),
        ]);

        Http::fake([
            'graph.facebook.com/*/'.$task->campaign->adAccount->external_id.'/insights?*' => Http::response([
                'data' => [[
                    'campaign_id' => $task->campaign->external_id,
                    'spend' => '40000',
                    'actions' => [
                        ['action_type' => 'purchase', 'value' => '1'],
                        ['action_type' => 'add_to_cart', 'value' => '4'],
                    ],
                ]],
            ]),
        ]);

        $this->artisan('t4jam:enforce-automation')->assertExitCode(0);

        $freshTask = $task->fresh();
        $this->assertTrue($freshTask->is_active);
        $this->assertSame(4, $freshTask->current_result);
        Http::assertSentCount(1);
    }

    public function test_unchecked_automation_activation_disables_task_on_update(): void
    {
        $this->seed(TestDataSeeder::class);
        config(['services.meta.enable_writes' => true]);
        $user = User::firstOrFail();
        $this->actingAs($user);
        T4JamProfile::updateOrCreate(['user_id' => $user->id], ['access_token' => 'token']);
        $task = AutomationTask::with('campaign')->firstOrFail();
        $task->update(['is_active' => true]);
        Http::fake(['graph.facebook.com/*/'.$task->campaign->external_id => Http::response(['success' => true])]);

        $this->postJson('/update-automation-tasks/', [
            'automation_id' => $task->id,
            'starting_budget' => 100000,
            'cpr_cap' => 25000,
            'period' => 10,
        ])->assertOk();

        $this->assertFalse($task->fresh()->is_active);
    }

    public function test_meta_ads_sync_job_includes_business_manager_accounts(): void
    {
        $this->seed(TestDataSeeder::class);
        $user = User::firstOrFail();
        $this->actingAs($user);

        $profile = T4JamProfile::updateOrCreate(['user_id' => $user->id], ['access_token' => 'token']);

        Http::fake([
            'graph.facebook.com/*/me?*' => Http::response(['id' => 'meta-user-1', 'name' => 'Meta Tester']),
            'graph.facebook.com/*/me/adaccounts?*' => Http::response([
                'data' => [
                    ['account_id' => '654', 'id' => 'act_654', 'name' => 'Personal Account', 'currency' => 'IDR', 'account_status' => 1],
                ],
            ]),
            'graph.facebook.com/*/me/businesses?*' => Http::response([
                'data' => [
                    ['id' => 'biz_1', 'name' => 'Business 1'],
                ],
            ]),
            'graph.facebook.com/*/biz_1/owned_ad_accounts?*' => Http::response([
                'data' => [
                    ['account_id' => '777', 'id' => 'act_777', 'name' => 'Owned Business Account', 'currency' => 'IDR', 'account_status' => 1],
                ],
            ]),
            'graph.facebook.com/*/biz_1/client_ad_accounts?*' => Http::response([
                'data' => [
                    ['account_id' => '888', 'id' => 'act_888', 'name' => 'Client Business Account', 'currency' => 'IDR', 'account_status' => 1],
                    ['account_id' => '777', 'id' => 'act_777', 'name' => 'Owned Business Account Duplicate', 'currency' => 'IDR', 'account_status' => 1],
                ],
            ]),
            'graph.facebook.com/*/act_654/campaigns?*' => Http::response(['data' => []]),
            'graph.facebook.com/*/act_777/campaigns?*' => Http::response(['data' => []]),
            'graph.facebook.com/*/act_888/campaigns?*' => Http::response(['data' => []]),
        ]);

        $this->runMetaSyncJob($profile);

        $this->assertDatabaseHas('ad_accounts', ['external_id' => 'act_654', 'name' => 'Personal Account']);
        $this->assertDatabaseHas('ad_accounts', ['external_id' => 'act_777', 'name' => 'Owned Business Account']);
        $this->assertDatabaseHas('ad_accounts', ['external_id' => 'act_888', 'name' => 'Client Business Account']);
    }

    public function test_meta_ads_sync_stores_provider_error_when_insights_hit_rate_limit(): void
    {
        Queue::fake();
        $this->seed(TestDataSeeder::class);
        $user = User::firstOrFail();
        $this->actingAs($user);

        $profile = T4JamProfile::updateOrCreate(['user_id' => $user->id], ['access_token' => 'token']);

        Http::fake([
            'graph.facebook.com/*/me?*' => Http::response(['id' => 'meta-user-1', 'name' => 'Meta Tester']),
            'graph.facebook.com/*/me/adaccounts?*' => Http::response([
                'data' => [
                    ['account_id' => '789', 'id' => 'act_789', 'name' => 'Rate Limited Account', 'currency' => 'IDR', 'account_status' => 1],
                ],
            ]),
            'graph.facebook.com/*/me/businesses?*' => Http::response(['data' => []]),
            'graph.facebook.com/*/act_789/campaigns?*' => Http::response([
                'data' => [
                    ['id' => 'cmp_789', 'name' => 'Rate Limited Campaign', 'status' => 'ACTIVE', 'daily_budget' => '300000'],
                ],
            ]),
            'graph.facebook.com/*/cmp_789/adsets?*' => Http::response(['data' => []]),
            'graph.facebook.com/*/act_789/insights?*' => Http::response([
                'error' => [
                    'message' => 'User request limit reached',
                    'code' => 17,
                    'type' => 'OAuthException',
                ],
            ], 400),
        ]);

        $this->postJson('/profile/sync-meta-ads/')
            ->assertOk()
            ->assertJsonPath('status', 200)
            ->assertJsonPath('text', 'Sync Meta Ads masuk antrean queue.');
        Queue::assertPushed(SyncMetaAdsProfile::class, fn (SyncMetaAdsProfile $job) => $job->queue === 'meta');

        $this->runMetaSyncJob($profile);

        $this->assertDatabaseHas('ad_accounts', ['external_id' => 'act_789']);
        $this->assertDatabaseHas('campaigns', ['external_id' => 'cmp_789', 'name' => 'Rate Limited Campaign']);
        $this->assertDatabaseHas('t4jam_profiles', [
            'user_id' => $user->id,
            'last_meta_error' => 'Meta rate limit tercapai. Coba lagi setelah jeda.',
        ]);
    }

    public function test_meta_write_status_uses_graph_api_when_enabled(): void
    {
        $this->seed(TestDataSeeder::class);
        config(['services.meta.enable_writes' => true]);
        $user = User::firstOrFail();
        $this->actingAs($user);

        T4JamProfile::updateOrCreate(['user_id' => $user->id], ['access_token' => 'token']);
        $campaign = Campaign::firstOrFail();
        $task = AutomationTask::firstOrFail();
        $task->update(['campaign_external_id' => $campaign->external_id]);

        Http::fake(['graph.facebook.com/*/'.$campaign->external_id => Http::response(['success' => true])]);

        $this->postJson('/update-status-automation-tasks/', [
            'automation_id' => $task->id,
            'status' => 'false',
        ])->assertOk();

        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_contains($request->url(), $campaign->external_id)
            && $request['status'] === 'PAUSED');
    }

    public function test_ad_setup_draft_can_be_saved(): void
    {
        $this->seed(TestDataSeeder::class);
        $user = User::firstOrFail();
        $this->actingAs($user);

        $accountId = AdAccount::firstOrFail()->id;

        $this->post('/setup-iklan/', $this->adSetupPayload($accountId, ['publish' => 0]))
            ->assertRedirect('/setup-iklan/');

        $this->assertDatabaseHas('ad_setups', [
            'name' => 'Setup Test',
            'status' => 'draft',
            'daily_budget' => 125000,
        ]);
    }

    public function test_ad_setup_status_endpoint_returns_current_owner_rows(): void
    {
        $this->seed(TestDataSeeder::class);
        $user = User::firstOrFail();
        $this->actingAs($user);

        $accountId = AdAccount::firstOrFail()->id;
        $this->postJson('/setup-iklan/', $this->adSetupPayload($accountId, ['publish' => 0]))
            ->assertOk()
            ->assertJsonPath('setup.status', 'draft');

        $this->getJson('/setup-iklan/status/')
            ->assertOk()
            ->assertJsonPath('total_setup', 1)
            ->assertJsonPath('setups.0.name', 'Setup Test')
            ->assertJsonPath('setups.0.status', 'draft')
            ->assertJsonPath('setups.0.ad_account', AdAccount::firstOrFail()->name);
    }

    public function test_ad_setup_ajax_publish_queues_without_waiting_for_meta_publish(): void
    {
        Queue::fake();
        $this->seed(TestDataSeeder::class);
        config(['services.meta.enable_writes' => true]);
        $user = User::firstOrFail();
        $this->actingAs($user);
        T4JamProfile::updateOrCreate(['user_id' => $user->id], ['access_token' => 'token']);

        $account = AdAccount::firstOrFail();

        $this->postJson('/setup-iklan/', $this->adSetupPayload($account->id, ['publish' => 1]))
            ->assertOk()
            ->assertJsonPath('text', 'Setup iklan diproses di background.')
            ->assertJsonPath('setup.status', 'publishing');

        Queue::assertPushed(PublishMetaAdSetup::class, fn (PublishMetaAdSetup $job) => $job->queue === 'meta');
        $this->assertSame('publishing', AdSetup::where('name', 'Setup Test')->firstOrFail()->status);
    }

    public function test_ad_setup_publish_creates_meta_campaign_adset_creative_and_ad(): void
    {
        $this->seed(TestDataSeeder::class);
        config(['services.meta.enable_writes' => true]);
        $user = User::firstOrFail();
        $this->actingAs($user);
        T4JamProfile::updateOrCreate(['user_id' => $user->id], ['access_token' => 'token']);

        $account = AdAccount::firstOrFail();

        Http::fake([
            'graph.facebook.com/*/'.$account->external_id.'/campaigns' => Http::response(['id' => 'meta-campaign']),
            'graph.facebook.com/*/'.$account->external_id.'/adsets' => Http::response(['id' => 'meta-adset']),
            'graph.facebook.com/*/'.$account->external_id.'/adcreatives' => Http::response(['id' => 'meta-creative']),
            'graph.facebook.com/*/'.$account->external_id.'/ads' => Http::response(['id' => 'meta-ad']),
        ]);

        $this->post('/setup-iklan/', $this->adSetupPayload($account->id, ['publish' => 1]))
            ->assertRedirect('/setup-iklan/');

        $this->assertDatabaseHas('ad_setups', [
            'name' => 'Setup Test',
            'status' => 'published',
            'meta_campaign_id' => 'meta-campaign',
            'meta_adset_id' => 'meta-adset',
            'meta_creative_id' => 'meta-creative',
            'meta_ad_id' => 'meta-ad',
        ]);
        $this->assertDatabaseHas('campaigns', [
            'external_id' => 'meta-campaign',
            'name' => 'Campaign Test',
            'daily_budget' => 125000,
        ]);
        $this->assertDatabaseHas('ad_sets', [
            'external_id' => 'meta-adset',
            'name' => 'Ad Set Test',
            'daily_budget' => 125000,
        ]);
    }

    public function test_ad_setup_publish_without_write_mode_marks_ready_without_error(): void
    {
        $this->seed(TestDataSeeder::class);
        config(['services.meta.enable_writes' => false]);
        $user = User::firstOrFail();
        $this->actingAs($user);

        $account = AdAccount::firstOrFail();

        $this->post('/setup-iklan/', $this->adSetupPayload($account->id, ['publish' => 1]))
            ->assertRedirect('/setup-iklan/')
            ->assertSessionHas('warning', 'Setup iklan sudah siap. Publish ke Meta belum dijalankan karena write mode belum aktif.')
            ->assertSessionMissing('status');

        $setup = AdSetup::where('name', 'Setup Test')->firstOrFail();

        $this->assertSame('ready', $setup->status);
        $this->assertNull($setup->last_error);
    }

    public function test_ad_setup_publish_failure_stores_user_friendly_error(): void
    {
        $this->seed(TestDataSeeder::class);
        config(['services.meta.enable_writes' => true]);
        $user = User::firstOrFail();
        $this->actingAs($user);
        T4JamProfile::updateOrCreate(['user_id' => $user->id], ['access_token' => 'token']);

        $account = AdAccount::firstOrFail();

        Http::fake([
            'graph.facebook.com/*/'.$account->external_id.'/campaigns' => Http::response([
                'error' => [
                    'message' => 'Invalid parameter: debug provider payload details.',
                    'code' => 100,
                    'type' => 'OAuthException',
                ],
            ], 400),
        ]);

        $this->post('/setup-iklan/', $this->adSetupPayload($account->id, ['publish' => 1]))
            ->assertRedirect('/setup-iklan/')
            ->assertSessionHas('status', 'Setup iklan masuk antrean queue. Worker akan publish ke Meta di background.');

        $setup = AdSetup::where('name', 'Setup Test')->firstOrFail();

        $this->assertSame('failed', $setup->status);
        $this->assertSame('Meta menolak data setup iklan. Cek Page ID, targeting, budget, dan URL landing page.', $setup->last_error);
    }

    public function test_ad_setup_publish_dispatches_queue_job(): void
    {
        Queue::fake();
        $this->seed(TestDataSeeder::class);
        config(['services.meta.enable_writes' => true]);
        $user = User::firstOrFail();
        $this->actingAs($user);
        T4JamProfile::updateOrCreate(['user_id' => $user->id], ['access_token' => 'token']);

        $account = AdAccount::firstOrFail();

        $this->post('/setup-iklan/', $this->adSetupPayload($account->id, ['publish' => 1]))
            ->assertRedirect('/setup-iklan/')
            ->assertSessionHas('status', 'Setup iklan masuk antrean queue. Worker akan publish ke Meta di background.');

        Queue::assertPushed(PublishMetaAdSetup::class, fn (PublishMetaAdSetup $job) => $job->queue === 'meta');
        $this->assertSame('publishing', AdSetup::where('name', 'Setup Test')->firstOrFail()->status);
    }

    public function test_ad_setup_publish_rejects_another_users_token(): void
    {
        Queue::fake();
        $this->seed(TestDataSeeder::class);
        config(['services.meta.enable_writes' => true]);
        T4JamProfile::where('user_id', User::firstOrFail()->id)->firstOrFail()->update(['access_token' => 'shared-token']);
        $this->actingAs(User::factory()->create());
        $this->post('/setup-iklan/', $this->adSetupPayload(AdAccount::firstOrFail()->id, ['publish' => 1]))
            ->assertRedirect('/setup-iklan/')->assertSessionHasErrors('meta');
        Queue::assertNothingPushed();
    }

    private function runMetaSyncJob(T4JamProfile $profile): void
    {
        (new SyncMetaAdsProfile($profile->id))->handle(app(MetaAdsSyncService::class));
    }

    private function adSetupPayload(int $accountId, array $overrides = []): array
    {
        return $overrides + [
            'ad_account_id' => $accountId,
            'name' => 'Setup Test',
            'campaign_name' => 'Campaign Test',
            'campaign_objective' => 'OUTCOME_SALES',
            'campaign_status' => 'PAUSED',
            'adset_name' => 'Ad Set Test',
            'daily_budget' => 125000,
            'billing_event' => 'IMPRESSIONS',
            'optimization_goal' => 'OFFSITE_CONVERSIONS',
            'bid_strategy' => 'LOWEST_COST_WITHOUT_CAP',
            'countries' => 'ID',
            'age_min' => 18,
            'age_max' => 55,
            'interests' => '6003348453981|Sepatu',
            'page_id' => '123456',
            'ad_name' => 'Ad Test',
            'creative_name' => 'Creative Test',
            'message' => 'Primary text iklan.',
            'headline' => 'Headline iklan',
            'description' => 'Description iklan',
            'link_url' => 'https://example.com',
            'call_to_action' => 'LEARN_MORE',
        ];
    }
}
