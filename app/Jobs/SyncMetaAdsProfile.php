<?php

namespace App\Jobs;

use App\Exceptions\MetaAdsException;
use App\Models\T4JamProfile;
use App\Services\MetaAdsSyncService;
use App\Support\MetaFlowLog;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
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
            MetaFlowLog::warning('full sync job skipped because profile missing', [
                'profile_id' => $this->profileId,
            ]);

            return;
        }

        try {
            MetaFlowLog::info('full sync job started', [
                'profile_id' => $profile->id,
                'queue' => 'meta',
            ]);

            $counts = $metaSync->sync($profile);
            MetaFlowLog::info('full sync job finished', [
                'profile_id' => $profile->id,
                'accounts' => $counts['accounts'] ?? 0,
                'campaigns' => $counts['campaigns'] ?? 0,
                'adsets' => $counts['adsets'] ?? 0,
                'insights' => $counts['insights'] ?? 0,
                'has_warning' => isset($counts['warning']),
            ]);
        } catch (MetaAdsException $exception) {
            $profile->update(['last_meta_error' => $exception->getMessage()]);
            MetaFlowLog::warning('full sync job failed with meta error', [
                'profile_id' => $this->profileId,
                'http_status' => $exception->httpStatus,
                'meta_code' => $exception->metaCode,
                'meta_type' => $exception->metaType,
            ]);
        } catch (Throwable $exception) {
            MetaFlowLog::warning('full sync job failed', [
                'profile_id' => $this->profileId,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            $profile->update(['last_meta_error' => 'Sync Meta Ads gagal. Coba lagi beberapa saat.']);
        }
    }
}
