<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('automation_tasks', function (Blueprint $table): void {
            $table->unsignedTinyInteger('meta_reconciliation_failure_count')->default(0)->after('meta_verification_due_at');
            $table->index('meta_verification_due_at', 'automation_tasks_reconcile_due_idx');
            $table->index(['pending_meta_action', 'meta_verification_due_at'], 'automation_tasks_reconcile_pending_idx');
            $table->index(['is_active', 'last_metrics_synced_at'], 'automation_tasks_reconcile_fresh_idx');
        });
    }

    public function down(): void
    {
        Schema::table('automation_tasks', function (Blueprint $table): void {
            $table->dropIndex('automation_tasks_reconcile_due_idx');
            $table->dropIndex('automation_tasks_reconcile_pending_idx');
            $table->dropIndex('automation_tasks_reconcile_fresh_idx');
            $table->dropColumn('meta_reconciliation_failure_count');
        });
    }
};
