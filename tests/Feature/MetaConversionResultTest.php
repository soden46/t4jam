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

    private function fakeMetrics(AutomationTask $task, ?array $results): void
    {
        $targetId = $task->level === 'adset' ? $task->ad_set_external_id : $task->campaign_external_id;
        $metrics = ['spend' => '20000', 'date_stop' => now()->toDateString(),
            'actions' => [['action_type' => 'purchase', 'value' => '99'], ['action_type' => 'lead', 'value' => '25']]];
        if ($results !== null) {
            $metrics['results'] = $results;
        }
        Http::fake([
            '*/'.$targetId.'?*' => Http::response(['id' => $targetId,
                'status' => 'ACTIVE', 'effective_status' => 'ACTIVE', 'daily_budget' => '77000']),
            '*/insights?*' => Http::response(['data' => [$metrics]]),
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
