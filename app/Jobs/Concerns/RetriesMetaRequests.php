<?php

namespace App\Jobs\Concerns;

use App\Exceptions\MetaAdsException;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

trait RetriesMetaRequests
{
    public int $maxExceptions = 3;

    private ?int $metaRetryDeadline = null;

    private ?string $metaRetryId = null;

    protected function initializeMetaRetries(): void
    {
        $this->metaRetryDeadline = now()->addHours(4)->getTimestamp();
        $this->metaRetryId = (string) Str::uuid();
    }

    public function retryUntil(): ?int
    {
        // Legacy payloads retain their original attempt limit.
        return $this->metaRetryDeadline;
    }

    public function resetMetaRetryWindow(): void
    {
        $this->initializeMetaRetries();
    }

    protected function retryOrFail(MetaAdsException $exception): ?int
    {
        $withinDeadline = $this->metaRetryDeadline === null || now()->getTimestamp() < $this->metaRetryDeadline;
        if ($exception->retryable() && $withinDeadline) {
            // A local cooldown rejects the request before HTTP; it is not a provider failure.
            if ($exception->cooldownActive && $this->metaRetryId !== null) {
                $delay = max(1, $exception->retryAfter ?? 60);
            } else {
                $failures = $this->attempts();
                if ($this->metaRetryId !== null) {
                    $key = 'meta-job-failures:'.$this->metaRetryId;
                    Cache::add($key, 0, max(1, $this->metaRetryDeadline - now()->getTimestamp()) + 1);
                    $failures = (int) Cache::increment($key);
                }

                if ($failures >= $this->tries) {
                    $this->fail($exception);

                    return null;
                }

                $delay = $exception->retryDelay($failures);
            }

            $this->releaseMetaRetry($delay);

            return $delay;
        }

        $this->fail($exception);

        return null;
    }

    private function releaseMetaRetry(int $delay): void
    {
        $connection = config('queue.default');
        $driver = config("queue.connections.$connection.driver");
        if ($this->job instanceof SyncJob && in_array($driver, ['database', 'redis', 'sqs', 'beanstalkd'], true)) {
            // SyncJob::release() cannot persist after-response retries. Carry them to the worker queue.
            $retry = clone $this;
            $retry->job = null;
            $retry->onConnection($connection);
            Queue::connection($connection)->later($delay, $retry, '', $this->queue ?? 'meta');
        }

        $this->release($delay);
    }
}
