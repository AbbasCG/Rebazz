<?php

namespace App\Http\Controllers;

use App\Models\AdminAuditLog;
use App\Models\User;
use App\Services\TwoFactorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

/** Eigen account van de medewerker: profiel, wachtwoord wijzigen en tweestapsverificatie (TOTP + herstelcodes). */
class AdminAccountController extends Controller
{
    public static function shape(User $u, TwoFactorService $tf): array
    {
        return [
            'name' => $u->name, 'email' => $u->email, 'role' => $u->role, 'areas' => $u->areas(),
            'breakGlass' => $u->isBreakGlassOwner(),
            'twoFactor' => ['enabled' => $u->hasTwoFactor(), 'recoveryLeft' => $u->hasTwoFactor() ? $tf->recoveryLeft($u) : 0, 'since' => $u->two_factor_enabled_at?->toIso8601String()],
            'require2fa' => (bool) config('shop.require_2fa'),
        ];
    }

    public function me(Request $request, TwoFactorService $tf): JsonResponse
    {
        return response()->json(self::shape($request->user(), $tf));
    }

    /** Controleert het huidige wachtwoord van het account. De eigenaar (ADMIN_PASSWORD) bevestigt met dat wachtwoord. */
    private function passwordOk(User $u, string $password): bool
    {
        if (RateLimiter::tooManyAttempts('admin-pw-confirm:'.$u->id, 5)) {
            abort(429, 'Te veel pogingen. Probeer het later opnieuw.');
        }
        $ok = $u->isBreakGlassOwner() ? hash_equals((string) config('shop.admin_password'), $password) : Hash::check($password, $u->password);
        $ok ? RateLimiter::clear('admin-pw-confirm:'.$u->id) : RateLimiter::hit('admin-pw-confirm:'.$u->id, 900);

        return $ok;
    }

    public function changePassword(Request $request): JsonResponse
    {
        $u = $request->user();
        if ($u->isBreakGlassOwner()) {
            return response()->json(['message' => 'Het eigenaarswachtwoord wijzigt u via ADMIN_PASSWORD in het .env-bestand.'], 422);
        }
        $d = $request->validate(['current_password' => ['required', 'string', 'max:200'], 'password' => ['required', 'string', 'min:12', 'max:200', 'different:current_password']]);
        if (! $this->passwordOk($u, $d['current_password'])) {
            return response()->json(['message' => 'Huidig wachtwoord klopt niet.'], 422);
        }
        $u->update(['password' => $d['password']]);
        $u->tokens()->where('id', '!=', $u->currentAccessToken()->id)->delete(); // andere sessies loggen uit
        AdminAuditLog::record($u, 'wachtwoord gewijzigd');

        return response()->json(['ok' => true]);
    }

    /** Stap 1: nieuw geheim aanmaken (nog niet actief). Het geheim wordt getoond om in de authenticator-app in te voeren. */
    public function setup(Request $request, TwoFactorService $tf): JsonResponse
    {
        $u = $request->user();
        if ($u->hasTwoFactor()) {
            return response()->json(['message' => 'Tweestapsverificatie staat al aan.'], 409);
        }
        $secret = $tf->newSecret();
        $u->forceFill(['two_factor_pending' => $secret])->save();

        return response()->json(['secret' => $secret, 'uri' => $tf->uri($u, $secret)]);
    }

    /** Stap 2: met een code uit de app bevestigen; daarna worden de herstelcodes (eenmalig) getoond. */
    public function enable(Request $request, TwoFactorService $tf): JsonResponse
    {
        $u = $request->user();
        $d = $request->validate(['code' => ['required', 'string', 'max:20']]);
        if ($u->hasTwoFactor() || ! $u->two_factor_pending) {
            return response()->json(['message' => 'Start eerst het instellen.'], 409);
        }
        if (RateLimiter::tooManyAttempts('admin-2fa-setup:'.$u->id, 8)) {
            return response()->json(['message' => 'Te veel pogingen. Start het instellen opnieuw.'], 429);
        }
        $step = $tf->verifyTotp((string) $u->two_factor_pending, $d['code']);
        if ($step === null) {
            RateLimiter::hit('admin-2fa-setup:'.$u->id, 900);

            return response()->json(['message' => 'De code klopt niet. Controleer de tijd op uw telefoon en probeer opnieuw.'], 422);
        }
        $u->forceFill(['two_factor_secret' => $u->two_factor_pending, 'two_factor_pending' => null, 'two_factor_enabled_at' => now(), 'two_factor_last_step' => $step])->save();
        $codes = $tf->regenerateRecovery($u);
        AdminAuditLog::record($u, '2FA ingeschakeld');

        return response()->json(['recoveryCodes' => $codes] + self::shape($u, $tf));
    }

    /** Uitschakelen vraagt het wachtwoord én een geldige code (of herstelcode). */
    public function disable(Request $request, TwoFactorService $tf): JsonResponse
    {
        $u = $request->user();
        $d = $request->validate(['password' => ['required', 'string', 'max:200'], 'code' => ['required', 'string', 'max:20']]);
        if (config('shop.require_2fa')) {
            return response()->json(['message' => 'Tweestapsverificatie is verplicht voor dit beheer en kan niet worden uitgezet.'], 422);
        }
        if (! $u->hasTwoFactor() || ! $this->passwordOk($u, $d['password']) || ! $this->codeOk($u, $d['code'], $tf)) {
            return response()->json(['message' => 'Wachtwoord of code klopt niet.'], 422);
        }
        $tf->disable($u);
        AdminAuditLog::record($u, '2FA uitgeschakeld');

        return response()->json(self::shape($u->fresh(), $tf));
    }

    /** Nieuwe herstelcodes (de oude vervallen); vraagt wachtwoord en code. */
    public function recovery(Request $request, TwoFactorService $tf): JsonResponse
    {
        $u = $request->user();
        $d = $request->validate(['password' => ['required', 'string', 'max:200'], 'code' => ['required', 'string', 'max:20']]);
        if (! $u->hasTwoFactor() || ! $this->passwordOk($u, $d['password']) || ! $this->codeOk($u, $d['code'], $tf)) {
            return response()->json(['message' => 'Wachtwoord of code klopt niet.'], 422);
        }
        $codes = $tf->regenerateRecovery($u);
        AdminAuditLog::record($u, '2FA-herstelcodes vernieuwd');

        return response()->json(['recoveryCodes' => $codes] + self::shape($u->fresh(), $tf));
    }

    private function codeOk(User $u, string $code, TwoFactorService $tf): bool
    {
        if (RateLimiter::tooManyAttempts('admin-2fa:'.$u->id, 5)) {
            abort(429, 'Te veel foute codes. Probeer het later opnieuw.');
        }
        $ok = $tf->check($u, $code);
        $ok ? RateLimiter::clear('admin-2fa:'.$u->id) : RateLimiter::hit('admin-2fa:'.$u->id, 900);

        return $ok;
    }
}
