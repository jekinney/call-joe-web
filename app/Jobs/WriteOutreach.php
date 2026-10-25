<?php

namespace App\Jobs;

use App\Events\NoticeSent;
use App\Models\User;
use App\Services\Catchup;
use App\Services\Outreach;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Writes a user's due reach-outs while they're online, in order (App\Services\Outreach::deliverDue). One at a time
 * per user, so a backlog is never written twice. After coming back from a while away, it ends with a
 * "Welcome back! Mom texted you 3 times." notice.
 */
class WriteOutreach implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public int $userId, public bool $welcome = false) {}

    public function uniqueId(): string
    {
        return (string) $this->userId;
    }

    public function handle(Outreach $outreach, Catchup $catchup): void
    {
        $user = User::find($this->userId);
        if (! $user) {
            return;
        }

        $outreach->deliverDue($user);
        if ($this->welcome && ($message = $catchup->welcomeMessage($user))) {
            NoticeSent::dispatch($user, $message);
        }
    }
}
