<?php

namespace App\Services;

use App\Exceptions\MetaAdsException;
use App\Models\T4JamProfile;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

class MetaRateLimitService
{
    private const DEFAULT_COOLDOWN_SECONDS = 300;

    private const MAX_COOLDOWN_SECONDS = 3600;

    public function cooldownKey(T4JamProfile $profile): string
    {
        return 'meta_rate_limit:'.$profile->id;
    }

    public function record(T4JamProfile $profile, ?int $retryAfter): void
    {
        Cache::lock($this->cooldownKey($profile).':lock', 5)->block(3, function () use ($profile, $retryAfter): void {
            $attemptKey = $this->cooldownKey($profile).':attempts';
            $attempt = min(5, (int) Cache::get($attemptKey, 0) + 1);
            Cache::put($attemptKey, $attempt, now()->addHours(2));

            $seconds = $retryAfter !== null && $retryAfter > 0
                ? min(self::MAX_COOLDOWN_SECONDS, $retryAfter)
                : min(self::MAX_COOLDOWN_SECONDS, self::DEFAULT_COOLDOWN_SECONDS * (2 ** ($attempt - 1)));
            $until = now()->addSeconds(max($seconds, $this->remainingSeconds($profile)));

            Cache::put(
                $this->cooldownKey($profile),
                $until->getTimestamp(),
                $until->copy()->addSecond(),
            );
        });
    }

    public function isRateLimited(T4JamProfile $profile): bool
    {
        $until = $this->cooldownUntil($profile);

        return $until !== null && $until->isFuture();
    }

    public function remainingSeconds(T4JamProfile $profile): int
    {
        $until = $this->cooldownUntil($profile);

        if (! $until) {
            return 0;
        }

        return max(0, (int) ceil(now()->diffInSeconds($until, false)));
    }

    public function cooldownUntil(T4JamProfile $profile): ?Carbon
    {
        $until = Cache::get($this->cooldownKey($profile));

        if ($until === null) {
            return null;
        }

        if (is_int($until)) {
            return Carbon::createFromTimestamp($until, now()->getTimezone());
        }

        if ($until instanceof DateTimeInterface) {
            return Carbon::instance($until);
        }

        // Preserve existing Carbon deadlines when cache class deserialization is disabled.
        if ($until instanceof \__PHP_Incomplete_Class) {
            $properties = (array) $until;

            if (is_string($properties['date'] ?? null) && is_string($properties['timezone'] ?? null)) {
                try {
                    return Carbon::parse($properties['date'], $properties['timezone']);
                } catch (\InvalidArgumentException) {
                    // Unreadable cache entries still need a bounded cooldown.
                }
            }
        }

        Cache::put($this->cooldownKey($profile), now()->addSeconds(self::DEFAULT_COOLDOWN_SECONDS)->getTimestamp(), self::DEFAULT_COOLDOWN_SECONDS + 1);

        return now()->startOfSecond()->addSeconds(self::DEFAULT_COOLDOWN_SECONDS);
    }

    public function clear(T4JamProfile $profile): void
    {
        Cache::forget($this->cooldownKey($profile));
        Cache::forget($this->cooldownKey($profile).':attempts');
    }

    public function assertNotRateLimited(T4JamProfile $profile): void
    {
        if ($this->isRateLimited($profile)) {
            throw new MetaAdsException(
                'Meta rate limit tercapai. Request dibatalkan selama jeda cooldown.',
                429,
                613,
                'RateLimit',
                $this->remainingSeconds($profile),
                true,
            );
        }
    }

    public function handleException(T4JamProfile $profile, MetaAdsException $exception): void
    {
        if ($exception->metaType === 'RateLimit' || ! $this->isRateLimitCode($exception->metaCode, $exception->httpStatus)) {
            return;
        }

        $this->record($profile, $exception->retryAfter);
    }

    public function isRateLimitCode(?int $metaCode, ?int $httpStatus): bool
    {
        if ($metaCode === 613) {
            return true;
        }

        return $httpStatus === 429 || in_array($metaCode, [4, 17, 80000, 80001, 80002, 80003, 80004], true);
    }
}
