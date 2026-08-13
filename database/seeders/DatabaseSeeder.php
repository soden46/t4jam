<?php

namespace Database\Seeders;

use App\Models\T4JamProfile;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $user = User::firstOrCreate(
            ['email' => 'admin@t4jam.local'],
            [
                'name' => 'T4Jam Admin',
                'password' => Hash::make('password'),
            ]
        );

        T4JamProfile::firstOrCreate(['user_id' => $user->id]);
    }
}
