<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Presence;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The open page's check-in ("I'm here").
 */
class PresenceController extends Controller
{
    public function __invoke(Request $request, Presence $presence): Response
    {
        /** @var User $user */
        $user = $request->user();
        $presence->touch($user);

        return response()->noContent();
    }
}
