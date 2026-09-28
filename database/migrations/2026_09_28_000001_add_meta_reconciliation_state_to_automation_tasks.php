<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('automation_tasks', function (Blueprint $table): void {
            $table->string('pending_meta_action', 32)->nullable()->after('last_budget_action');
            $table->timestamp('meta_verification_due_at')->nullable()->after('pending_meta_action');
        });
    }

    public function down(): void
    {
        Schema::table('automation_tasks', function (Blueprint $table): void {
            $table->dropColumn(['pending_meta_action', 'meta_verification_due_at']);
        });
    }
};
