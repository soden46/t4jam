<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ad_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('account_id')->unique();
            $table->string('external_id')->unique();
            $table->string('name');
            $table->string('currency', 8)->default('IDR');
            $table->timestamps();
        });

        Schema::create('campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ad_account_id')->constrained()->cascadeOnDelete();
            $table->string('external_id')->unique();
            $table->string('name');
            $table->string('status')->default('ACTIVE');
            $table->string('budget_type')->default('campaign');
            $table->string('level')->default('campaign');
            $table->unsignedInteger('daily_budget')->default(0);
            $table->unsignedInteger('spend')->default(0);
            $table->unsignedInteger('reach')->default(0);
            $table->unsignedInteger('result')->default(0);
            $table->unsignedInteger('link_click')->default(0);
            $table->unsignedInteger('landing_page_view')->default(0);
            $table->timestamps();
        });

        Schema::create('automation_tasks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('ad_account_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('campaign_id')->nullable()->constrained()->nullOnDelete();
            $table->string('campaign_external_id')->nullable();
            $table->string('campaign_name');
            $table->string('ad_account_name');
            $table->string('level')->default('campaign');
            $table->string('event_flow')->default('lp_to_wa');
            $table->string('system_flow')->default('onhold');
            $table->string('conversion')->default('purchase');
            $table->string('mode')->default('default');
            $table->unsignedInteger('current_budget')->default(100000);
            $table->unsignedInteger('current_spend')->default(0);
            $table->unsignedInteger('current_result')->default(0);
            $table->unsignedInteger('cpr_cap')->default(7000);
            $table->unsignedInteger('starting_budget')->default(100000);
            $table->unsignedInteger('maximum_budget')->default(0);
            $table->unsignedInteger('pause_cpr_cap')->default(70000);
            $table->unsignedSmallInteger('period')->default(10);
            $table->boolean('is_active')->default(true);
            $table->boolean('pause_when_cpr_loss')->default(false);
            $table->boolean('counter_cpr')->default(false);
            $table->boolean('use_on_off')->default(false);
            $table->time('on_time')->default('01:00');
            $table->time('off_time')->default('21:00');
            $table->text('last_log')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('automation_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('automation_task_id')->constrained()->cascadeOnDelete();
            $table->json('messages');
            $table->timestamps();
        });

        Schema::create('interests', function (Blueprint $table) {
            $table->id();
            $table->string('external_id')->unique();
            $table->string('name');
            $table->string('topic')->nullable();
            $table->unsignedBigInteger('audience_size_lower_bound')->default(0);
            $table->unsignedBigInteger('audience_size_upper_bound')->default(0);
            $table->json('path')->nullable();
            $table->text('description')->nullable();
            $table->string('keyword')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('product_categories', function (Blueprint $table) {
            $table->id();
            $table->string('external_id')->unique();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_category_id')->nullable()->constrained()->nullOnDelete();
            $table->string('external_id')->unique();
            $table->string('name');
            $table->unsignedInteger('price')->default(0);
            $table->unsignedInteger('sold')->default(0);
            $table->unsignedInteger('total_review')->default(0);
            $table->decimal('rating', 3, 2)->default(0);
            $table->string('image_url')->nullable();
            $table->string('detail_url')->nullable();
            $table->timestamp('last_added_at')->nullable();
            $table->timestamps();
        });

        Schema::create('t4jam_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('app_id')->nullable();
            $table->string('app_secret')->nullable();
            $table->text('access_token')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('t4jam_profiles');
        Schema::dropIfExists('products');
        Schema::dropIfExists('product_categories');
        Schema::dropIfExists('interests');
        Schema::dropIfExists('automation_logs');
        Schema::dropIfExists('automation_tasks');
        Schema::dropIfExists('campaigns');
        Schema::dropIfExists('ad_accounts');
    }
};
