<?php

namespace App\Console\Commands;

use App\Events\NoticeSent;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Sends a live notice to a user's open page: a quick way to check real-time delivery end to end.
 */
class NotifyUserCommand extends Command
{
    protected $signature = 'app:notify {email : Who gets it} {message : What it says}';

    protected $description = 'Pop up a live notice on a user\'s open page (over Reverb)';

    public function handle(): int
    {
        $user = User::where('email', strtolower((string) $this->argument('email')))->first();

        if (! $user) {
            $this->error('No account with that email.');

            return self::FAILURE;
        }

        NoticeSent::dispatch($user, (string) $this->argument('message'));
        $this->info("Sent to {$user->email}.");

        return self::SUCCESS;
    }
}
