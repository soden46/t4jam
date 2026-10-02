<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ad_accounts', function (Blueprint $table): void {
            $table->string('timezone_name')->nullable();
        });
        Schema::table('automation_tasks', function (Blueprint $table): void {
            $table->timestamp('cpr_paused_at')->nullable();
        });

        // Legacy last_checked_at advances during metric sync, so it cannot prove
        // the pause date. Start their daily recovery clock at deployment.
        DB::table('automation_tasks')->where('is_active', false)
            ->where('last_budget_action', 'pause')->update(['cpr_paused_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('automation_tasks', fn (Blueprint $table) => $table->dropColumn('cpr_paused_at'));
        Schema::table('ad_accounts', fn (Blueprint $table) => $table->dropColumn('timezone_name'));
    }
};
