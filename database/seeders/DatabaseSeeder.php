<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Local development data. Safe to run again.
     */
    public function run(): void
    {
        // A local test account (password: "password", from the factory). Never used outside development.
        if (! User::where('email', 'test@example.com')->exists()) {
            User::factory()->create(['name' => 'Test User', 'email' => 'test@example.com']);
        }

        $this->call([OwnerSeeder::class, ContactSeeder::class]);
    }
}
