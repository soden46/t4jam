<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class MetaQueueStatus extends Command
{
    protected $signature = 't4jam:queue-status {--worker-timeout=700}';

    protected $description = 'Inspect database queues and retry timing without running jobs or calling Meta';

    public function handle(): int
    {
        $connection = config('queue.default');
        $settings = config("queue.connections.$connection", []);
        if (($settings['driver'] ?? null) !== 'database') {
            $this->error('Pemeriksaan ini memerlukan queue driver database.');

            return self::FAILURE;
        }

        $workerTimeout = (int) $this->option('worker-timeout');
        $minimumTimeout = 650; // Longest Meta job timeout (SyncMetaAdsProfile).
        $validTiming = $workerTimeout >= $minimumTimeout && (int) ($settings['retry_after'] ?? 0) > $workerTimeout;
        $jobs = DB::connection($settings['connection'] ?? null)->table($settings['table'] ?? 'jobs');
        $queues = (clone $jobs)->selectRaw('queue, COUNT(*) AS total')->groupBy('queue')->get();
        $sample = (clone $jobs)->whereIn('queue', ['meta', 'default'])->orderBy('id')->limit(30)
            ->get(['id', 'queue', 'payload', 'attempts', 'reserved_at', 'available_at', 'created_at'])
            ->map(fn ($job) => [
                'id' => $job->id, 'queue' => $job->queue,
                'type' => json_decode($job->payload, true)['displayName'] ?? 'unknown',
                'attempts' => $job->attempts, 'reserved_at' => $job->reserved_at,
                'available_at' => $job->available_at, 'created_at' => $job->created_at,
            ]);

        $this->line(json_encode([
            'queue_connection' => $connection, 'queue_name' => $settings['queue'] ?? null,
            'required_worker_queues' => 'meta,default',
            'worker_timeout' => $workerTimeout, 'retry_after' => $settings['retry_after'] ?? null,
            'timing_valid' => $validTiming,
            'meta_writes' => (bool) config('services.meta.enable_writes'),
            'queues' => $queues, 'jobs' => $sample,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        if (! $validTiming) {
            $this->error('Worker timeout harus >= 650 dan retry_after harus lebih besar dari worker timeout.');
        }

        return $validTiming ? self::SUCCESS : self::FAILURE;
    }
}
