<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('automation_tasks', function (Blueprint $table): void {
            $table->timestamp('last_budget_changed_at')->nullable()->after('last_checked_at');
            $table->unsignedInteger('last_budget_before')->nullable()->after('last_budget_changed_at');
            $table->string('last_budget_action', 32)->nullable()->after('last_budget_before');
        });
    }

    public function down(): void
    {
        Schema::table('automation_tasks', function (Blueprint $table): void {
            $table->dropColumn(['last_budget_changed_at', 'last_budget_before', 'last_budget_action']);
        });
    }
};
