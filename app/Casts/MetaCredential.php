<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Support\Facades\Crypt;

class MetaCredential implements CastsAttributes
{
    private const PREFIX = 'encrypted:v1:';

    public function get($model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }

        // A marked ciphertext must decrypt successfully; never treat a wrong key as plaintext.
        return str_starts_with($value, self::PREFIX)
            ? Crypt::decryptString(substr($value, strlen(self::PREFIX)))
            : $value;
    }

    public function set($model, string $key, mixed $value, array $attributes): ?string
    {
        return filled($value) ? self::PREFIX.Crypt::encryptString($value) : null;
    }
}
