<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Achter nginx/Cloudflare/load balancer: vertrouw die proxy zodat IP's, https en HSTS kloppen (TRUSTED_PROXIES=* of lijst).
        $middleware->trustProxies(at: env('TRUSTED_PROXIES') ? (env('TRUSTED_PROXIES') === '*' ? '*' : array_map('trim', explode(',', env('TRUSTED_PROXIES')))) : null);
        $middleware->append(\App\Http\Middleware\SecurityHeaders::class);
        $middleware->alias([
            'abilities' => \Laravel\Sanctum\Http\Middleware\CheckAbilities::class,
            'customer' => \App\Http\Middleware\EnsureCustomer::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
