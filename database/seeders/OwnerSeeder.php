<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * The owner's admin account, from .env (OWNER_NAME, OWNER_EMAIL, OWNER_PASSWORD, optional OWNER_BIRTH_DATE and
 * OWNER_TIMEZONE), so no password is ever in the code. Works in any environment:
 * `php artisan db:seed --class=OwnerSeeder`. Running it again keeps the account's password and makes sure it's an
 * admin.
 */
class OwnerSeeder extends Seeder
{
    public function run(): void
    {
        $owner = config('app.owner');
        if (empty($owner['email']) || empty($owner['password'])) {
            $this->command->warn('Skipped the owner account: set OWNER_EMAIL and OWNER_PASSWORD in .env.');

            return;
        }

        $user = User::firstOrNew(['email' => strtolower($owner['email'])]);
        if (! $user->exists) {
            $user->password = Hash::make($owner['password']);
        }

        $user->forceFill([
            'name' => $owner['name'] ?: 'Owner',
            'timezone' => $owner['timezone'] ?: 'UTC',
            'date_of_birth' => $owner['birth_date'] ?: $user->date_of_birth,
            'role_id' => Role::owner()->id,
            'email_verified_at' => $user->email_verified_at ?? now(),
            'terms_accepted_at' => now(),
            'terms_version' => config('legal.terms_version'),
        ])->save();

        $this->command->info("Owner account ready: {$user->email} (admin).");
    }
}
