<?php

namespace App\Jobs;

use App\Exceptions\MetaAdsException;
use App\Models\T4JamProfile;
use App\Services\MetaAdsSyncService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncMetaAdsProfile implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 600;

    public int $uniqueFor = 1800;

    public function __construct(private readonly int $profileId)
    {
        $this->onQueue('meta');
    }

    public function uniqueId(): string
    {
        return (string) $this->profileId;
    }

    public function backoff(): array
    {
        return [60, 180, 300];
    }

    public function handle(MetaAdsSyncService $metaSync): void
    {
        $profile = T4JamProfile::query()->find($this->profileId);

        if (! $profile) {
            return;
        }

        try {
            $metaSync->sync($profile);
        } catch (MetaAdsException $exception) {
            if ($this->isRateLimitError($exception)) {
                $this->releaseOnRateLimit($profile, $exception);

                return;
            }

            $profile->update(['last_meta_error' => $exception->getMessage()]);

            throw $exception;
        } catch (Throwable $exception) {
            Log::warning('Meta ads sync failed after response', [
                'profile_id' => $this->profileId,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            $profile->update(['last_meta_error' => 'Sync Meta Ads gagal ('.$exception::class.')']);

            throw $exception;
        }
    }

    private function isRateLimitError(MetaAdsException $exception): bool
    {
        $metaCode = $exception->metaCode;

        return in_array($metaCode, [4, 17, 613, 80000, 80001, 80002, 80003, 80004], true);
    }

    private function releaseOnRateLimit(T4JamProfile $profile, MetaAdsException $exception): void
    {
        $message = strtolower($exception->getMessage());
        $retryAfter = match (true) {
            str_contains($message, 'user request limit') => 60,
            str_contains($message, 'application request limit') => 120,
            str_contains($message, 'rate limit') => 60,
            default => 30,
        };

        Log::warning('Meta rate limit during sync, releasing job', [
            'profile_id' => $this->profileId,
            'meta_code' => $exception->metaCode,
            'retry_after_seconds' => $retryAfter,
        ]);

        $this->release($retryAfter);

        $profile->update([
            'last_meta_error' => 'Meta rate limit tercapai. Sync akan dicoba otomatis '.$retryAfter.' detik lagi.',
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        if (! $exception) {
            return;
        }

        T4JamProfile::query()
            ->where('id', $this->profileId)
            ->update(['last_meta_error' => 'Sync Meta Ads gagal ('.$exception::class.')']);
    }
}
