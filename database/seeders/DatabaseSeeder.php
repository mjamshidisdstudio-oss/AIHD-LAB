<?php

namespace Database\Seeders;

use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        // Local/dev only: the seeded operator account for the admin Nuxt
        // client (see admin/README.md). A real deployment provisions admin
        // accounts through its own process, never this seeder.
        User::factory()->admin()->create([
            'name' => 'Mehdi Rostami',
            'email' => 'admin@aihd.lab',
            'password' => Hash::make('password'),
        ]);

        $this->call([
            SeasonalViewsSeeder::class,
        ]);
    }
}
