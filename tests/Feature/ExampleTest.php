<?php

namespace Tests\Feature;

use App\Models\AutomationTask;
use App\Models\Campaign;
use App\Models\T4JamProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
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
        $this->seed();
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

    public function test_create_automation_task_persists_dynamic_data(): void
    {
        $this->seed();
        $user = User::firstOrFail();
        $this->actingAs($user);

        $before = AutomationTask::count();

        $this->postJson('/create-automation-tasks/', [
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
        $this->seed();
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
        ]);

        $this->get('/profile/')->assertOk();

        $this->post('/profile/access-token/', [
            'id_aplikasi' => 'app-id',
            'kunci_rahasia' => 'secret',
            'access_token_app' => 'token',
        ])->assertRedirect('/profile/');

        $this->assertDatabaseHas('ad_accounts', ['external_id' => 'act_123', 'name' => 'Meta Account']);
        $this->assertDatabaseHas('campaigns', ['external_id' => 'cmp_1', 'spend' => 45000, 'result' => 3, 'landing_page_view' => 90]);
        $this->assertDatabaseHas('t4jam_profiles', ['user_id' => $user->id, 'meta_user_name' => 'Meta Tester', 'last_meta_error' => null]);
    }

    public function test_meta_write_status_uses_graph_api_when_enabled(): void
    {
        $this->seed();
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
        $this->seed();
        $user = User::firstOrFail();
        $this->actingAs($user);

        $accountId = \App\Models\AdAccount::firstOrFail()->id;

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
        $this->seed();
        config(['services.meta.enable_writes' => true]);
        $user = User::firstOrFail();
        $this->actingAs($user);
        T4JamProfile::updateOrCreate(['user_id' => $user->id], ['access_token' => 'token']);

        $account = \App\Models\AdAccount::firstOrFail();

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
