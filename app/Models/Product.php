<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['product_category_id', 'external_id', 'name', 'price', 'sold', 'total_review', 'rating', 'image_url', 'detail_url', 'last_added_at'])]
class Product extends Model
{
    protected function casts(): array
    {
        return [
            'rating' => 'float',
            'last_added_at' => 'datetime',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, 'product_category_id');
    }
}
