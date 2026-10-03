<?php

namespace App\Listeners;

use App\Jobs\PublishMetaAdSetup;
use App\Jobs\PushMetaAutomationTaskUpdate;
use App\Jobs\SyncMetaAdsAccount;
use App\Jobs\SyncMetaAdsProfile;
use Illuminate\Queue\Events\JobRetryRequested;

class ResetMetaJobRetryWindow
{
    public function handle(JobRetryRequested $event): void
    {
        $payload = $event->payload();
        $class = $payload['displayName'] ?? null;
        if (! in_array($class, [SyncMetaAdsProfile::class, SyncMetaAdsAccount::class,
            PublishMetaAdSetup::class, PushMetaAutomationTaskUpdate::class], true)) {
            return;
        }

        $command = $payload['data']['command'] ?? null;
        if (! is_string($command) || ! str_starts_with($command, 'O:')) {
            return;
        }
        $job = unserialize($command, ['allowed_classes' => [$class]]);
        if (! $job instanceof $class) {
            return;
        }

        // An explicit queue:retry starts a new bounded window, preserving all job IDs/arguments.
        $job->resetMetaRetryWindow();
        $payload['data']['command'] = serialize($job);
        $payload['retryUntil'] = $job->retryUntil();
        $payload['maxExceptions'] = $job->maxExceptions;
        $event->job->payload = json_encode($payload, JSON_THROW_ON_ERROR);
    }
}
