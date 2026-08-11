<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['external_id', 'name', 'topic', 'audience_size_lower_bound', 'audience_size_upper_bound', 'path', 'description', 'keyword'])]
class Interest extends Model
{
    protected function casts(): array
    {
        return ['path' => 'array'];
    }
}
