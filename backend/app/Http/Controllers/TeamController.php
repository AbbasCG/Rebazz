<?php

namespace App\Http\Controllers;

use App\Models\AdminAuditLog;
use App\Models\User;
use App\Services\TwoFactorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Medewerkers beheren (alleen de eigenaar): toevoegen, rol wijzigen, deactiveren, wachtwoord/2FA resetten, en het activiteitenlogboek. */
class TeamController extends Controller
{
    private function shape(User $u): array
    {
        return [
            'id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'role' => $u->role, 'active' => $u->active,
            'twoFactor' => $u->hasTwoFactor(), 'lastLogin' => $u->last_login_at?->toIso8601String(), 'breakGlass' => $u->isBreakGlassOwner(),
        ];
    }

    public function index(): JsonResponse
    {
        return response()->json([
            'members' => User::orderByDesc('role')->orderBy('name')->get()->map(fn ($u) => $this->shape($u))->values(),
            'roles' => collect(User::ROLES)->map(fn ($areas) => $areas)->all(),
            'require2fa' => (bool) config('shop.require_2fa'),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $d = $request->validate([
            'name' => ['required', 'string', 'max:100'], 'email' => ['required', 'email:rfc', 'max:200'],
            'role' => ['required', Rule::in(array_keys(User::ROLES))], 'password' => ['required', 'string', 'min:12', 'max:200'],
        ]);
        $email = strtolower(trim($d['email']));
        if ($email === User::OWNER_EMAIL || User::whereRaw('lower(email) = ?', [$email])->exists()) {
            return response()->json(['message' => 'Dit e-mailadres is al in gebruik.'], 422);
        }
        $u = User::create(['name' => strip_tags($d['name']), 'email' => $email, 'password' => $d['password'], 'role' => $d['role'], 'active' => true]);
        AdminAuditLog::record($request->user(), 'medewerker toegevoegd', $email.' ('.$d['role'].')');

        return response()->json($this->shape($u), 201);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $me = $request->user();
        $d = $request->validate([
            'name' => ['sometimes', 'string', 'max:100'], 'role' => ['sometimes', Rule::in(array_keys(User::ROLES))],
            'active' => ['sometimes', 'boolean'], 'password' => ['sometimes', 'string', 'min:12', 'max:200'],
        ]);
        if ($user->isBreakGlassOwner() && (isset($d['role']) || isset($d['active']) || isset($d['password']))) {
            return response()->json(['message' => 'Het eigenaarsaccount wijzigt u niet hier (wachtwoord: ADMIN_PASSWORD in .env).'], 422);
        }
        if ($user->id === $me->id && ((isset($d['role']) && $d['role'] !== $user->role) || (isset($d['active']) && ! $d['active']))) {
            return response()->json(['message' => 'U kunt uw eigen rol of status niet wijzigen.'], 422);
        }
        $changes = [];
        foreach (['role', 'active'] as $k) {
            if (isset($d[$k]) && $d[$k] !== $user->{$k}) {
                $changes[] = $k.'→'.(is_bool($d[$k]) ? ($d[$k] ? 'aan' : 'uit') : $d[$k]);
            }
        }
        if (isset($d['password'])) {
            $changes[] = 'wachtwoord';
        }
        if (isset($d['name'])) {
            $d['name'] = strip_tags($d['name']);
        }
        $user->update($d);
        if (isset($d['role']) || isset($d['active']) || isset($d['password'])) {
            $user->tokens()->delete(); // wijziging van rechten of wachtwoord: direct uitloggen
        }
        AdminAuditLog::record($me, 'medewerker gewijzigd', $user->email.': '.($changes ? implode(', ', $changes) : 'naam'));

        return response()->json($this->shape($user->fresh()));
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        if ($user->isBreakGlassOwner() || $user->id === $request->user()->id) {
            return response()->json(['message' => 'Dit account kunt u niet verwijderen.'], 422);
        }
        $user->tokens()->delete();
        AdminAuditLog::record($request->user(), 'medewerker verwijderd', $user->email);
        $user->delete();

        return response()->json(['ok' => true]);
    }

    /** Verloren telefoon en geen herstelcodes: de eigenaar zet 2FA van de medewerker uit (die stelt het opnieuw in). */
    public function resetTwoFactor(Request $request, User $user, TwoFactorService $tf): JsonResponse
    {
        $tf->disable($user);
        $user->tokens()->delete();
        AdminAuditLog::record($request->user(), '2FA gereset', $user->email);

        return response()->json($this->shape($user->fresh()));
    }

    public function audit(): JsonResponse
    {
        return response()->json(['log' => AdminAuditLog::orderByDesc('id')->limit(200)->get(['id', 'user_label', 'action', 'detail', 'ip', 'created_at'])]);
    }
}
