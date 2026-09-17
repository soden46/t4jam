<?php

namespace App\Jobs;

use App\Exceptions\MetaAdsException;
use App\Jobs\Concerns\RetriesMetaRequests;
use App\Models\T4JamProfile;
use App\Services\MetaAdsSyncService;
use App\Support\MetaFlowLog;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class SyncMetaAdsAccount implements ShouldBeUnique, ShouldQueue
{
    use Queueable;
    use RetriesMetaRequests;

    public int $tries = 3;

    public int $timeout = 240;

    public int $uniqueFor = 60;

    public function __construct(
        public readonly int $profileId,
        public readonly string $adAccountExternalId,
    ) {
        $this->onQueue('meta');
    }

    public function uniqueId(): string
    {
        return $this->profileId.':'.$this->adAccountExternalId;
    }

    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(MetaAdsSyncService $metaSync): void
    {
        $profile = T4JamProfile::query()->find($this->profileId);

        if (! $profile?->hasAccessToken()) {
            MetaFlowLog::warning('webhook account sync skipped without profile token', [
                'profile_id' => $this->profileId,
                'ad_account_id' => $this->adAccountExternalId,
            ]);

            return;
        }

        try {
            $metaSync->syncAccountFromWebhook($profile, $this->adAccountExternalId);
        } catch (MetaAdsException $exception) {
            $profile->update(['last_meta_error' => $exception->getMessage()]);
            MetaFlowLog::warning('webhook account sync failed with meta error', [
                'profile_id' => $this->profileId,
                'ad_account_id' => $this->adAccountExternalId,
                'http_status' => $exception->httpStatus,
                'meta_code' => $exception->metaCode,
            ]);
            $this->retryOrFail($exception);
        } catch (Throwable $exception) {
            $profile->update(['last_meta_error' => 'Sinkronisasi perubahan Meta gagal.']);
            MetaFlowLog::warning('webhook account sync crashed', [
                'profile_id' => $this->profileId,
                'ad_account_id' => $this->adAccountExternalId,
                'exception' => $exception::class,
            ]);

            throw $exception;
        }
    }
}
