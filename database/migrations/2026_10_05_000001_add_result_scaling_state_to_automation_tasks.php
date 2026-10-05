<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('automation_tasks', function (Blueprint $table): void {
            $table->unsignedInteger('pause_cpr_limit')->nullable();
            $table->unsignedInteger('scaled_result_count')->nullable();
            $table->string('scaling_period', 64)->nullable();
            $table->string('scaled_conversion', 128)->nullable();
            $table->timestamp('scaling_observed_at')->nullable();
        });

        // Existing result snapshots must not become new conversions on deployment.
        $timezones = DB::table('ad_accounts')->pluck('timezone_name', 'id');
        DB::table('automation_tasks')->orderBy('id')->chunkById(200, function ($tasks) use ($timezones): void {
            foreach ($tasks as $task) {
                if (! $task->last_metrics_synced_at) {
                    continue;
                }
                $at = Carbon::parse($task->last_metrics_synced_at, config('app.timezone'));
                $preset = config('services.meta.automation_insights_date_preset', 'today') ?: 'today';
                $period = $preset === 'today'
                    ? 'today:'.$at->copy()->timezone(($timezones[$task->ad_account_id] ?? null) ?: 'Asia/Jakarta')->toDateString()
                    : $preset;
                DB::table('automation_tasks')->where('id', $task->id)->update([
                    'scaled_result_count' => max(0, (int) $task->current_result),
                    'scaling_period' => $period,
                    'scaled_conversion' => $task->conversion,
                    'scaling_observed_at' => $task->last_metrics_synced_at,
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('automation_tasks', function (Blueprint $table): void {
            $table->dropColumn(['pause_cpr_limit', 'scaled_result_count', 'scaling_period', 'scaled_conversion', 'scaling_observed_at']);
        });
    }
};
