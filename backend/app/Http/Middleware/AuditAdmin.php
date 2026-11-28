<?php

namespace App\Http\Middleware;

use App\Models\AdminAuditLog;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Legt elke wijzigende beheeractie vast (wie, welke route, uitkomst). Nooit de inhoud van het verzoek. */
class AuditAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        if (! in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true) && $request->user()) {
            $route = $request->route();
            try {
                AdminAuditLog::record($request->user(), $request->method().' '.($route?->uri() ?? $request->path()), 'status '.$response->getStatusCode());
            } catch (\Throwable) {
                // het logboek mag de actie zelf nooit blokkeren
            }
        }

        return $response;
    }
}
