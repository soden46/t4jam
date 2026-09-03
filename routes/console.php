<?php

use App\Exceptions\MetaAdsException;
use App\Models\T4JamProfile;
use App\Services\MetaAdsSyncService;
use App\Support\MetaFlowLog;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('t4jam:sync-meta-ads {--profile_id=}', function (MetaAdsSyncService $metaSync): int {
    $profiles = T4JamProfile::query()
        ->whereNotNull('access_token')
        ->where('access_token', '<>', '')
        ->when($this->option('profile_id'), fn ($query, $profileId) => $query->whereKey($profileId))
        ->get();

    if ($profiles->isEmpty()) {
        MetaFlowLog::info('sync command skipped because no token profiles');
        $this->info('Tidak ada profile dengan access token Meta.');

        return 0;
    }

    $failed = 0;

    foreach ($profiles as $profile) {
        try {
            MetaFlowLog::info('sync command profile started', [
                'profile_id' => $profile->id,
            ]);

            $counts = $metaSync->sync($profile);

            $message = sprintf(
                'Profile %d synced: %d ad account, %d campaign, %d ad set, %d insight.',
                $profile->id,
                $counts['accounts'] ?? 0,
                $counts['campaigns'] ?? 0,
                $counts['adsets'] ?? 0,
                $counts['insights'] ?? 0,
            );

            if (! empty($counts['warning'])) {
                $message .= ' Warning: '.$counts['warning'];
            }

            MetaFlowLog::info('sync command profile finished', [
                'profile_id' => $profile->id,
                'accounts' => $counts['accounts'] ?? 0,
                'campaigns' => $counts['campaigns'] ?? 0,
                'adsets' => $counts['adsets'] ?? 0,
                'insights' => $counts['insights'] ?? 0,
                'has_warning' => isset($counts['warning']),
            ]);
            $this->info($message);
        } catch (MetaAdsException $exception) {
            $failed++;
            $profile->update(['last_meta_error' => $exception->getMessage()]);
            MetaFlowLog::warning('sync command profile failed with meta error', [
                'profile_id' => $profile->id,
                'http_status' => $exception->httpStatus,
                'meta_code' => $exception->metaCode,
                'meta_type' => $exception->metaType,
            ]);
            $this->warn("Profile {$profile->id} sync failed: {$exception->getMessage()}");
        } catch (Throwable $exception) {
            $failed++;
            $message = 'Sync Meta Ads gagal. Coba lagi beberapa saat.';
            $profile->update(['last_meta_error' => $message]);

            MetaFlowLog::warning('sync command profile failed', [
                'profile_id' => $profile->id,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            $this->warn("Profile {$profile->id} sync failed: {$message}");
        }
    }

    return $failed > 0 ? 1 : 0;
})->purpose('Sync Meta Ads data for profiles with access tokens');

Schedule::command('t4jam:sync-meta-ads')
    ->cron('0 0-23/5 * * *')
    ->timezone('Asia/Jakarta')
    ->withoutOverlapping(295)
    ->name('t4jam-sync-meta-ads');
