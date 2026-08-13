<?php

namespace App\Jobs;

use App\Exceptions\MetaAdsException;
use App\Models\AutomationLog;
use App\Models\AutomationTask;
use App\Models\T4JamProfile;
use App\Services\MetaAdsSyncService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class PushMetaAutomationTaskUpdate implements ShouldQueue
{
    use Queueable;

    private const RATE_LIMIT_ERROR_CODES = [4, 17, 613, 80000, 80001, 80002, 80003, 80004];

    public int $tries = 3;

    public int $timeout = 180;

    public function __construct(
        private readonly int $profileId,
        private readonly string $taskId,
        private readonly string $action,
        private readonly string $baseMessage,
        private readonly ?int $budget = null,
        private readonly ?bool $active = null,
    ) {
        $this->onQueue('meta');
    }

    public function backoff(): array
    {
        return [60, 180, 300];
    }

    public function handle(MetaAdsSyncService $metaSync): void
    {
        $profile = T4JamProfile::query()->find($this->profileId);
        $task = AutomationTask::query()->find($this->taskId);

        if (! $profile || ! $task || ! config('services.meta.enable_writes')) {
            return;
        }

        try {
            $client = $metaSync->client($profile);

            if ($this->action === 'status') {
                $this->pushStatus($client, $task);
            } else {
                $this->pushBudget($client, $task);
            }
        } catch (MetaAdsException $exception) {
            if ($this->isRateLimitError($exception)) {
                $this->markPendingRetry($task, 60);
                $this->release(60);

                return;
            }

            $this->markFailed($task, $this->metaErrorMessage($exception), $exception);

            return;
        } catch (Throwable $exception) {
            $this->markFailed($task, 'Update Meta gagal. Coba lagi beberapa saat.', $exception);

            throw $exception;
        }

        $this->markSucceeded($task);
    }

    private function pushBudget($client, AutomationTask $task): void
    {
        $targetId = $this->targetId($task);

        if (! $targetId || $this->budget === null) {
            throw new MetaAdsException('Target campaign/ad set tidak ditemukan.');
        }

        if ($task->level === 'adset') {
            $client->updateAdSetBudget($targetId, $this->budget);
        } else {
            $client->updateCampaignBudget($targetId, $this->budget);
        }
    }

    private function pushStatus($client, AutomationTask $task): void
    {
        $targetId = $this->targetId($task);

        if (! $targetId || $this->active === null) {
            throw new MetaAdsException('Target campaign/ad set tidak ditemukan.');
        }

        if ($task->level === 'adset') {
            $client->updateAdSetStatus($targetId, $this->active);
        } else {
            $client->updateCampaignStatus($targetId, $this->active);
        }
    }

    private function targetId(AutomationTask $task): ?string
    {
        return $task->level === 'adset'
            ? $task->ad_set_external_id
            : $task->campaign_external_id;
    }

    private function markSucceeded(AutomationTask $task): void
    {
        $message = $this->baseMessage.'; Meta berhasil diupdate.';

        $task->update([
            'last_log' => $message,
            'last_checked_at' => now(),
        ]);

        AutomationLog::create([
            'automation_task_id' => $task->id,
            'messages' => [$message],
        ]);
    }

    private function markPendingRetry(AutomationTask $task, int $seconds): void
    {
        $message = $this->baseMessage.'; Meta rate limit, akan dicoba ulang '.$seconds.' detik lagi.';

        $task->update([
            'last_log' => $message,
            'last_checked_at' => now(),
        ]);

        AutomationLog::create([
            'automation_task_id' => $task->id,
            'messages' => [$message],
        ]);
    }

    private function markFailed(AutomationTask $task, string $message, Throwable $exception): void
    {
        $log = $this->baseMessage.'; '.$message;

        Log::warning('Meta automation update failed', [
            'automation_task_id' => $task->id,
            'action' => $this->action,
            'exception' => $exception::class,
            'message' => $exception->getMessage(),
        ]);

        $task->update([
            'last_log' => $log,
            'last_checked_at' => now(),
        ]);

        AutomationLog::create([
            'automation_task_id' => $task->id,
            'messages' => [$log],
        ]);
    }

    private function metaErrorMessage(MetaAdsException $exception): string
    {
        $message = strtolower($exception->getMessage());

        if ($exception->metaCode === 190 || str_contains($message, 'token')) {
            return 'Access token Meta tidak valid atau sudah expired.';
        }

        if ($exception->httpStatus === 403 || str_contains($message, 'permission')) {
            return 'Akses Meta belum punya izin untuk mengubah campaign/ad set ini.';
        }

        if ($exception->httpStatus === 400) {
            return 'Meta menolak update. Cek minimum budget, status campaign/ad set, dan permission ad account.';
        }

        return 'Update Meta belum berhasil. Coba lagi beberapa saat.';
    }

    private function isRateLimitError(MetaAdsException $exception): bool
    {
        return in_array($exception->metaCode, self::RATE_LIMIT_ERROR_CODES, true);
    }
}
