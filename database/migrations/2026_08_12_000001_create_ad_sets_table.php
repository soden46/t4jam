<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ad_sets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ad_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->string('external_id')->unique();
            $table->string('name');
            $table->string('status')->default('ACTIVE');
            $table->string('effective_status')->nullable();
            $table->unsignedInteger('daily_budget')->default(0);
            $table->unsignedInteger('spend')->default(0);
            $table->unsignedInteger('reach')->default(0);
            $table->unsignedInteger('result')->default(0);
            $table->unsignedInteger('link_click')->default(0);
            $table->unsignedInteger('landing_page_view')->default(0);
            $table->timestamp('insights_synced_at')->nullable();
            $table->timestamps();
        });

        Schema::table('automation_tasks', function (Blueprint $table) {
            $table->foreignId('ad_set_id')->nullable()->after('campaign_id')->constrained()->nullOnDelete();
            $table->string('ad_set_external_id')->nullable()->after('campaign_external_id');
        });
    }

    public function down(): void
    {
        Schema::table('automation_tasks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('ad_set_id');
            $table->dropColumn('ad_set_external_id');
        });

        Schema::dropIfExists('ad_sets');
    }
};
