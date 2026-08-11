<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['automation_task_id', 'messages'])]
class AutomationLog extends Model
{
    protected function casts(): array
    {
        return ['messages' => 'array'];
    }

    public function automationTask(): BelongsTo
    {
        return $this->belongsTo(AutomationTask::class);
    }
}
