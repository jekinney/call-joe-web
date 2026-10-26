<?php

namespace App\Http\Controllers;

use App\Contracts\PaymentGateway;
use App\Exceptions\PaymentException;
use App\Services\Billing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Braintree's webhooks. Each one is signed with our keys, and only the signature decides whether to believe it; a
 * wrong one is refused. Braintree also sends a GET with a challenge when the address is first set up.
 */
class BraintreeWebhookController extends Controller
{
    public function __invoke(Request $request, Billing $billing, PaymentGateway $gateway): Response|JsonResponse
    {
        try {
            if ($request->isMethod('GET')) {
                return response($gateway->verifyWebhook((string) $request->query('bt_challenge')), 200, ['Content-Type' => 'text/plain']);
            }

            $handled = $billing->handleWebhook((string) $request->input('bt_signature'), (string) $request->input('bt_payload'));
        } catch (PaymentException) {
            return response('Not allowed.', 400);
        }

        return response()->json(['ok' => true, 'handled' => $handled]);
    }
}
