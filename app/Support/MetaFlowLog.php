<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;

class MetaFlowLog
{
    public const TAG = '[T4JAM_META_FLOW]';

    public static function info(string $event, array $context = []): void
    {
        Log::info(self::TAG.' '.$event, $context);
    }

    public static function warning(string $event, array $context = []): void
    {
        Log::warning(self::TAG.' '.$event, $context);
    }
}
