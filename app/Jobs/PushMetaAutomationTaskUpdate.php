<?php

namespace App\Jobs;

use App\Exceptions\MetaAdsException;
use App\Jobs\Concerns\RetriesMetaRequests;
use App\Models\AdSet;
use App\Models\AutomationLog;
use App\Models\AutomationTask;
use App\Models\Campaign;
use App\Models\T4JamProfile;
use App\Services\AutomationTaskMutationService;
use App\Services\MetaAdsSyncService;
use App\Support\MetaFlowLog;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;

class PushMetaAutomationTaskUpdate implements ShouldQueue
{
    use Queueable;
    use RetriesMetaRequests;

    private const LOCK_CONTENTION_DELAY_SECONDS = 5;

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

        if (! $profile || ! $task || $task->user_id !== $profile->user_id || ! config('services.meta.enable_writes')) {
            MetaFlowLog::warning('queued automation meta update skipped', [
                'profile_id' => $this->profileId,
                'automation_task_id' => $this->taskId,
                'action' => $this->action,
                'has_profile' => (bool) $profile,
                'has_task' => (bool) $task,
                'writes_enabled' => (bool) config('services.meta.enable_writes'),
            ]);

            return;
        }

        try {
            $outcome = app(AutomationTaskMutationService::class)->runLocked($task, function () use ($profile, $task, $metaSync): array {
                $task->refresh();
                $target = $this->target($task);
                $target?->refresh();

                if (! $target || ! $this->stillRelevant($task) || ! config('services.meta.enable_writes')) {
                    MetaFlowLog::info('queued automation meta update skipped as stale', [
                        'automation_task_id' => $task->id,
                        'action' => $this->action,
                    ]);

                    return ['skipped' => true];
                }

                if ($this->action === 'status' && $this->active && app(AutomationTaskMutationService::class)->hasPendingPause($task)) {
                    MetaFlowLog::info('queued automation resume skipped while pause is pending', [
                        'automation_task_id' => $task->id,
                    ]);

                    return ['skipped' => true];
                }

                if ($this->action !== 'status' && app(AutomationTaskMutationService::class)->hasPendingPause($task)) {
                    MetaFlowLog::info('queued automation budget update skipped while pause is pending', [
                        'automation_task_id' => $task->id,
                    ]);

                    return ['skipped' => true];
                }

                if ($this->alreadyApplied($target)) {
                    return ['skipped' => true];
                }

                MetaFlowLog::info('queued automation meta update started', [
                    'profile_id' => $profile->id,
                    'automation_task_id' => $task->id,
                    'action' => $this->action,
                    'queue' => 'meta',
                ]);

                $client = $metaSync->client($profile);
                if ($this->action === 'status') {
                    $this->pushStatus($client, $task);
                } else {
                    $this->pushBudget($client, $task);
                }

                $this->syncLocalSuccess($task, $target);
                $this->markSucceeded($task);

                return ['completed' => true];
            });

            if ($outcome === false) {
                MetaFlowLog::info('queued automation meta update deferred by task lock', [
                    'automation_task_id' => $task->id,
                    'action' => $this->action,
                    'retry_after_seconds' => self::LOCK_CONTENTION_DELAY_SECONDS,
                ]);

                $this->release(self::LOCK_CONTENTION_DELAY_SECONDS);

                return;
            }
            if ($outcome['skipped'] ?? false) {
                return;
            }
        } catch (MetaAdsException $exception) {
            if ($exception->retryable() && $this->attempts() < $this->tries) {
                $this->markPendingRetry($task, $exception->retryDelay($this->attempts()));
            } else {
                $this->markFailed($task, $this->metaErrorMessage($exception), $exception);
            }
            $this->retryOrFail($exception);

            return;
        } catch (Throwable $exception) {
            $this->markFailed($task, 'Update Meta gagal. Coba lagi beberapa saat.', $exception);

            throw $exception;
        }

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

    private function target(AutomationTask $task): Campaign|AdSet|null
    {
        if ($task->level === 'adset') {
            return $task->ad_set_external_id
                ? AdSet::query()->where('external_id', $task->ad_set_external_id)->first()
                : null;
        }

        return $task->campaign_external_id
            ? Campaign::query()->where('external_id', $task->campaign_external_id)->first()
            : null;
    }

    private function stillRelevant(AutomationTask $task): bool
    {
        if ($this->action === 'status') {
            return $this->active !== null && (bool) $task->is_active === $this->active;
        }

        return $this->budget !== null && (int) $task->current_budget === $this->budget;
    }

    private function alreadyApplied(Campaign|AdSet $target): bool
    {
        if ($this->action === 'status') {
            return $target->status === ($this->active ? 'ACTIVE' : 'PAUSED');
        }

        return (int) $target->daily_budget === $this->budget;
    }

    private function syncLocalSuccess(AutomationTask $task, Campaign|AdSet $target): void
    {
        DB::transaction(function () use ($task, $target): void {
            if ($this->action === 'status') {
                $status = $this->active ? 'ACTIVE' : 'PAUSED';
                $target->update([
                    'status' => $status,
                    'effective_status' => $status,
                ]);
                $task->update(['is_active' => $this->active]);

                return;
            }

            $target->update(['daily_budget' => $this->budget]);
            $task->update(['current_budget' => $this->budget]);
        });
    }

    private function markSucceeded(AutomationTask $task): void
    {
        $message = $this->baseMessage.'; Meta berhasil diupdate.';

        MetaFlowLog::info('queued automation meta update finished', [
            'automation_task_id' => $task->id,
            'action' => $this->action,
            'target_id' => $this->targetId($task),
        ]);

        $task->update([
            'last_log' => $message,
        ]);

        AutomationLog::create([
            'automation_task_id' => $task->id,
            'messages' => [$message],
        ]);
    }

    private function markPendingRetry(AutomationTask $task, int $seconds): void
    {
        $message = $this->baseMessage.'; Meta sementara gagal, akan dicoba ulang '.$seconds.' detik lagi.';

        MetaFlowLog::warning('queued automation meta update delayed by rate limit', [
            'automation_task_id' => $task->id,
            'action' => $this->action,
            'target_id' => $this->targetId($task),
            'retry_after_seconds' => $seconds,
        ]);

        $task->update([
            'last_log' => $message,
        ]);

        AutomationLog::create([
            'automation_task_id' => $task->id,
            'messages' => [$message],
        ]);
    }

    private function markFailed(AutomationTask $task, string $message, Throwable $exception): void
    {
        $log = $this->baseMessage.'; '.$message;

        MetaFlowLog::warning('queued automation meta update failed', [
            'automation_task_id' => $task->id,
            'action' => $this->action,
            'exception' => $exception::class,
        ]);

        $task->update([
            'last_log' => $log,
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
            if ($exception->providerMessage) {
                return 'Meta menolak update: '.$exception->providerMessage;
            }

            return 'Meta menolak update. Cek minimum budget, status campaign/ad set, dan permission ad account.';
        }

        return 'Update Meta belum berhasil. Coba lagi beberapa saat.';
    }
}
