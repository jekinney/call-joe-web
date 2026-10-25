<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Call Joe is for adults only. An account under 18, or with no birthday on file, can't use /app.
 */
class EnsureAdult
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_unless($user instanceof User && $user->isAdult(), 403, 'Call Joe is only for adults (18 and over).');

        return $next($request);
    }
}
