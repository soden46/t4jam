<?php

namespace App\Jobs;

use App\Exceptions\MetaAdsException;
use App\Jobs\Concerns\RetriesMetaRequests;
use App\Models\T4JamProfile;
use App\Services\MetaAdsSyncService;
use App\Services\MetaRateLimitService;
use App\Support\MetaFlowLog;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class SyncMetaAdsProfile implements ShouldBeUnique, ShouldQueue
{
    use Queueable;
    use RetriesMetaRequests;

    public int $tries = 3;

    public int $timeout = 650;

    public int $uniqueFor = 14400;

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
            app(MetaRateLimitService::class)->assertNotRateLimited($profile);
            MetaFlowLog::info('full sync job started', [
                'profile_id' => $profile->id,
                'queue' => 'meta',
                'job_id' => $this->job?->getJobId(),
                'attempt' => $this->attempts(),
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
            if ($exception->metaType === 'RateLimit') {
                MetaFlowLog::info('full sync job deferred during cooldown', [
                    'profile_id' => $this->profileId,
                    'job_id' => $this->job?->getJobId(),
                    'attempt' => $this->attempts(),
                    'retry_after_seconds' => $exception->retryAfter,
                ]);
                $this->retryOrFail($exception);

                return;
            }

            $profile->update(['last_meta_error' => $exception->getMessage()]);
            MetaFlowLog::warning('full sync job failed with meta error', [
                'profile_id' => $this->profileId,
                'http_status' => $exception->httpStatus,
                'meta_code' => $exception->metaCode,
                'meta_type' => $exception->metaType,
                'job_id' => $this->job?->getJobId(),
                'attempt' => $this->attempts(),
                'retry_after_seconds' => $exception->retryAfter,
            ]);
            $this->retryOrFail($exception);
        } catch (Throwable $exception) {
            MetaFlowLog::warning('full sync job failed', [
                'profile_id' => $this->profileId,
                'exception' => $exception::class,
            ]);

            $profile->update(['last_meta_error' => 'Sync Meta Ads gagal. Coba lagi beberapa saat.']);
            throw $exception;
        }
    }
}
