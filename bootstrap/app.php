<?php

use App\Http\Middleware\EnsureAdult;
use App\Http\Middleware\EnsureFeature;
use App\Http\Middleware\EnsureNotSuspended;
use App\Http\Middleware\EnsureTermsAccepted;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Webhooks prove themselves with their own secret, not a CSRF token.
        $middleware->validateCsrfTokens(except: ['webhooks/*']);

        // Behind a load balancer: TRUSTED_PROXIES is "*" or a comma-separated list of addresses (empty trusts none).
        $middleware->trustProxies(at: array_filter(array_map('trim', explode(',', (string) env('TRUSTED_PROXIES', '')))) ?: null);
        $middleware->appendToGroup('web', SecurityHeaders::class);

        $middleware->alias([
            'adult' => EnsureAdult::class,
            'feature' => EnsureFeature::class,
            'not-suspended' => EnsureNotSuspended::class,
            'terms' => EnsureTermsAccepted::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
