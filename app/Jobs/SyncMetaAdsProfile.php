<?php

namespace App\Jobs;

use App\Exceptions\MetaAdsException;
use App\Models\T4JamProfile;
use App\Services\MetaAdsSyncService;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncMetaAdsProfile
{
    use Dispatchable;

    public function __construct(private readonly int $profileId) {}

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
            Log::warning('Meta ads sync failed after response', [
                'profile_id' => $this->profileId,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            $profile->update(['last_meta_error' => 'Sync Meta Ads gagal. Coba lagi beberapa saat.']);
        }
    }
}
