<?php

namespace Tests\Feature;

use App\Models\AutomationTask;
use App\Models\T4JamProfile;
use App\Models\User;
use App\Services\MetaAutomationReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MetaConversionResultTest extends TestCase
{
    use RefreshDatabase;

    public static function events(): array
    {
        return [
            ['lead', 'lead', 'Leads'],
            ['offsite_conversion.fb_pixel_purchase', 'purchase', 'Purchase'],
            ['onsite_conversion.messaging_conversation_started_7d', 'onsite_conversion.messaging_conversation_started_7d', 'Messaging Conversations'],
            ['omni_initiated_checkout', 'initiate_checkout', 'Initiate Checkout'],
            ['offsite_conversion.custom.123', 'offsite_conversion.custom.123', 'Custom Conversion 123'],
            ['complete_registration', 'complete_registration', 'Complete Registration'],
            ['lead', 'lead', 'Leads', 'adset'],
        ];
    }

    #[DataProvider('events')]
    public function test_meta_selected_result_drives_cpr_scaling_and_label(string $indicator, string $conversion, string $label, string $level = 'campaign'): void
    {
        [$profile, $task] = $this->fixture($level);
        $this->fakeMetrics($task, $this->metaResults($indicator, 2));

        $this->reconcile($profile, $task);

        $task->refresh();
        $this->assertSame(2, $task->current_result);
        $this->assertSame($conversion, $task->result_conversion);
        $this->assertSame(120000, $task->current_budget);
        $this->assertSame($conversion, $task->scaled_conversion);
        Http::assertSent(fn ($request) => $request->method() === 'GET'
            && str_contains($request->data()['fields'] ?? '', 'results'));
        Http::assertSent(fn ($request) => ($request->data()['daily_budget'] ?? null) === 120000);
        $this->actingAs($profile->user)->getJson('/get-specific-task/?automation_id='.$task->id)
            ->assertOk()->assertJsonPath('data.current_hasil', 2)
            ->assertJsonPath('data.current_cpr', 10000)
            ->assertJsonPath('data.conversion_label', $label);
    }

    public function test_changing_meta_event_uses_a_separate_scaling_counter(): void
    {
        [$profile, $task] = $this->fixture();
        $task->update(['result_conversion' => 'purchase', 'scaled_conversion' => 'purchase',
            'scaled_result_count' => 10, 'scaling_period' => 'today:'.now()->toDateString()]);
        $this->fakeMetrics($task, $this->metaResults('lead', 2));
        $this->reconcile($profile, $task);
        $this->assertSame('lead', $task->fresh()->scaled_conversion);
        $this->assertSame(2, $task->fresh()->scaled_result_count);
    }

    public static function sparseSpendingResults(): array
    {
        return [
            '30SEP production response' => ['campaign', 23657, 0, 25000, false],
            '100KT production response over CPR limit' => ['campaign', 35568, 0, 34000, true],
            'campaign spend without checkout' => ['campaign', 35148, 0, 50000, false],
            'ad set spend without checkout' => ['adset', 22466, 0, 50000, false],
            'zero checkout still enforces CPR pause' => ['campaign', 35148, 0, 33000, true],
            'selected checkout actions still count' => ['campaign', 35148, 2, 50000, false],
        ];
    }

    #[DataProvider('sparseSpendingResults')]
    public function test_indicator_only_results_refresh_spend_using_the_confirmed_meta_event(
        string $level, int $spend, int $checkouts, int $pauseLimit, bool $paused,
    ): void {
        [$profile, $task] = $this->fixture($level);
        $task->update(['current_spend' => 0, 'current_result' => 0, 'pause_cpr_limit' => $pauseLimit]);
        $targetId = $level === 'adset' ? $task->ad_set_external_id : $task->campaign_external_id;
        $settings = ['optimization_goal' => 'OFFSITE_CONVERSIONS',
            'promoted_object' => ['custom_event_type' => 'INITIATED_CHECKOUT']];
        Http::fake([
            '*/'.$targetId.'?*' => Http::response(['id' => $targetId, 'status' => 'ACTIVE',
                'effective_status' => 'ACTIVE', 'daily_budget' => '77000',
                ...($level === 'campaign' ? ['adsets' => ['data' => [$settings]]] : $settings)]),
            '*/insights?*' => Http::response(['data' => [[
                'spend' => (string) $spend, 'date_start' => now()->toDateString(), 'date_stop' => now()->toDateString(),
                'results' => [['indicator' => 'actions:offsite_conversion.fb_pixel_initiate_checkout']],
                'inline_link_clicks' => '6', 'actions' => [
                    ['action_type' => 'link_click', 'value' => '6'],
                    ['action_type' => 'purchase', 'value' => '1'],
                    ['action_type' => 'omni_purchase', 'value' => '1'],
                    ['action_type' => 'offsite_conversion.fb_pixel_purchase', 'value' => '1'],
                    ...($checkouts > 0 ? [['action_type' => 'omni_initiated_checkout', 'value' => (string) $checkouts]] : []),
                ],
            ]]]),
            '*'.$targetId => Http::response(['success' => true]),
        ]);

        $this->reconcile($profile, $task);

        $fresh = $task->fresh();
        $this->assertSame($spend, $fresh->current_spend);
        $this->assertSame($checkouts, $fresh->current_result);
        $this->assertSame('initiate_checkout', $fresh->result_conversion);
        $this->assertNull($fresh->metrics_unavailable_at);
        $this->assertNotNull($fresh->last_metrics_synced_at);
        $this->assertSame(! $paused, $fresh->is_active);
        $this->actingAs($profile->user)->getJson('/get-specific-task/?automation_id='.$task->id)
            ->assertOk()->assertJsonPath('data.current_spend', $spend)
            ->assertJsonPath('data.current_hasil', $checkouts)
            ->assertJsonPath('data.current_cpr', $checkouts > 0 ? intdiv($spend, $checkouts) : $spend)
            ->assertJsonPath('data.metrics_available', true);
        if ($paused) {
            Http::assertSent(fn ($request) => $request->method() === 'POST' && $request['status'] === 'PAUSED');
            Http::assertNotSent(fn ($request) => $request->method() === 'POST' && isset($request['daily_budget']));
        } elseif ($checkouts === 0) {
            Http::assertNotSent(fn ($request) => $request->method() === 'POST');
        }
    }

    public static function unavailableResults(): array
    {
        return [
            [null],
            [[['indicator' => 'actions:lead', 'values' => []]]],
            [[['indicator' => 'actions:lead', 'values' => [['value' => '2'], ['value' => '2']]]]],
            [[['indicator' => 'actions:lead', 'values' => [['value' => '2']]],
                ['indicator' => 'actions:purchase', 'values' => [['value' => '2']]]]],
        ];
    }

    #[DataProvider('unavailableResults')]
    public function test_missing_or_ambiguous_results_preserve_metrics_and_do_not_write(?array $results): void
    {
        [$profile, $task] = $this->fixture();
        $task->update(['current_result' => 5, 'current_spend' => 12345, 'result_conversion' => 'purchase']);
        $this->fakeMetrics($task, $results);
        $this->reconcile($profile, $task);
        $this->assertSame(5, $task->fresh()->current_result);
        $this->assertSame(12345, $task->fresh()->current_spend);
        $this->assertNotNull($task->fresh()->metrics_unavailable_at);
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
    }

    public function test_zero_meta_results_do_not_fall_back_to_positive_purchase_actions(): void
    {
        [$profile, $task] = $this->fixture();
        $task->update(['result_conversion' => 'lead']);
        $this->fakeMetrics($task, []);
        $this->reconcile($profile, $task);
        $this->assertSame(0, $task->fresh()->current_result);
        $this->assertSame('lead', $task->fresh()->result_conversion);
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
    }

    public static function zeroResultSettings(): array
    {
        return [
            [['promoted_object' => ['custom_event_type' => 'LEAD']], 'Leads'],
            [['promoted_object' => ['custom_event_type' => 'PURCHASE']], 'Purchase'],
            [['promoted_object' => ['custom_event_type' => 'INITIATED_CHECKOUT']], 'Initiate Checkout'],
            [['optimization_goal' => 'CONVERSATIONS'], 'Messaging Conversations'],
            [['optimization_goal' => 'APP_INSTALLS'], 'App Installs'],
            [['optimization_goal' => 'LEAD_GENERATION'], 'Leads', 'adset'],
            [['optimization_goal' => 'LINK_CLICKS', 'promoted_object' => ['custom_event_type' => 'PURCHASE']], 'Link Clicks'],
        ];
    }

    #[DataProvider('zeroResultSettings')]
    public function test_paused_zero_result_rows_get_event_from_meta_settings(array $settings, string $label, string $level = 'campaign'): void
    {
        [$profile, $task] = $this->fixture($level);
        $task->update(['current_result' => 0, 'result_conversion' => null, 'is_active' => false, 'last_budget_action' => 'meta_sync']);
        $this->fakeMetrics($task, [], $settings, status: 'PAUSED');
        $this->reconcile($profile, $task);

        $this->actingAs($profile->user)->getJson('/get-specific-task/?automation_id='.$task->id)
            ->assertOk()->assertJsonPath('data.current_hasil', 0)->assertJsonPath('data.conversion_label', $label);
        $this->assertFalse($task->fresh()->is_active);
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
    }

    public function test_empty_insights_can_still_populate_zero_result_event_label(): void
    {
        [$profile, $task] = $this->fixture();
        $task->update(['current_result' => 0, 'result_conversion' => null, 'is_active' => false, 'last_budget_action' => 'meta_sync']);
        $this->fakeMetrics($task, null, ['optimization_goal' => 'LEAD_GENERATION'], emptyInsights: true, status: 'PAUSED');
        $this->reconcile($profile, $task);
        $this->assertSame('lead', $task->fresh()->result_conversion);
        $this->assertNotNull($task->fresh()->metrics_unavailable_at);
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
    }

    public function test_missing_native_results_use_only_the_event_selected_in_meta(): void
    {
        [$profile, $task] = $this->fixture();
        $task->update(['current_result' => 0, 'is_active' => false, 'last_budget_action' => 'meta_sync']);
        $this->fakeMetrics($task, null, ['promoted_object' => ['custom_event_type' => 'LEAD']], status: 'PAUSED');
        $this->reconcile($profile, $task);
        $this->assertSame('lead', $task->fresh()->result_conversion);
        $this->assertSame(25, $task->fresh()->current_result);
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
    }

    public function test_mixed_campaign_settings_do_not_guess_a_conversion(): void
    {
        [$profile, $task] = $this->fixture();
        $task->update(['current_result' => 0, 'result_conversion' => null]);
        Http::fake([
            '*/'.$task->campaign_external_id.'?*' => Http::response(['status' => 'ACTIVE', 'daily_budget' => 77000,
                'adsets' => ['data' => [
                    ['promoted_object' => ['custom_event_type' => 'LEAD']],
                    ['promoted_object' => ['custom_event_type' => 'PURCHASE']],
                ]]]),
            '*/insights?*' => Http::response(['data' => [['spend' => '20000',
                'actions' => [['action_type' => 'purchase', 'value' => 99], ['action_type' => 'lead', 'value' => 25]]]]]),
        ]);
        $this->reconcile($profile, $task);
        $this->assertNull($task->fresh()->result_conversion);
        $this->assertNotNull($task->fresh()->metrics_unavailable_at);
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
    }

    public function test_forced_full_sync_populates_paused_event_label_without_insights(): void
    {
        [$profile, $task] = $this->fixture();
        $task->update(['current_result' => 0, 'result_conversion' => null, 'is_active' => false, 'last_budget_action' => 'meta_sync']);
        $accountId = $task->adAccount->external_id;
        $adSet = $task->campaign->adSets()->firstOrFail();
        Http::fake([
            '*/me?*' => Http::response(['id' => 'meta-user', 'name' => 'Meta User']),
            '*/me/adaccounts?*' => Http::response(['data' => [['id' => $accountId, 'name' => 'Account']]]),
            '*/me/businesses?*' => Http::response(['data' => []]),
            '*/'.$accountId.'/campaigns?*' => Http::response(['data' => [['id' => $task->campaign_external_id,
                'name' => 'Campaign', 'status' => 'PAUSED', 'daily_budget' => '77000']]]),
            '*/'.$accountId.'/adsets?*' => Http::response(['data' => [['id' => $adSet->external_id,
                'campaign_id' => $task->campaign_external_id, 'status' => 'PAUSED',
                'optimization_goal' => 'OFFSITE_CONVERSIONS', 'promoted_object' => ['custom_event_type' => 'LEAD']]]]),
            '*/'.$accountId.'/insights?*' => Http::response(['data' => []]),
        ]);
        $this->artisan('t4jam:sync-meta-ads', ['--profile_id' => $profile->id])->assertSuccessful();
        $this->assertSame('lead', $task->fresh()->result_conversion);
        $this->assertFalse($task->fresh()->is_active);
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
    }

    private function fixture(string $level = 'campaign'): array
    {
        $this->travelTo(now('Asia/Jakarta')->setTime(10, 0));
        $this->seed(TestDataSeeder::class);
        config(['services.meta.enable_writes' => true]);
        $profile = T4JamProfile::updateOrCreate(['user_id' => User::firstOrFail()->id], ['access_token' => 'test-token']);
        $task = AutomationTask::with(['campaign.adAccount', 'adAccount'])->firstOrFail();
        AutomationTask::whereKeyNot($task->id)->delete();
        $profile->adAccounts()->syncWithoutDetaching([$task->ad_account_id]);
        $task->campaign->update(['status' => 'ACTIVE', 'effective_status' => 'ACTIVE', 'daily_budget' => 77000]);
        $task->update(['conversion' => 'purchase', 'mode' => 'default', 'system_flow' => 'loss',
            'current_budget' => 77000, 'cpr_cap' => 35000, 'maximum_budget' => 0,
            'is_active' => true, 'pause_when_cpr_loss' => true, 'use_on_off' => false,
            'last_metrics_synced_at' => null]);
        if ($level === 'adset') {
            $adSet = $task->campaign->adSets()->firstOrFail();
            $adSet->update(['status' => 'ACTIVE', 'effective_status' => 'ACTIVE', 'daily_budget' => 77000]);
            $task->update(['level' => 'adset', 'ad_set_id' => $adSet->id, 'ad_set_external_id' => $adSet->external_id]);
        }

        return [$profile, $task];
    }

    private function fakeMetrics(AutomationTask $task, ?array $results, array $settings = [], bool $emptyInsights = false, string $status = 'ACTIVE'): void
    {
        $targetId = $task->level === 'adset' ? $task->ad_set_external_id : $task->campaign_external_id;
        $metrics = ['spend' => '20000', 'date_stop' => now()->toDateString(),
            'actions' => [['action_type' => 'purchase', 'value' => '99'], ['action_type' => 'lead', 'value' => '25']]];
        if ($results !== null) {
            $metrics['results'] = $results;
        }
        $remoteSettings = $task->level === 'campaign'
            ? ($settings === [] ? [] : ['adsets' => ['data' => [$settings]]]) : $settings;
        Http::fake([
            '*/'.$targetId.'?*' => Http::response(['id' => $targetId,
                'status' => $status, 'effective_status' => $status, 'daily_budget' => '77000', ...$remoteSettings]),
            '*/insights?*' => Http::response(['data' => $emptyInsights ? [] : [$metrics]]),
            '*'.$targetId => Http::response(['success' => true]),
        ]);
    }

    private function reconcile(T4JamProfile $profile, AutomationTask $task): void
    {
        app(MetaAutomationReconciliationService::class)->reconcileProfile($profile,
            $task->adAccount->external_id, [$task->campaign_external_id],
            $task->level === 'adset' ? [$task->ad_set_external_id] : [], source: 'webhook');
    }
}
