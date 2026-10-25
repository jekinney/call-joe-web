<?php

namespace App\Http\Controllers;

use App\Models\Call;
use App\Services\Outreach;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Decline on an incoming call (resources/js/ringing.js): it stops ringing and becomes a missed call.
 */
class DeclineCallController extends Controller
{
    public function __invoke(Request $request, Call $call, Outreach $outreach): Response
    {
        abort_unless($call->userContact->user_id === $request->user()?->id, 404);
        $outreach->missed($call);

        return response()->noContent();
    }
}
