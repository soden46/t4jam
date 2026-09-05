<?php

namespace Tests\Feature;

use App\Jobs\PublishMetaAdSetup;
use App\Jobs\SyncMetaAdsProfile;
use App\Models\AdAccount;
use App\Models\AdSet;
use App\Models\AdSetup;
use App\Models\AutomationTask;
use App\Models\Campaign;
use App\Models\T4JamProfile;
use App\Models\User;
use App\Services\MetaAdsSyncService;
use App\Support\MetaFlowLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
            && $request['daily_budget'] === 125000);
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

    public function test_dashboard_reload_syncs_only_selected_account_campaigns(): void
    {
        $this->seed(TestDataSeeder::class);
        $user = User::firstOrFail();
        $this->actingAs($user);

        T4JamProfile::updateOrCreate(
            ['user_id' => $user->id],
            ['access_token' => 'token']
        );

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

        // Membuktikan Reload tidak melakukan full sync, adset lookup, atau insights.
        Http::assertSentCount(2);
    }

    public function test_dashboard_reload_uses_existing_meta_token_when_current_user_has_none(): void
    {
        $this->seed(TestDataSeeder::class);
        $tokenOwner = User::firstOrFail();
        $operator = User::factory()->create();
        $this->actingAs($operator);
        T4JamProfile::updateOrCreate(['user_id' => $tokenOwner->id], ['access_token' => 'shared-token']);

        Http::fake([
            'graph.facebook.com/*/act_902?*' => Http::response([
                'account_id' => '902',
                'id' => 'act_902',
                'name' => 'Shared Token Account',
                'currency' => 'IDR',
                'account_status' => 1,
            ]),
            'graph.facebook.com/*/act_902/campaigns?*' => Http::response([
                'data' => [
                    [
                        'id' => 'cmp_902',
                        'name' => 'Shared Token Campaign',
                        'status' => 'ACTIVE',
                        'effective_status' => 'ACTIVE',
                        'daily_budget' => '250000',
                    ],
                ],
            ]),
        ]);

        $this->postJson('/api/reload-ad-account/', [
            'ad_account' => 'act_902',
        ])->assertOk()
            ->assertJsonPath('text', 'Reload selesai. 1 campaign diperbarui.');

        $this->assertDatabaseHas('campaigns', ['external_id' => 'cmp_902']);
        Http::assertSent(fn ($request) => $request['access_token'] === 'shared-token');
        Http::assertSentCount(2);
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
            && $request['daily_budget'] === 1500000);
    }

    public function test_update_automation_uses_existing_meta_token_when_current_user_has_none(): void
    {
        $this->seed(TestDataSeeder::class);
        config(['services.meta.enable_writes' => true]);
        $tokenOwner = User::firstOrFail();
        $operator = User::factory()->create();
        $this->actingAs($operator);
        T4JamProfile::updateOrCreate(['user_id' => $tokenOwner->id], ['access_token' => 'shared-token']);

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

        $this->assertDatabaseHas('campaigns', ['id' => $task->campaign_id, 'daily_budget' => 1500000]);
        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_contains($request->url(), $task->campaign->external_id)
            && $request['access_token'] === 'shared-token'
            && $request['daily_budget'] === 1500000);
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

    public function test_automation_budget_metrics_follow_synced_campaign_data(): void
    {
        $this->seed(TestDataSeeder::class);
        $user = User::firstOrFail();
        $this->actingAs($user);

        $task = AutomationTask::with(['adAccount', 'campaign'])->firstOrFail();
        $task->campaign->update([
            'daily_budget' => 55000,
            'spend' => 35372,
            'result' => 1,
        ]);
        $task->update([
            'current_budget' => 55000,
            'current_spend' => 0,
            'current_result' => 0,
        ]);

        $row = collect($this
            ->getJson('/get-automation-task/?acc=all&level=all&funnel=all')
            ->assertOk()
            ->json('data'))
            ->firstWhere('id', $task->id);

        $this->assertSame(55000, $row['current_budget']);
        $this->assertSame(35372, $row['current_spend']);
        $this->assertSame(1, $row['current_hasil']);
        $this->assertSame(35372, $row['current_cpr']);
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
            && $request['daily_budget'] === 20000);
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
            && $request['daily_budget'] === 999988);
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
            && $request['daily_budget'] === 200000);
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
            'graph.facebook.com/*/cmp_1/insights?*' => Http::response([
                'data' => [[
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
            ->assertSessionHas('status', 'Access token valid. Sync Meta Ads masuk antrean queue.');

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
            'graph.facebook.com/*/cmp_456/insights?*' => Http::response(['data' => []]),
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

    public function test_meta_flow_logs_use_searchable_tag(): void
    {
        Log::spy();
        Queue::fake();
        $this->seed(TestDataSeeder::class);
        $tokenOwner = User::firstOrFail();
        $operator = User::factory()->create();
        $this->actingAs($operator);
        T4JamProfile::updateOrCreate(['user_id' => $tokenOwner->id], ['access_token' => 'shared-token']);

        $this->postJson('/profile/sync-meta-ads/')
            ->assertOk()
            ->assertJsonPath('text', 'Sync Meta Ads masuk antrean queue.');

        Log::shouldHaveReceived('info')
            ->withArgs(fn (string $message, array $context = []) => str_starts_with($message, MetaFlowLog::TAG)
                && str_contains($message, 'meta credential fallback selected')
                && ($context['user_id'] ?? null) === $operator->id)
            ->once();
        Log::shouldHaveReceived('info')
            ->withArgs(fn (string $message, array $context = []) => str_starts_with($message, MetaFlowLog::TAG)
                && str_contains($message, 'manual full sync queued')
                && ($context['user_id'] ?? null) === $operator->id
                && ($context['queue'] ?? null) === 'meta')
            ->once();
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
            'graph.facebook.com/*/cmp_321/insights?*' => Http::response(['data' => []]),
        ]);

        $this->artisan('t4jam:sync-meta-ads')
            ->expectsOutput("Profile {$profile->id} synced: 1 ad account, 1 campaign, 0 ad set, 0 insight.")
            ->assertExitCode(0);

        $this->assertDatabaseHas('ad_accounts', ['external_id' => 'act_321']);
        $this->assertDatabaseHas('campaigns', ['external_id' => 'cmp_321', 'name' => 'Scheduled Campaign']);
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
            'graph.facebook.com/*/cmp_789/insights?*' => Http::response([
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
            'last_meta_error' => 'User request limit reached',
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

    public function test_ad_setup_publish_uses_existing_meta_token_when_current_user_has_none(): void
    {
        Queue::fake();
        $this->seed(TestDataSeeder::class);
        config(['services.meta.enable_writes' => true]);
        $tokenOwner = User::firstOrFail();
        $operator = User::factory()->create();
        $this->actingAs($operator);
        T4JamProfile::updateOrCreate(['user_id' => $tokenOwner->id], ['access_token' => 'shared-token']);

        $account = AdAccount::firstOrFail();

        $this->post('/setup-iklan/', $this->adSetupPayload($account->id, ['publish' => 1]))
            ->assertRedirect('/setup-iklan/')
            ->assertSessionHas('status', 'Setup iklan masuk antrean queue. Worker akan publish ke Meta di background.');

        $this->assertDatabaseHas('ad_setups', [
            'user_id' => $operator->id,
            'name' => 'Setup Test',
            'status' => 'publishing',
        ]);
        Queue::assertPushed(PublishMetaAdSetup::class, fn (PublishMetaAdSetup $job) => $job->queue === 'meta');
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
