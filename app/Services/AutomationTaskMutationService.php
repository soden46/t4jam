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
        return $task->pending_meta_action === 'pause'
            || str_contains((string) $task->last_log, self::PENDING_PAUSE_MARKER);
    }
}
