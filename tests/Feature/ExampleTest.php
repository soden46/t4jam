<?php

namespace Tests\Feature;

use App\Jobs\PublishMetaAdSetup;
use App\Jobs\PushMetaAutomationTaskUpdate;
use App\Models\AdAccount;
use App\Models\AdSet;
use App\Models\AdSetup;
use App\Models\AutomationTask;
use App\Models\Campaign;
use App\Models\T4JamProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
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

    public function test_dashboard_reload_syncs_meta_accounts_immediately(): void
    {
        $this->seed(TestDataSeeder::class);
        $user = User::firstOrFail();
        $this->actingAs($user);

        T4JamProfile::updateOrCreate(['user_id' => $user->id], ['access_token' => 'token']);

        Http::fake([
            'graph.facebook.com/*/me?*' => Http::response(['id' => 'meta-user-1', 'name' => 'Meta Tester']),
            'graph.facebook.com/*/me/adaccounts?*' => Http::response([
                'data' => [
                    ['account_id' => '901', 'id' => 'act_901', 'name' => 'Reloaded Account', 'currency' => 'IDR', 'account_status' => 1],
                ],
            ]),
            'graph.facebook.com/*/me/businesses?*' => Http::response(['data' => []]),
            'graph.facebook.com/*/act_901/campaigns?*' => Http::response(['data' => []]),
        ]);

        $this->postJson('/api/reload-ad-account/')
            ->assertOk()
            ->assertJsonPath('status', 200)
            ->assertJsonPath('text', 'Ad account berhasil direload')
            ->assertJsonPath('ad_account_count', 3);

        $this->assertDatabaseHas('ad_accounts', ['external_id' => 'act_901', 'name' => 'Reloaded Account']);
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
        Http::fake(['graph.facebook.com/*/'.$task->campaign_external_id => Http::response(['success' => true])]);

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
            ->assertJsonPath('text', 'Automation strategy berhasil diupdate. Update budget Meta masuk antrean queue.');

        $task->refresh();

        $this->assertSame(1500000, $task->current_budget);
        $this->assertDatabaseHas('campaigns', ['id' => $task->campaign_id, 'daily_budget' => 1500000]);
        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_contains($request->url(), $task->campaign_external_id)
            && $request['daily_budget'] === 1500000);
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
            ->assertJsonPath('text', 'Automation strategy berhasil diupdate. Update budget Meta masuk antrean queue.');

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

    public function test_update_automation_task_dispatches_meta_queue_job(): void
    {
        Queue::fake();
        $this->seed(TestDataSeeder::class);
        config(['services.meta.enable_writes' => true]);
        $user = User::firstOrFail();
        $this->actingAs($user);
        T4JamProfile::updateOrCreate(['user_id' => $user->id], ['access_token' => 'token']);

        $task = AutomationTask::firstOrFail();

        $this->postJson('/update-automation-tasks/', [
            'automation_id' => $task->id,
            'starting_budget' => 200000,
        ])->assertOk()
            ->assertJsonPath('text', 'Automation strategy berhasil diupdate. Update budget Meta masuk antrean queue.');

        Queue::assertPushed(PushMetaAutomationTaskUpdate::class, fn (PushMetaAutomationTaskUpdate $job) => $job->queue === 'meta');
        $this->assertSame(200000, $task->fresh()->current_budget);
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
            'graph.facebook.com/*/cmp_1/adsets?*' => Http::response([
                'data' => [
                    ['id' => 'adset_1', 'name' => 'Meta Ad Set', 'status' => 'ACTIVE', 'effective_status' => 'ACTIVE', 'daily_budget' => '150000'],
                ],
            ]),
            'graph.facebook.com/*/adset_1/insights?*' => Http::response([
                'data' => [[
                    'spend' => '12000',
                    'reach' => '900',
                    'inline_link_clicks' => '30',
                    'actions' => [
                        ['action_type' => 'purchase', 'value' => '1'],
                        ['action_type' => 'landing_page_view', 'value' => '24'],
                    ],
                ]],
            ]),
        ]);

        $this->get('/profile/')->assertOk();

        $this->post('/profile/access-token/', [
            'id_aplikasi' => 'app-id',
            'kunci_rahasia' => 'secret',
            'access_token_app' => 'token',
        ])->assertRedirect('/profile/');

        $this->from('/profile/')
            ->post('/profile/sync-meta-ads/')
            ->assertRedirect('/profile/')
            ->assertSessionHas('status', 'Sync Meta Ads sedang diproses. Refresh halaman beberapa saat lagi untuk melihat hasil terbaru.');

        $this->assertDatabaseHas('ad_accounts', ['external_id' => 'act_123', 'name' => 'Meta Account']);
        $this->assertDatabaseHas('campaigns', ['external_id' => 'cmp_1', 'spend' => 45000, 'result' => 3, 'landing_page_view' => 90]);
        $this->assertDatabaseHas('ad_sets', ['external_id' => 'adset_1', 'daily_budget' => 150000, 'spend' => 12000]);
        $this->assertDatabaseHas('t4jam_profiles', ['user_id' => $user->id, 'meta_user_name' => 'Meta Tester', 'last_meta_error' => null]);
    }

    public function test_meta_ads_sync_includes_business_owned_and_client_ad_accounts(): void
    {
        $this->seed(TestDataSeeder::class);
        $user = User::firstOrFail();
        $this->actingAs($user);

        T4JamProfile::updateOrCreate(['user_id' => $user->id], ['access_token' => 'token']);

        Http::fake([
            'graph.facebook.com/*/me?*' => Http::response(['id' => 'meta-user-1', 'name' => 'Meta Tester']),
            'graph.facebook.com/*/me/adaccounts?*' => Http::response([
                'data' => [
                    ['account_id' => '111', 'id' => 'act_111', 'name' => 'Personal Account', 'currency' => 'IDR', 'account_status' => 1],
                ],
            ]),
            'graph.facebook.com/*/me/businesses?*' => Http::response([
                'data' => [
                    ['id' => 'business_1', 'name' => 'Prosesin ID'],
                ],
            ]),
            'graph.facebook.com/*/business_1/owned_ad_accounts?*' => Http::response([
                'data' => [
                    ['account_id' => '222', 'id' => 'act_222', 'name' => 'Owned Business Account', 'currency' => 'IDR', 'account_status' => 1],
                ],
            ]),
            'graph.facebook.com/*/business_1/client_ad_accounts?*' => Http::response([
                'data' => [
                    ['account_id' => '333', 'id' => 'act_333', 'name' => 'Client Business Account', 'currency' => 'IDR', 'account_status' => 1],
                    ['account_id' => '111', 'id' => 'act_111', 'name' => 'Personal Account Duplicate', 'currency' => 'IDR', 'account_status' => 1],
                ],
            ]),
            'graph.facebook.com/*/act_111/campaigns?*' => Http::response(['data' => []]),
            'graph.facebook.com/*/act_222/campaigns?*' => Http::response(['data' => []]),
            'graph.facebook.com/*/act_333/campaigns?*' => Http::response(['data' => []]),
        ]);

        $this->from('/profile/')
            ->post('/profile/sync-meta-ads/')
            ->assertRedirect('/profile/')
            ->assertSessionHas('status', 'Sync Meta Ads sedang diproses. Refresh halaman beberapa saat lagi untuk melihat hasil terbaru.');

        $this->assertDatabaseHas('ad_accounts', ['external_id' => 'act_111', 'name' => 'Personal Account']);
        $this->assertDatabaseHas('ad_accounts', ['external_id' => 'act_222', 'name' => 'Owned Business Account']);
        $this->assertDatabaseHas('ad_accounts', ['external_id' => 'act_333', 'name' => 'Client Business Account']);

        $accounts = $this->getJson('/api/get-ad-account/')
            ->assertOk()
            ->json('adaccount');

        $this->assertSame(
            ['act_111', 'act_222', 'act_333'],
            collect($accounts)->pluck('id')->intersect(['act_111', 'act_222', 'act_333'])->sort()->values()->all()
        );
    }

    public function test_manual_meta_ads_sync_redirects_while_sync_runs_after_response(): void
    {
        $this->seed(TestDataSeeder::class);
        $user = User::firstOrFail();
        $this->actingAs($user);

        T4JamProfile::updateOrCreate(['user_id' => $user->id], ['access_token' => 'token']);

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
            'graph.facebook.com/*/cmp_456/insights?*' => Http::response(['data' => []]),
            'graph.facebook.com/*/cmp_456/adsets?*' => Http::response(['data' => []]),
        ]);

        $this->from('/profile/')
            ->post('/profile/sync-meta-ads/')
            ->assertRedirect('/profile/')
            ->assertSessionHas('status', 'Sync Meta Ads sedang diproses. Refresh halaman beberapa saat lagi untuk melihat hasil terbaru.');

        $this->assertDatabaseHas('ad_accounts', ['external_id' => 'act_456', 'name' => 'Manual Account']);
        $this->assertDatabaseHas('campaigns', ['external_id' => 'cmp_456', 'daily_budget' => 250000]);
    }

    public function test_meta_ads_sync_runs_after_response_without_queue_worker(): void
    {
        $this->seed(TestDataSeeder::class);
        $user = User::firstOrFail();
        $this->actingAs($user);

        T4JamProfile::updateOrCreate(['user_id' => $user->id], ['access_token' => 'token']);

        Http::fake([
            'graph.facebook.com/*/me?*' => Http::response(['id' => 'meta-user-1', 'name' => 'Meta Tester']),
            'graph.facebook.com/*/me/adaccounts?*' => Http::response([
                'data' => [
                    ['account_id' => '654', 'id' => 'act_654', 'name' => 'After Response Account', 'currency' => 'IDR', 'account_status' => 1],
                ],
            ]),
            'graph.facebook.com/*/me/businesses?*' => Http::response(['data' => []]),
            'graph.facebook.com/*/act_654/campaigns?*' => Http::response(['data' => []]),
        ]);

        $this->from('/profile/')
            ->post('/profile/sync-meta-ads/')
            ->assertRedirect('/profile/')
            ->assertSessionHas('status', 'Sync Meta Ads sedang diproses. Refresh halaman beberapa saat lagi untuk melihat hasil terbaru.');

        $this->assertDatabaseHas('ad_accounts', ['external_id' => 'act_654', 'name' => 'After Response Account']);
    }

    public function test_meta_ads_sync_keeps_saved_rows_when_insights_hit_rate_limit(): void
    {
        $this->seed(TestDataSeeder::class);
        $user = User::firstOrFail();
        $this->actingAs($user);

        T4JamProfile::updateOrCreate(['user_id' => $user->id], ['access_token' => 'token']);

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

        $this->from('/profile/')
            ->post('/profile/sync-meta-ads/')
            ->assertRedirect('/profile/')
            ->assertSessionHas('status', 'Sync Meta Ads sedang diproses. Refresh halaman beberapa saat lagi untuk melihat hasil terbaru.');

        $this->assertDatabaseHas('ad_accounts', ['external_id' => 'act_789', 'name' => 'Rate Limited Account']);
        $this->assertDatabaseHas('campaigns', ['external_id' => 'cmp_789', 'daily_budget' => 300000]);
        $this->assertDatabaseHas('t4jam_profiles', [
            'user_id' => $user->id,
            'last_meta_error' => 'Meta rate limit tercapai. Data yang sudah terbaca tetap disimpan. Tunggu beberapa menit lalu sync lagi.',
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
