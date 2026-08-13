<?php

namespace App\Jobs;

use App\Exceptions\MetaAdsException;
use App\Models\AdSetup;
use App\Models\T4JamProfile;
use App\Services\MetaAdSetupPublisher;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class PublishMetaAdSetup implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public int $uniqueFor = 900;

    public function __construct(
        private readonly int $adSetupId,
        private readonly int $profileId,
    ) {
        $this->onQueue('meta');
    }

    public function uniqueId(): string
    {
        return (string) $this->adSetupId;
    }

    public function handle(MetaAdSetupPublisher $publisher): void
    {
        $setup = AdSetup::query()->find($this->adSetupId);
        $profile = T4JamProfile::query()->find($this->profileId);

        if (! $setup || ! $profile) {
            return;
        }

        try {
            $publisher->publish($setup, $profile);
        } catch (MetaAdsException $exception) {
            $message = $this->metaErrorMessage($exception);
            $setup->update(['status' => 'failed', 'last_error' => $message]);
            $this->reportFailure($exception, $setup);
        } catch (Throwable $exception) {
            $setup->update([
                'status' => 'failed',
                'last_error' => 'Publish ke Meta belum berhasil. Coba lagi beberapa saat atau cek koneksi Meta di Profile.',
            ]);

            Log::warning('Meta ad setup publish job failed', [
                'ad_setup_id' => $setup->id,
                'user_id' => $setup->user_id,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }

    private function metaErrorMessage(MetaAdsException $exception): string
    {
        $message = strtolower($exception->getMessage());

        if ($exception->metaCode === 190 || str_contains($message, 'token')) {
            return 'Access token Meta tidak valid atau sudah expired. Silakan simpan ulang access token di Profile.';
        }

        if (in_array($exception->metaCode, [17, 4, 613, 80000, 80001, 80002, 80003, 80004], true)) {
            return 'Meta rate limit tercapai. Tunggu sebentar lalu publish lagi.';
        }

        if ($exception->httpStatus === 403 || str_contains($message, 'permission')) {
            return 'Akses Meta belum punya izin untuk membuat iklan di ad account ini.';
        }

        if ($exception->httpStatus === 400) {
            return 'Meta menolak data setup iklan. Cek Page ID, targeting, budget, dan URL landing page.';
        }

        return 'Publish ke Meta belum berhasil. Coba lagi beberapa saat atau cek koneksi Meta di Profile.';
    }

    private function reportFailure(MetaAdsException $exception, AdSetup $setup): void
    {
        Log::warning('Meta ad setup publish failed', [
            'ad_setup_id' => $setup->id,
            'user_id' => $setup->user_id,
            'http_status' => $exception->httpStatus,
            'meta_code' => $exception->metaCode,
            'meta_type' => $exception->metaType,
        ]);
    }
}
