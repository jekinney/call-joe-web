<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A suspended account can't use /app (an admin suspends it in /admin/users or /admin/moderation).
 */
class EnsureNotSuspended
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user instanceof User && $user->isSuspended()) {
            abort(403, __('Your account is suspended. Write to :email if you think this is a mistake.', ['email' => config('legal.contact_email')]));
        }

        return $next($request);
    }
}
