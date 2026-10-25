<?php

namespace App\Http\Controllers;

use App\Jobs\WriteOutreach;
use App\Models\User;
use App\Services\Catchup;
use App\Services\Outreach;
use App\Services\Presence;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The open page's check-in ("I'm here"). Coming back online, any texts contacts "sent" while the user was away are
 * written now, in the background, and arrive live; after a long time away, a welcome-back line follows them
 * (resources/js/presence.js shows it). With nothing to write, the welcome comes straight back.
 */
class PresenceController extends Controller
{
    public function __invoke(Request $request, Presence $presence, Catchup $catchup, Outreach $outreach): Response|JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $away = $presence->touch($user);
        if ($away === null) {
            return response()->noContent();
        }

        $welcome = $away >= Catchup::WELCOME_BACK_SECONDS;
        if ($outreach->hasWritingDue($user)) {
            WriteOutreach::dispatch($user->id, $welcome);
        } elseif ($welcome && ($message = $catchup->welcomeMessage($user))) {
            return response()->json(['welcome' => $message]);
        }

        return response()->noContent();
    }
}
