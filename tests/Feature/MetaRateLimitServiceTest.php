<?php

namespace Tests\Feature;

use App\Exceptions\MetaAdsException;
use App\Models\T4JamProfile;
use App\Services\MetaRateLimitService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class MetaRateLimitServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['cache.default' => 'database', 'cache.serializable_classes' => false]);
        $this->freezeSecond();
    }

    public function test_database_cooldown_blocks_requests_until_expiration(): void
    {
        $profile = (new T4JamProfile)->forceFill(['id' => 1]);
        $service = app(MetaRateLimitService::class);

        $service->record($profile, 120);
        $this->travel(30)->seconds();

        $this->assertTrue($service->isRateLimited($profile));
        $this->assertSame(90, $service->remainingSeconds($profile));
        $this->assertTrue($service->cooldownUntil($profile)->equalTo(now()->addSeconds(90)));
        $this->assertSame(now()->addSeconds(90)->toDateTimeString(), $service->cooldownUntil($profile)->toDateTimeString());

        try {
            $service->assertNotRateLimited($profile);
            $this->fail('Requests must be blocked during cooldown.');
        } catch (MetaAdsException $exception) {
            $this->assertSame(613, $exception->metaCode);
            $this->assertSame(90, $exception->retryAfter);
        }

        $this->travel(90)->seconds();
        $this->assertFalse($service->isRateLimited($profile));
        $this->assertSame(0, $service->remainingSeconds($profile));
        $service->assertNotRateLimited($profile);
    }

    public function test_existing_carbon_cache_preserves_its_deadline_with_classes_disabled(): void
    {
        $profile = (new T4JamProfile)->forceFill(['id' => 1]);
        $service = app(MetaRateLimitService::class);
        $until = now()->setTimezone('Asia/Jakarta')->addSeconds(300);
        Cache::put($service->cooldownKey($profile), $until, 301);

        $this->assertInstanceOf(\__PHP_Incomplete_Class::class, Cache::get($service->cooldownKey($profile)));
        $this->assertTrue($service->isRateLimited($profile));
        $this->assertSame(300, $service->remainingSeconds($profile));
        $this->assertInstanceOf(Carbon::class, $service->cooldownUntil($profile));
        $this->assertTrue($service->cooldownUntil($profile)->equalTo($until));

        $this->travel(300)->seconds();
        $this->assertFalse($service->isRateLimited($profile));
        $this->assertSame(0, $service->remainingSeconds($profile));
    }

    public function test_existing_carbon_in_array_cache_remains_supported(): void
    {
        config(['cache.default' => 'array']);
        $profile = (new T4JamProfile)->forceFill(['id' => 1]);
        $service = app(MetaRateLimitService::class);
        Cache::put($service->cooldownKey($profile), now()->addSeconds(60), 61);

        $this->assertTrue($service->isRateLimited($profile));
        $this->assertSame(60, $service->remainingSeconds($profile));
    }

    public function test_missing_and_cleared_cooldowns_allow_requests_without_affecting_other_profiles(): void
    {
        $profile = (new T4JamProfile)->forceFill(['id' => 1]);
        $other = (new T4JamProfile)->forceFill(['id' => 2]);
        $service = app(MetaRateLimitService::class);

        $this->assertFalse($service->isRateLimited($profile));
        $this->assertSame(0, $service->remainingSeconds($profile));
        $this->assertNull($service->cooldownUntil($profile));

        $service->record($profile, 60);
        $service->record($other, 120);
        $service->clear($profile);

        $this->assertFalse($service->isRateLimited($profile));
        $this->assertSame(120, $service->remainingSeconds($other));
        $service->assertNotRateLimited($profile);
    }

    public function test_default_and_maximum_cooldown_are_preserved(): void
    {
        $profile = (new T4JamProfile)->forceFill(['id' => 1]);
        $service = app(MetaRateLimitService::class);

        foreach ([[null, 60], [0, 60], [-1, 60], [7200, 3600]] as [$retryAfter, $expected]) {
            $service->record($profile, $retryAfter);
            $this->assertSame($expected, $service->remainingSeconds($profile));
        }
    }

    public function test_unreadable_cache_keeps_a_bounded_cooldown(): void
    {
        $profile = (new T4JamProfile)->forceFill(['id' => 1]);
        $service = app(MetaRateLimitService::class);
        Cache::put($service->cooldownKey($profile), ['invalid' => true], 300);

        $this->assertTrue($service->isRateLimited($profile));
        $this->assertSame(60, $service->remainingSeconds($profile));

        $this->travel(60)->seconds();
        $this->assertFalse($service->isRateLimited($profile));
    }
}
