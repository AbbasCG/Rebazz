<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/** Personeel van het beheer. De eigenaar (OWNER_EMAIL) logt in met ADMIN_PASSWORD; overige medewerkers met e-mail + wachtwoord en een rol. */
#[Fillable(['name', 'email', 'password', 'role', 'active'])]
#[Hidden(['password', 'remember_token', 'two_factor_secret', 'two_factor_pending', 'two_factor_recovery'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /** Het "break-glass"-account: inloggen alleen met ADMIN_PASSWORD uit .env, nooit via e-mail. */
    public const OWNER_EMAIL = 'admin@rebazz.local';

    /**
     * Werkgebieden per rol. owner = alles. "refunds" (terugbetalen) en "team" (medewerkers beheren) zijn bewust apart.
     * orders: bestellingen + klantenservice · returns: retouren behandelen · products: assortiment · marketing: nieuwsbrief, statistieken, advertenties
     * privacy: AVG-verzoeken · tax: btw · settings: winkelgegevens, automatiseringen
     */
    public const ROLES = [
        'owner' => ['orders', 'refunds', 'returns', 'products', 'marketing', 'privacy', 'tax', 'settings', 'team'],
        'manager' => ['orders', 'refunds', 'returns', 'products', 'marketing', 'privacy', 'tax', 'settings'],
        'support' => ['orders', 'returns'],
        'marketing' => ['marketing', 'products'],
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'active' => 'boolean',
            'two_factor_secret' => 'encrypted',
            'two_factor_pending' => 'encrypted',
            'two_factor_enabled_at' => 'datetime',
            'last_login_at' => 'datetime',
        ];
    }

    public function areas(): array
    {
        return self::ROLES[$this->role] ?? [];
    }

    public function canArea(string $area): bool
    {
        return in_array($area, $this->areas(), true);
    }

    public function isBreakGlassOwner(): bool
    {
        return strtolower($this->email) === self::OWNER_EMAIL;
    }

    public function hasTwoFactor(): bool
    {
        return $this->two_factor_enabled_at !== null && ! empty($this->two_factor_secret);
    }
}
