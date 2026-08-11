<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id',
    'ad_account_id',
    'campaign_id',
    'name',
    'status',
    'campaign_name',
    'campaign_objective',
    'special_ad_categories',
    'campaign_status',
    'adset_name',
    'daily_budget',
    'billing_event',
    'optimization_goal',
    'bid_strategy',
    'start_time',
    'end_time',
    'targeting',
    'ad_name',
    'creative_name',
    'page_id',
    'instagram_actor_id',
    'message',
    'headline',
    'description',
    'link_url',
    'call_to_action',
    'meta_campaign_id',
    'meta_adset_id',
    'meta_creative_id',
    'meta_ad_id',
    'last_error',
    'published_at',
])]
class AdSetup extends Model
{
    protected function casts(): array
    {
        return [
            'special_ad_categories' => 'array',
            'targeting' => 'array',
            'start_time' => 'datetime',
            'end_time' => 'datetime',
            'published_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function adAccount(): BelongsTo
    {
        return $this->belongsTo(AdAccount::class);
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }
}
