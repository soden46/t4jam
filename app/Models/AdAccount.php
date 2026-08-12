<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
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
}
