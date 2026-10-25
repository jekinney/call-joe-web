<?php

namespace App\Jobs;

use App\Models\Call;
use App\Services\Outreach;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * An incoming call that's still ringing after Outreach::RING_SECONDS becomes a missed call.
 */
class ExpireRinging implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $callId) {}

    public function handle(Outreach $outreach): void
    {
        if ($call = Call::find($this->callId)) {
            $outreach->missed($call);
        }
    }
}
