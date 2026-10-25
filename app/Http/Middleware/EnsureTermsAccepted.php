<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Using /app needs the current Terms, Privacy Policy and AI disclosure accepted. When they change
 * (config/legal.php terms_version), everyone is sent to accept them again first.
 */
class EnsureTermsAccepted
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user instanceof User && ! $user->hasAcceptedTerms()) {
            return $request->expectsJson()
                ? response()->json(['message' => __('Please accept the updated terms.')], 409)
                : redirect()->guest(route('terms.accept'));
        }

        return $next($request);
    }
}
