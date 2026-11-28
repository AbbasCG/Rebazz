<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Beheer-toegang per werkgebied: `staff:orders` = alleen rollen met dat gebied. `staff.self` = alleen een actief personeelsaccount (voor profiel/2FA).
 * Met SHOP_REQUIRE_2FA=on moet elke medewerker eerst tweestapsverificatie instellen voordat iets anders werkt.
 */
class EnsureStaff
{
    public function handle(Request $request, Closure $next, ?string $area = null): Response
    {
        $user = $request->user();
        if (! $user instanceof User || ! $user->tokenCan('admin') || ! $user->active) {
            abort(403, 'Geen toegang.');
        }
        if ($area !== null && config('shop.require_2fa') && ! $user->hasTwoFactor()) {
            return response()->json(['error' => 'two_factor_required', 'message' => 'Stel eerst tweestapsverificatie in (Mijn account).'], 403);
        }
        if ($area !== null && $area !== 'any' && ! $user->canArea($area)) {
            return response()->json(['error' => 'forbidden', 'message' => 'Uw rol heeft hier geen toegang toe.'], 403);
        }

        return $next($request);
    }
}
