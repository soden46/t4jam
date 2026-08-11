<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'id',
    'ad_account_id',
    'campaign_id',
    'campaign_external_id',
    'campaign_name',
    'ad_account_name',
    'level',
    'event_flow',
    'system_flow',
    'conversion',
    'mode',
    'current_budget',
    'current_spend',
    'current_result',
    'cpr_cap',
    'starting_budget',
    'maximum_budget',
    'pause_cpr_cap',
    'period',
    'is_active',
    'pause_when_cpr_loss',
    'counter_cpr',
    'use_on_off',
    'on_time',
    'off_time',
    'last_log',
    'last_checked_at',
])]
class AutomationTask extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'pause_when_cpr_loss' => 'boolean',
            'counter_cpr' => 'boolean',
            'use_on_off' => 'boolean',
            'last_checked_at' => 'datetime',
        ];
    }

    public function adAccount(): BelongsTo
    {
        return $this->belongsTo(AdAccount::class);
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function logs(): HasMany
    {
        return $this->hasMany(AutomationLog::class);
    }
}
