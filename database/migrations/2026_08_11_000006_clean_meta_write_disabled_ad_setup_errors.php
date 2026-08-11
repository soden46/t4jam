<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('ad_setups')
            ->where('status', 'ready')
            ->where('last_error', 'Meta write disabled. Set META_ADS_ENABLE_WRITES=true to publish.')
            ->update(['last_error' => null]);
    }

    public function down(): void
    {
        //
    }
};
