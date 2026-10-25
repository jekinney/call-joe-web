<?php

namespace App\Http\Middleware;

use App\Enums\Feature;
use App\Models\User;
use App\Services\Entitlements;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A route only for plans with a feature: ->middleware('feature:rated_r'). Others are sent to the plan page.
 */
class EnsureFeature
{
    public function __construct(private Entitlements $entitlements) {}

    public function handle(Request $request, Closure $next, string $feature): Response
    {
        $user = $request->user();
        $wanted = Feature::from($feature);

        if (! $user instanceof User || ! $this->entitlements->can($user, $wanted)) {
            return redirect()->route('app.plan')->with('upgrade', $wanted->value);
        }

        return $next($request);
    }
}
