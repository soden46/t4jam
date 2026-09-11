<?php

namespace App\Models;

use App\Casts\MetaCredential;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'app_id', 'app_secret', 'access_token', 'meta_user_id', 'meta_user_name', 'meta_connected_at', 'last_meta_sync_at', 'last_meta_error'])]
class T4JamProfile extends Model
{
    protected $hidden = ['access_token', 'app_secret'];

    protected $table = 't4jam_profiles';

    public static function usableForUser(int $userId): self
    {
        return self::firstOrCreate(['user_id' => $userId]);
    }

    public function hasAccessToken(): bool
    {
        return filled($this->access_token);
    }

    protected function casts(): array
    {
        return [
            'access_token' => MetaCredential::class,
            'app_secret' => MetaCredential::class,
            'meta_connected_at' => 'datetime',
            'last_meta_sync_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
