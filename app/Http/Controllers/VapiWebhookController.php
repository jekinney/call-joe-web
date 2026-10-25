<?php

namespace App\Http\Controllers;

use App\Services\Calling;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Reports from Vapi about calls (status updates and end-of-call reports). Each call's assistant sends our secret in a
 * header (see Calling::start); anything without it is refused.
 */
class VapiWebhookController extends Controller
{
    public function __invoke(Request $request, Calling $calling): JsonResponse
    {
        $secret = (string) config('services.vapi.webhook_secret');
        if ($secret === '' || ! hash_equals($secret, (string) $request->header(Calling::SECRET_HEADER))) {
            return response()->json(['error' => 'Not allowed.'], 401);
        }

        $message = (array) $request->input('message', []);
        $known = match ($message['type'] ?? null) {
            'end-of-call-report' => $calling->handleReport($message),
            'status-update' => $calling->handleStatus($message),
            default => null,
        };

        return response()->json(['ok' => true, 'handled' => $known === true]);
    }
}
