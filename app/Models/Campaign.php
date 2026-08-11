<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['ad_account_id', 'external_id', 'name', 'status', 'effective_status', 'budget_type', 'level', 'objective', 'daily_budget', 'spend', 'reach', 'result', 'link_click', 'landing_page_view', 'insights_synced_at'])]
class Campaign extends Model
{
    protected function casts(): array
    {
        return ['insights_synced_at' => 'datetime'];
    }

    public function adAccount(): BelongsTo
    {
        return $this->belongsTo(AdAccount::class);
    }

    public function automationTasks(): HasMany
    {
        return $this->hasMany(AutomationTask::class);
    }
}
