<?php

namespace App\Http\Middleware;

use App\Models\Customer;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Alleen ingelogde klanten (geen beheerder): de gebruiker achter het token moet een Customer zijn met het recht 'customer'. */
class EnsureCustomer
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        abort_unless($user instanceof Customer && $user->tokenCan('customer'), 403);

        return $next($request);
    }
}
