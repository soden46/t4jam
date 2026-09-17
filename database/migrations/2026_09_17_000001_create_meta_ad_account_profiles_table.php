<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meta_ad_account_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('t4jam_profile_id')->constrained('t4jam_profiles')->cascadeOnDelete();
            $table->foreignId('ad_account_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['t4jam_profile_id', 'ad_account_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meta_ad_account_profiles');
    }
};
