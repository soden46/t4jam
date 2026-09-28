<?php

namespace App\Services;

use App\Exceptions\MetaAdsException;
use App\Models\T4JamProfile;
use Illuminate\Support\Facades\Cache;

class MetaRateLimitService
{
    private const DEFAULT_COOLDOWN_SECONDS = 60;

    private const MAX_COOLDOWN_SECONDS = 3600;

    public function cooldownKey(T4JamProfile $profile): string
    {
        return 'meta_rate_limit:'.$profile->id;
    }

    public function record(T4JamProfile $profile, ?int $retryAfter): void
    {
        $seconds = $this->normalizeSeconds($retryAfter);

        Cache::put(
            $this->cooldownKey($profile),
            now()->addSeconds($seconds),
            now()->addSeconds($seconds)->addSeconds(1),
        );
    }

    public function isRateLimited(T4JamProfile $profile): bool
    {
        $until = Cache::get($this->cooldownKey($profile));

        return $until !== null && $until->isFuture();
    }

    public function remainingSeconds(T4JamProfile $profile): int
    {
        $until = Cache::get($this->cooldownKey($profile));

        if (! $until) {
            return 0;
        }

        return max(0, now()->diffInSeconds($until, false));
    }

    public function cooldownUntil(T4JamProfile $profile): mixed
    {
        return Cache::get($this->cooldownKey($profile));
    }

    public function clear(T4JamProfile $profile): void
    {
        Cache::forget($this->cooldownKey($profile));
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
        if (! $this->isRateLimitCode($exception->metaCode, $exception->httpStatus)) {
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

    private function normalizeSeconds(?int $retryAfter): int
    {
        if ($retryAfter && $retryAfter > 0) {
            return min(self::MAX_COOLDOWN_SECONDS, $retryAfter);
        }

        return self::DEFAULT_COOLDOWN_SECONDS;
    }
}
