<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('automation_tasks', function (Blueprint $table): void {
            $table->unsignedInteger('pause_cpr_cap')->default(5000)->change();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
        });
        // Only an installation with exactly one user has an unambiguous legacy owner.
        if (DB::table('users')->count() === 1) {
            DB::table('automation_tasks')->update(['user_id' => DB::table('users')->value('id')]);
        }
        foreach (['campaigns', 'ad_sets'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->json('conversion_results')->nullable();
            });
        }
        Schema::table('ad_setups', function (Blueprint $table): void {
            $table->string('pending_meta_step')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('ad_setups', fn (Blueprint $table) => $table->dropColumn('pending_meta_step'));
        foreach (['campaigns', 'ad_sets'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropColumn('conversion_results'));
        }
        Schema::table('automation_tasks', fn (Blueprint $table) => $table->dropConstrainedForeignId('user_id'));
    }
};
