<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ad_accounts', function (Blueprint $table) {
            $table->unsignedSmallInteger('account_status')->nullable()->after('currency');
        });

        Schema::table('campaigns', function (Blueprint $table) {
            $table->string('effective_status')->nullable()->after('status');
            $table->string('objective')->nullable()->after('level');
            $table->timestamp('insights_synced_at')->nullable()->after('landing_page_view');
        });

        Schema::table('t4jam_profiles', function (Blueprint $table) {
            $table->string('meta_user_id')->nullable()->after('user_id');
            $table->string('meta_user_name')->nullable()->after('meta_user_id');
            $table->timestamp('meta_connected_at')->nullable()->after('access_token');
            $table->timestamp('last_meta_sync_at')->nullable()->after('meta_connected_at');
            $table->text('last_meta_error')->nullable()->after('last_meta_sync_at');
        });
    }

    public function down(): void
    {
        Schema::table('t4jam_profiles', function (Blueprint $table) {
            $table->dropColumn(['meta_user_id', 'meta_user_name', 'meta_connected_at', 'last_meta_sync_at', 'last_meta_error']);
        });

        Schema::table('campaigns', function (Blueprint $table) {
            $table->dropColumn(['effective_status', 'objective', 'insights_synced_at']);
        });

        Schema::table('ad_accounts', function (Blueprint $table) {
            $table->dropColumn('account_status');
        });
    }
};
