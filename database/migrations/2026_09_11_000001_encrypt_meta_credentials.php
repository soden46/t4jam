<?php

use App\Casts\MetaCredential;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('t4jam_profiles', function (Blueprint $table): void {
            $table->text('app_secret')->nullable()->change();
        });

        DB::table('t4jam_profiles')->orderBy('id')->chunkById(100, function ($profiles): void {
            $cast = new MetaCredential;
            foreach ($profiles as $profile) {
                $updates = [];
                foreach (['access_token', 'app_secret'] as $key) {
                    $value = $profile->{$key};
                    if (filled($value)) {
                        $plain = $cast->get(null, $key, $value, []);
                        $updates[$key] = $cast->set(null, $key, $plain, []);
                    }
                }
                if ($updates !== []) {
                    DB::table('t4jam_profiles')->where('id', $profile->id)->update($updates);
                }
            }
        });
    }

    public function down(): void
    {
        // Intentionally retain ciphertext and TEXT capacity on rollback.
    }
};
