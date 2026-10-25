<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Catchup;
use App\Services\Presence;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The open page's check-in ("I'm here"). After a long time away, it answers with a welcome-back line when contacts
 * texted in the meantime (resources/js/presence.js shows it).
 */
class PresenceController extends Controller
{
    public function __invoke(Request $request, Presence $presence, Catchup $catchup): Response|JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $away = $presence->touch($user);

        if ($away !== null && $away >= Catchup::WELCOME_BACK_SECONDS && ($welcome = $catchup->welcomeMessage($user))) {
            return response()->json(['welcome' => $welcome]);
        }

        return response()->noContent();
    }
}
