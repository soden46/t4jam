<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['account_id', 'external_id', 'name', 'currency', 'account_status'])]
class AdAccount extends Model
{
    public function campaigns(): HasMany
    {
        return $this->hasMany(Campaign::class);
    }

    public function adSets(): HasMany
    {
        return $this->hasMany(AdSet::class);
    }

    public function profiles(): BelongsToMany
    {
        return $this->belongsToMany(T4JamProfile::class, 'meta_ad_account_profiles', 'ad_account_id', 't4jam_profile_id')
            ->withTimestamps();
    }
}
