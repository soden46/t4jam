<?php

namespace App\Http\Middleware;

use App\Models\T4JamProfile;
use App\Support\MetaFlowLog;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class TriggerPostDeployMetaSync
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $this->scheduleOnceAfterDeploy($request);

        return $response;
    }

    private function scheduleOnceAfterDeploy(Request $request): void
    {
        if (! config('services.meta.auto_post_deploy_sync')) {
            return;
        }

        if ($request->is('up') || $request->is('meta/webhook') || $request->is('meta/webhook/*')) {
            return;
        }

        if (! T4JamProfile::query()->whereNotNull('access_token')->where('access_token', '<>', '')->exists()) {
            return;
        }

        $releaseKey = $this->releaseKey();
        $cacheKey = 't4jam:auto-post-deploy-sync:last-release';

        if (Cache::get($cacheKey) === $releaseKey) {
            return;
        }

        $lock = Cache::lock('t4jam:auto-post-deploy-sync:lock', 600);

        if (! $lock->get()) {
            return;
        }

        try {
            if (Cache::get($cacheKey) === $releaseKey) {
                return;
            }

            Cache::forever($cacheKey, $releaseKey);

            app()->terminating(function () use ($releaseKey): void {
                $options = [];
                $profileId = config('services.meta.auto_post_deploy_profile_id');

                if ($profileId) {
                    $options['--profile_id'] = $profileId;
                }

                if (config('services.meta.auto_post_deploy_configure_webhook')) {
                    $options['--configure-webhook'] = true;
                }

                try {
                    $status = Artisan::call('t4jam:post-deploy-sync', $options);
                    MetaFlowLog::info('auto post deploy sync finished', [
                        'release' => $releaseKey,
                        'exit_code' => $status,
                    ]);
                } catch (Throwable $exception) {
                    MetaFlowLog::warning('auto post deploy sync failed', [
                        'release' => $releaseKey,
                        'exception' => $exception::class,
                    ]);
                }
            });
        } finally {
            $lock->release();
        }
    }

    private function releaseKey(): string
    {
        $headPath = base_path('.git/HEAD');

        if (is_file($headPath)) {
            $head = trim((string) file_get_contents($headPath));

            if (str_starts_with($head, 'ref: ')) {
                $refPath = base_path('.git/'.trim(substr($head, 5)));

                if (is_file($refPath)) {
                    return 'git:'.trim((string) file_get_contents($refPath));
                }
            }

            if ($head !== '') {
                return 'git:'.$head;
            }
        }

        $composerLock = base_path('composer.lock');
        $fallbackHash = is_file($composerLock) ? md5_file($composerLock) : 'no-composer-lock';

        return 'files:'.$fallbackHash.':'.filemtime(base_path('routes/console.php'));
    }
}
