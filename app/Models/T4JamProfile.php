<?php

namespace App\Models;

use App\Support\MetaFlowLog;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'app_id', 'app_secret', 'access_token', 'meta_user_id', 'meta_user_name', 'meta_connected_at', 'last_meta_sync_at', 'last_meta_error'])]
class T4JamProfile extends Model
{
    protected $table = 't4jam_profiles';

    public static function usableForUser(int $userId): self
    {
        $profile = self::firstOrCreate(['user_id' => $userId]);

        if ($profile->hasAccessToken()) {
            return $profile;
        }

        $fallback = self::query()
            ->whereNotNull('access_token')
            ->where('access_token', '<>', '')
            ->latest('updated_at')
            ->first();

        if ($fallback) {
            MetaFlowLog::info('meta credential fallback selected', [
                'user_id' => $userId,
                'profile_id' => $profile->id,
                'fallback_profile_id' => $fallback->id,
            ]);
        }

        return $fallback ?? $profile;
    }

    public function hasAccessToken(): bool
    {
        return filled($this->access_token);
    }

    protected function casts(): array
    {
        return [
            'meta_connected_at' => 'datetime',
            'last_meta_sync_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
