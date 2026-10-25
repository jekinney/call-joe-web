<?php

namespace App\Console\Commands;

use App\Services\Outreach;
use Illuminate\Console\Command;

/**
 * Runs every minute (routes/console.php): contacts update their worry and plan reach-outs, then due calls ring or
 * become missed calls, and online users' texts get written.
 */
class RunOutreachCommand extends Command
{
    protected $signature = 'outreach:run';

    protected $description = 'Let contacts reach out on their own: plan texts and calls, then deliver what is due';

    public function handle(Outreach $outreach): int
    {
        $planned = $outreach->planAll();
        $users = $outreach->deliverAll();
        $this->info("Planned {$planned} reach-outs; delivered for {$users} users.");

        return self::SUCCESS;
    }
}
