<?php

use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\LogApiRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: null,
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        // Hosts like Render end HTTPS at their load balancer and forward plain
        // HTTP, so trust the forwarded headers (set TRUSTED_PROXIES to narrow
        // it). Links, PayPal return URLs and signed receipts then stay https.
        $middleware->trustProxies(at: env('TRUSTED_PROXIES', '*'));

        $middleware->web(append: [
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        // Persist every API request/response (and any midway failure trace).
        $middleware->api(prepend: [
            LogApiRequests::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // The API always answers in JSON; so do dashboard endpoints called with
        // fetch (they ask for JSON), while Inertia page visits keep redirecting.
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || ($request->expectsJson() && ! $request->header('X-Inertia')),
        );

        // Stash the throwable on the request so LogApiRequests can persist its
        // trace. The routing pipeline renders exceptions into responses before
        // they reach outer middleware, so a render hook is the reliable capture.
        $exceptions->render(function (Throwable $e, Request $request) {
            $request->attributes->set('api_log_exception', $e);

            return null;
        });
    })->create();
