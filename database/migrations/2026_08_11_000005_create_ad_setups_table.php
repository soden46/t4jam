<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ad_setups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ad_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('campaign_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('status')->default('draft');
            $table->string('campaign_name');
            $table->string('campaign_objective')->default('OUTCOME_SALES');
            $table->json('special_ad_categories')->nullable();
            $table->string('campaign_status')->default('PAUSED');
            $table->string('adset_name');
            $table->unsignedInteger('daily_budget');
            $table->string('billing_event')->default('IMPRESSIONS');
            $table->string('optimization_goal')->default('OFFSITE_CONVERSIONS');
            $table->string('bid_strategy')->default('LOWEST_COST_WITHOUT_CAP');
            $table->timestamp('start_time')->nullable();
            $table->timestamp('end_time')->nullable();
            $table->json('targeting');
            $table->string('ad_name');
            $table->string('creative_name');
            $table->string('page_id');
            $table->string('instagram_actor_id')->nullable();
            $table->text('message');
            $table->string('headline');
            $table->string('description')->nullable();
            $table->string('link_url');
            $table->string('call_to_action')->default('LEARN_MORE');
            $table->string('meta_campaign_id')->nullable();
            $table->string('meta_adset_id')->nullable();
            $table->string('meta_creative_id')->nullable();
            $table->string('meta_ad_id')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ad_setups');
    }
};
