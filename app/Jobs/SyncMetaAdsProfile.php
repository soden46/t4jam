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

class SyncMetaAdsProfile implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 650;

    public int $uniqueFor = 900;

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
        return [60, 300];
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
            $profile->update(['last_meta_error' => $exception->getMessage()]);
        } catch (Throwable $exception) {
            Log::warning('Meta ads sync job failed', [
                'profile_id' => $this->profileId,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            $profile->update(['last_meta_error' => 'Sync Meta Ads gagal. Coba lagi beberapa saat.']);
        }
    }
}
