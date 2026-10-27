<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Owners are only ever made from the command line, never through the website. (Other admin roles can also be given in
 * Admin > Roles by someone allowed to manage roles.)
 */
class MakeAdminCommand extends Command
{
    protected $signature = 'app:make-admin {email : The account to change} {--role=Owner : The role to give} {--revoke : Take admin rights away instead}';

    protected $description = 'Give an account an admin role (the Owner role by default), or take admin rights away with --revoke';

    public function handle(): int
    {
        $user = User::where('email', strtolower((string) $this->argument('email')))->first();

        if (! $user) {
            $this->error('No account with that email.');

            return self::FAILURE;
        }

        if ($this->option('revoke')) {
            $user->forceFill(['role_id' => null])->save();
            $this->info("{$user->email} is no longer an admin.");

            return self::SUCCESS;
        }

        $name = (string) $this->option('role');
        $role = strcasecmp($name, 'Owner') === 0 ? Role::owner() : Role::where('name', $name)->first();

        if (! $role) {
            $this->error('No role called '.$name.'. Roles: '.Role::orderBy('name')->pluck('name')->implode(', ').'.');

            return self::FAILURE;
        }

        $user->forceFill(['role_id' => $role->id])->save();
        $this->info($role->is_owner ? "{$user->email} is now an admin." : "{$user->email} is now an admin with the {$role->name} role.");

        return self::SUCCESS;
    }
}
