<?php

use App\Http\Middleware\EnsureAdult;
use App\Http\Middleware\EnsureFeature;
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

        $middleware->alias([
            'adult' => EnsureAdult::class,
            'feature' => EnsureFeature::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
