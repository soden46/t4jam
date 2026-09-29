<?php

namespace App\Services;

use App\Models\AutomationTask;
use Closure;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;

class AutomationTaskMutationService
{
    public const LOCK_SECONDS = 120;

    public const PENDING_PAUSE_MARKER = 'Pending pause (rate limited).';

    public function key(AutomationTask $task): string
    {
        return 'automation-task-mutation:'.$task->id;
    }

    public function lock(AutomationTask $task): Lock
    {
        return Cache::lock($this->key($task), self::LOCK_SECONDS);
    }

    public function runLocked(AutomationTask $task, Closure $callback): mixed
    {
        return $this->lock($task)->get($callback);
    }

    public function hasPendingPause(AutomationTask $task): bool
    {
        if ($task->pending_meta_action === 'pause') {
            return true;
        }

        // Older rows may predate the canonical marker. Only honor their display
        // marker while an actual verification is still due; a historical
        // last_log alone must never keep a resolved pause logically pending.
        return $task->pending_meta_action === null
            && $task->meta_verification_due_at !== null
            && str_contains((string) $task->last_log, self::PENDING_PAUSE_MARKER);
    }

    public function clearPendingPause(AutomationTask $task): void
    {
        $hasMarker = str_contains((string) $task->last_log, self::PENDING_PAUSE_MARKER);

        if (! $this->hasPendingPause($task) && ! $hasMarker) {
            return;
        }

        $changes = [
            'pending_meta_action' => null,
            'meta_verification_due_at' => null,
        ];

        if ($hasMarker) {
            $changes['last_log'] = 'Pending pause telah diselesaikan.';
        }

        $task->update($changes);
    }
}
