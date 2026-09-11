<?php

namespace Tests\Feature;

use App\Exceptions\MetaAdsException;
use App\Jobs\PushMetaAutomationTaskUpdate;
use App\Jobs\SyncMetaAdsProfile;
use App\Models\AutomationTask;
use App\Models\T4JamProfile;
use App\Models\User;
use App\Services\AutomationBudgetService;
use App\Services\MetaAdsSyncService;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GoogleProvider;
use Laravel\Socialite\Two\InvalidStateException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProductionSafetyTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $this->seed(TestDataSeeder::class);
        $user = User::firstOrFail();
        $this->actingAs($user);
        $profile = T4JamProfile::where('user_id', $user->id)->firstOrFail();
        $profile->update(['access_token' => 'owner-token']);
        $task = AutomationTask::with('campaign', 'adAccount')->firstOrFail();
        AutomationTask::where('id', '!=', $task->id)->update(['is_active' => false]);
        $task->update(['conversion' => 'purchase', 'is_active' => true, 'cpr_cap' => 25000, 'pause_cpr_cap' => 20000,
            'pause_when_cpr_loss' => true, 'counter_cpr' => true, 'last_checked_at' => null]);
        config(['services.meta.enable_writes' => true]);
        Http::preventStrayRequests();

        return [$profile, $task];
    }

    public static function activations(): array
    {
        return [['1', true], ['active', true], [null, false], ['false', false]];
    }

    #[DataProvider('activations')]
    public function test_create_and_update_normalize_activation(?string $value, bool $expected): void
    {
        [, $task] = $this->fixture();
        Http::fake(['*' => Http::response(['success' => true])]);
        $payload = ['ad_account' => $task->adAccount->external_id, 'campaign_id' => $task->campaign_external_id,
            'starting_budget' => 100000, 'cpr_cap' => 25000];
        if ($value !== null) {
            $payload['automation_activation'] = $value;
        }
        $response = $this->postJson('/create-automation-tasks/', $payload)->assertOk();
        $created = AutomationTask::findOrFail($response->json('data.id'));
        $this->assertSame($expected, $created->is_active);
        $this->assertSame(auth()->id(), $created->user_id);
        $this->postJson('/update-automation-tasks/', $payload + ['automation_id' => $task->id])->assertOk();
        $this->assertSame($expected, $task->fresh()->is_active);
    }

    public static function conversions(): array
    {
        return [['purchase', 'campaign', 1, 40000, false], ['lead', 'campaign', 4, 10000, true], ['purchase', 'adset', 1, 40000, false]];
    }

    #[DataProvider('conversions')]
    public function test_client_conversion_metrics_and_pause(string $conversion, string $level, int $result, int $cpr, bool $active): void
    {
        [, $task] = $this->fixture();
        $target = $level === 'adset' ? $task->campaign->adSets()->firstOrFail() : $task->campaign;
        $task->update(['conversion' => $conversion, 'level' => $level, 'ad_set_id' => $level === 'adset' ? $target->id : null,
            'ad_set_external_id' => $level === 'adset' ? $target->external_id : null]);
        Http::fake([
            '*/insights?*' => Http::response(['data' => [['spend' => '40000', 'actions' => [
                ['action_type' => 'purchase', 'value' => '1'], ['action_type' => 'lead', 'value' => '4'],
            ]]]]),
            '*'.$target->external_id => Http::response(['success' => true]),
        ]);
        $this->artisan('t4jam:enforce-automation')->assertSuccessful();
        $task->refresh();
        $this->assertSame(40000, $task->current_spend);
        $this->assertSame($result, $task->current_result);
        $this->assertSame($active, $task->is_active);
        $this->getJson('/get-specific-task/?automation_id='.$task->id)->assertOk()
            ->assertJsonPath('data.current_spend', 40000)->assertJsonPath('data.current_hasil', $result)->assertJsonPath('data.current_cpr', $cpr);
        if (! $active) {
            $this->assertSame('PAUSED', $target->fresh()->status);
            $this->assertSame('pause', $task->last_budget_action);
            $this->assertStringContainsString('40.000', $task->last_log);
            $this->assertStringContainsString('25.000', $task->last_log);
            Http::assertSent(fn ($r) => $r->method() === 'POST' && $r['status'] === 'PAUSED');
        } else {
            Http::assertNotSent(fn ($r) => $r->method() === 'POST');
        }
    }

    public function test_pause_hysteresis_and_manual_pause(): void
    {
        [, $task] = $this->fixture();
        Http::fake([
            '*/insights?*' => Http::sequence()
                ->push(['data' => [['spend' => 40000, 'actions' => [['action_type' => 'purchase', 'value' => 1]]]]])
                ->push(['data' => [['spend' => 40000, 'actions' => [['action_type' => 'purchase', 'value' => 1]]]]])
                ->push(['data' => [['spend' => 10000, 'actions' => [['action_type' => 'purchase', 'value' => 1]]]]]),
            '*'.$task->campaign_external_id => Http::response(['success' => true]),
        ]);
        $this->artisan('t4jam:enforce-automation')->assertSuccessful();
        $this->assertFalse($task->fresh()->is_active);
        $this->travel(11)->minutes();
        $this->artisan('t4jam:enforce-automation')->assertSuccessful();
        $this->assertFalse($task->fresh()->is_active);
        $this->travel(11)->minutes();
        $this->artisan('t4jam:enforce-automation')->assertSuccessful();
        $this->assertTrue($task->fresh()->is_active);
        $this->postJson('/update-status-automation-tasks/', ['automation_id' => $task->id, 'status' => 'false'])->assertOk();
        $this->assertSame('manual', $task->fresh()->last_budget_action);
        $this->travel(11)->minutes();
        Http::fake();
        $this->artisan('t4jam:enforce-automation')->assertSuccessful();
        Http::assertNothingSent();
    }

    public function test_invalid_recovery_is_rejected_before_meta_write_and_old_invalid_rows_cannot_resume(): void
    {
        [, $task] = $this->fixture();
        Http::fake();
        $this->postJson('/update-automation-tasks/', ['automation_id' => $task->id, 'counter_cpr' => 1,
            'cpr_cap' => 25000, 'pause_cpr_cap' => 70000])->assertUnprocessable()->assertJsonValidationErrors('pause_cpr_cap');
        Http::assertNothingSent();
        $task->campaign->update(['status' => 'PAUSED']);
        $task->update(['is_active' => false, 'last_budget_action' => 'pause', 'pause_cpr_cap' => 70000]);
        Http::fake(['*' => Http::response(['data' => [['spend' => 40000, 'actions' => [['action_type' => 'purchase', 'value' => 1]]]]])]);
        $this->artisan('t4jam:enforce-automation')->assertSuccessful();
        $this->assertFalse($task->fresh()->is_active);
        Http::assertNotSent(fn ($r) => $r->method() === 'POST');
    }

    public function test_dashboard_uses_selected_conversion_and_session(): void
    {
        [, $task] = $this->fixture();
        $metrics = app(AutomationBudgetService::class)->metricSnapshot(['spend' => 40000, 'actions' => [
            ['action_type' => 'purchase', 'value' => 1], ['action_type' => 'lead', 'value' => 4],
        ]]);
        $task->campaign->update(app(AutomationBudgetService::class)->insightPayload($metrics));
        $url = '/api/get-ad-insight/?ad_account='.$task->adAccount->external_id;
        $this->getJson($url.'&conversion=purchase')->assertOk()->assertJsonPath('summery.0.hasil', 1)->assertJsonPath('summery.0.cpr', 40000);
        $this->postJson('/api/changed-settings/', ['conversion' => 'lead'])->assertOk();
        $this->getJson($url)->assertOk()->assertJsonPath('summery.0.hasil', 4)->assertJsonPath('summery.0.cpr', 10000);
        $this->get('/dashboard/')->assertOk()->assertSee('value="lead" selected', false);
    }

    public function test_scheduler_and_endpoints_never_use_another_owners_task(): void
    {
        [$profile, $task] = $this->fixture();
        $other = User::factory()->create();
        $otherProfile = T4JamProfile::create(['user_id' => $other->id, 'access_token' => 'other-token']);
        Http::fake();
        app(AutomationBudgetService::class)->pauseTasksOverCprCap($otherProfile, app(MetaAdsSyncService::class)->client($otherProfile), true);
        Http::assertNothingSent();
        $this->actingAs($other)->getJson('/get-specific-task/?automation_id='.$task->id)->assertJsonPath('data', null);
        $this->getJson('/get-automation-task/')->assertJsonCount(0, 'data');
        $this->getJson('/get-history-log/?task_id='.$task->id)->assertNotFound();
        $task->update(['user_id' => null]);
        app(AutomationBudgetService::class)->pauseTasksOverCprCap($profile, app(MetaAdsSyncService::class)->client($profile), true);
        Http::assertNothingSent();
    }

    public function test_credentials_are_encrypted_hidden_and_blank_fields_preserve_them(): void
    {
        [$profile] = $this->fixture();
        Queue::fake();
        $profile->update(['app_secret' => 'private-secret']);
        $raw = DB::table('t4jam_profiles')->where('id', $profile->id)->first();
        $this->assertStringStartsWith('encrypted:v1:', $raw->access_token);
        $this->assertStringNotContainsString('owner-token', $raw->access_token);
        $this->assertStringNotContainsString('private-secret', $raw->app_secret);
        $this->get('/profile/')->assertOk()->assertDontSee('owner-token')->assertDontSee('private-secret');
        $this->assertArrayNotHasKey('access_token', $profile->toArray());
        $this->post('/profile/access-token/', ['access_token_app' => '', 'kunci_rahasia' => ''])->assertRedirect();
        $this->assertSame('owner-token', $profile->fresh()->access_token);
        $this->assertSame('private-secret', $profile->fresh()->app_secret);
    }

    public function test_credential_migration_accepts_existing_plaintext_and_is_repeatable(): void
    {
        [$profile] = $this->fixture();
        DB::table('t4jam_profiles')->where('id', $profile->id)->update(['access_token' => 'legacy-token', 'app_secret' => 'legacy-secret']);
        $this->assertSame('legacy-token', $profile->fresh()->access_token);
        $migration = require database_path('migrations/2026_09_11_000001_encrypt_meta_credentials.php');
        $migration->up();
        $migration->up();
        $this->assertSame('legacy-token', $profile->fresh()->access_token);
        $this->assertSame('legacy-secret', $profile->fresh()->app_secret);
        $this->assertStringStartsWith('encrypted:v1:', DB::table('t4jam_profiles')->where('id', $profile->id)->value('app_secret'));
    }

    public function test_google_demo_and_fake_reset_are_unavailable_in_production(): void
    {
        $this->withoutMiddleware(PreventRequestForgery::class);
        app()->instance('env', 'production');
        config(['services.google.client_id' => null, 'services.google.client_secret' => null]);
        $this->get('/social-auth/login/google-oauth2/')->assertRedirect('/login/')->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => 'google-demo@t4jam.local']);
        $this->post('/account/riset-password/', ['email' => 'user@example.com'])->assertSessionHasErrors('email')->assertSessionMissing('status');
    }

    public static function failures(): array
    {
        return [[429, 4, true, 600], [503, 2, true, 180], [400, 190, false, 0], [403, 200, false, 0]];
    }

    public function test_real_password_reset_notification_and_single_use_token(): void
    {
        $user = User::factory()->create();
        config(['mail.default' => 'smtp']);
        Notification::fake();
        $this->post('/account/riset-password/', ['email' => $user->email])->assertSessionHas('status');
        $token = null;
        Notification::assertSentTo($user, ResetPassword::class,
            function ($notification) use (&$token) {
                $token = $notification->token;

                return true;
            });
        $this->get('/account/reset-password/'.$token.'?email='.urlencode($user->email))->assertOk();
        $data = ['email' => $user->email, 'token' => $token, 'password' => 'new-password123', 'password_confirmation' => 'new-password123'];
        $this->post('/account/reset-password/', $data)->assertRedirect('/login/');
        $this->assertTrue(Hash::check('new-password123', $user->fresh()->password));
        $this->post('/account/reset-password/', $data)->assertSessionHasErrors('email');
    }

    public function test_schema_migration_preserves_existing_data_and_does_not_guess_multiuser_owner(): void
    {
        [, $task] = $this->fixture();
        $migration = require database_path('migrations/2026_09_11_000002_add_automation_ownership_and_conversion_metrics.php');
        $migration->down();
        $migration->up();
        $this->assertSame(auth()->id(), $task->fresh()->user_id);
        $this->assertSame(25000, $task->fresh()->cpr_cap);
        $migration->down();
        User::factory()->create();
        $migration->up();
        $this->assertNull($task->fresh()->user_id);
        $this->assertSame(25000, $task->fresh()->cpr_cap);
    }

    public function test_google_callback_uses_stateful_provider_and_keeps_existing_password(): void
    {
        $user = User::factory()->create();
        $password = $user->password;
        $google = (new \Laravel\Socialite\Two\User)->map(['id' => 'google-id', 'email' => $user->email, 'name' => 'Google User']);
        $google->setRaw(['email_verified' => true]);
        $provider = \Mockery::mock(GoogleProvider::class);
        $provider->shouldReceive('user')->once()->andReturn($google);
        $provider->shouldNotReceive('stateless');
        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);
        $this->get('/social-auth/complete/google-oauth2/')->assertRedirect('/dashboard/');
        $this->assertAuthenticatedAs($user);
        $this->assertSame($password, $user->fresh()->password);
    }

    public function test_invalid_google_state_does_not_authenticate(): void
    {
        $provider = \Mockery::mock(GoogleProvider::class);
        $provider->shouldReceive('user')->once()->andThrow(new InvalidStateException);
        $provider->shouldNotReceive('stateless');
        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);
        $this->get('/social-auth/complete/google-oauth2/')->assertRedirect('/login/')->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_retry_exhaustion_and_network_backoff(): void
    {
        [$profile] = $this->fixture();
        Http::fake(['*' => Http::failedConnection()]);
        $job = (new SyncMetaAdsProfile($profile->id))->withFakeQueueInteractions();
        $job->handle(app(MetaAdsSyncService::class));
        $job->assertReleased(60);
        $job = new SyncMetaAdsProfile($profile->id);
        $queueJob = \Mockery::mock(Job::class);
        $queueJob->shouldReceive('attempts')->andReturn(3);
        $queueJob->shouldReceive('fail')->once()->with(\Mockery::type(MetaAdsException::class));
        $queueJob->shouldNotReceive('release');
        $job->setJob($queueJob);
        $job->handle(app(MetaAdsSyncService::class));
    }

    #[DataProvider('failures')]
    public function test_jobs_retry_transient_errors_but_fail_permanent_errors(int $status, int $code, bool $retry, int $delay): void
    {
        [$profile, $task] = $this->fixture();
        Http::fake(['*' => Http::response(['error' => ['code' => $code, 'message' => 'Do not echo owner-token private-secret']], $status, ['Retry-After' => $delay])]);
        $jobs = [new SyncMetaAdsProfile($profile->id), new PushMetaAutomationTaskUpdate($profile->id, $task->id, 'status', 'Update', active: false)];
        foreach ($jobs as $job) {
            $job->withFakeQueueInteractions();
            $job->handle(app(MetaAdsSyncService::class));
            if ($retry) {
                $job->assertReleased($delay);
            } else {
                $job->assertFailed();
                $job->assertNotReleased();
            }
        }
        $this->assertStringNotContainsString('owner-token', (string) $profile->fresh()->last_meta_error);
        Http::assertSentCount(2);
    }
}
