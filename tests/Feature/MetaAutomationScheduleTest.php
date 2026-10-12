<?php

namespace Tests\Feature;

use App\Models\AutomationTask;
use App\Models\T4JamProfile;
use App\Models\User;
use App\Services\AutomationTaskMutationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MetaAutomationScheduleTest extends TestCase
{
    use RefreshDatabase;

    public static function scheduleCases(): array
    {
        return [
            'next ON with unavailable conversion' => [[], true],
            'next ON with failed Insights' => [['insights_error' => true], true],
            'long budget evaluation period does not delay ON' => [['period' => 1440], true],
            'schedule pause resumes without conversion' => [['last_budget_action' => 'schedule_pause'], true],
            'ad set next ON with unavailable conversion' => [['level' => 'adset'], true],
            'OFF with unavailable conversion' => [['now' => '2026-10-12 23:00:00', 'is_active' => true, 'last_budget_action' => null], false],
            'same-day pause before ON' => [['cpr_paused_at' => '2026-10-12 03:30:00'], true],
            'pause during current session stays off' => [['now' => '2026-10-12 08:00:00', 'cpr_paused_at' => '2026-10-12 06:00:00'], false],
            'before ON stays off' => [['now' => '2026-10-12 03:59:00'], false],
            'at OFF stays off' => [['now' => '2026-10-12 23:00:00'], false],
            'overnight next ON' => [['now' => '2026-10-12 20:00:00', 'on_time' => '20:00', 'off_time' => '04:00'], true],
            'midnight is still same overnight session' => [['now' => '2026-10-12 00:10:00', 'on_time' => '20:00', 'off_time' => '04:00', 'cpr_paused_at' => '2026-10-11 22:00:00', 'healthy_metrics' => true], false],
            '24-hour window restarts at ON' => [['off_time' => '04:00'], true],
            'schedule disabled retains CPR recovery guards' => [['use_on_off' => false], false],
            'unknown CPR pause date stays off' => [['cpr_paused_at' => null], false],
            'pending mutation stays off' => [['pending_meta_action' => 'budget'], false],
            'writes disabled stays off' => [['writes' => false], false],
            'failed activation stays off' => [['write_error' => true], false],
            'unconfirmed activation stays off' => [['unconfirmed_write' => true], false],
            'mutation lock stays off' => [['locked' => true], false],
            'archived target stays off' => [['target_status' => 'ARCHIVED'], false],
        ];
    }

    #[DataProvider('scheduleCases')]
    public function test_on_schedule_works_independently_of_conversion_and_budget_rules(array $changes, bool $active): void
    {
        $this->travelTo(Carbon::parse($changes['now'] ?? '2026-10-12 04:00:00', 'Asia/Jakarta'));
        Cache::flush();
        $this->seed(TestDataSeeder::class);
        config(['services.meta.enable_writes' => $changes['writes'] ?? true]);
        $profile = T4JamProfile::updateOrCreate(['user_id' => User::firstOrFail()->id], ['access_token' => 'token']);
        $task = AutomationTask::with('campaign')->where('user_id', $profile->user_id)->firstOrFail();
        AutomationTask::whereKeyNot($task->id)->delete();
        $target = $task->campaign;
        if (($changes['level'] ?? 'campaign') === 'adset') {
            $target = $task->campaign->adSets()->firstOrFail();
            $task->update(['level' => 'adset', 'ad_set_id' => $target->id, 'ad_set_external_id' => $target->external_id]);
        }
        $initialStatus = ($changes['is_active'] ?? false) ? 'ACTIVE' : 'PAUSED';
        $target->update(['status' => $changes['target_status'] ?? $initialStatus, 'effective_status' => $changes['target_status'] ?? $initialStatus]);
        $task->update(array_merge([
            'use_on_off' => true, 'on_time' => '04:00', 'off_time' => '23:00',
            'is_active' => false, 'last_budget_action' => 'pause',
            'cpr_paused_at' => '2026-10-11 08:46:13', 'counter_cpr' => false,
            'period' => 5, 'last_checked_at' => now(),
            'cpr_cap' => 34000, 'pause_cpr_limit' => 33999,
            'current_spend' => 42922, 'current_result' => 0,
            'pending_meta_action' => null,
        ], array_intersect_key($changes, array_flip(['cpr_paused_at', 'on_time', 'off_time', 'use_on_off', 'pending_meta_action', 'is_active', 'last_budget_action', 'period']))));

        Http::fake(function ($request) use ($task, $target, $changes) {
            if ($request->method() === 'POST') {
                if ($changes['write_error'] ?? false) {
                    return Http::response(['error' => ['code' => 100, 'message' => 'Invalid request']], 400);
                }

                return Http::response(($changes['unconfirmed_write'] ?? false) ? [] : ['success' => true]);
            }
            if (str_contains($request->url(), '/insights')) {
                if ($changes['insights_error'] ?? false) {
                    return Http::response(['error' => ['code' => 100, 'message' => 'Invalid request']], 400);
                }

                return Http::response(['data' => [[
                    ($task->level === 'adset' ? 'adset_id' : 'campaign_id') => $target->external_id, 'spend' => '0',
                    'results' => ($changes['healthy_metrics'] ?? false)
                        ? $this->metaResults('purchase', 0) : [['indicator' => 'actions:purchase', 'values' => []]],
                    'date_stop' => now('Asia/Jakarta')->toDateString(),
                ]]]);
            }

            return Http::response(['id' => $target->external_id, 'status' => 'PAUSED', 'effective_status' => 'PAUSED']);
        });

        $lock = app(AutomationTaskMutationService::class)->lock($task);
        if ($changes['locked'] ?? false) {
            $lock->get();
        }
        try {
            $this->artisan('t4jam:enforce-automation')->assertSuccessful();
        } finally {
            if ($changes['locked'] ?? false) {
                $lock->release();
            }
        }

        $fresh = $task->fresh();
        $this->assertSame($active, $fresh->is_active);
        $this->assertSame($active ? 'schedule_resume' : (($changes['is_active'] ?? false) ? 'schedule_pause' : 'pause'), $fresh->last_budget_action);
        $this->assertSame($active ? 'ACTIVE' : ($changes['target_status'] ?? 'PAUSED'), $target->fresh()->status);
        Http::assertNotSent(fn ($request) => $request->method() === 'POST' && isset($request['daily_budget']));
        if ($active) {
            $this->assertNull($fresh->cpr_paused_at);
            $this->travelTo(now()->addMinutes(5));
            $this->artisan('t4jam:enforce-automation')->assertSuccessful();
            $this->assertTrue($task->fresh()->is_active);
            $this->assertCount(1, Http::recorded(fn ($request) => $request->method() === 'POST' && $request['status'] === 'ACTIVE'));
        } elseif (! ($changes['write_error'] ?? false) && ! ($changes['unconfirmed_write'] ?? false) && ! ($changes['is_active'] ?? false)) {
            Http::assertNotSent(fn ($request) => $request->method() === 'POST');
        } elseif ($changes['is_active'] ?? false) {
            Http::assertSent(fn ($request) => $request->method() === 'POST' && $request['status'] === 'PAUSED');
        }
    }
}
