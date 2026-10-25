<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

/**
 * Admin rights are only ever granted from the command line, never through the website.
 */
class MakeAdminCommand extends Command
{
    protected $signature = 'app:make-admin {email : The account to change} {--revoke : Take admin rights away instead}';

    protected $description = 'Give an account admin rights (/admin), or take them away with --revoke';

    public function handle(): int
    {
        $user = User::where('email', strtolower((string) $this->argument('email')))->first();

        if (! $user) {
            $this->error('No account with that email.');

            return self::FAILURE;
        }

        $user->forceFill(['is_admin' => ! $this->option('revoke')])->save();
        $this->info($user->is_admin ? "{$user->email} is now an admin." : "{$user->email} is no longer an admin.");

        return self::SUCCESS;
    }
}
