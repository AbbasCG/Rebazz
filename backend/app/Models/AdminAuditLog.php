<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Wie deed wat in het beheer (inloggen, wijzigingen, terugbetalingen, teambeheer). Bevat nooit wachtwoorden, codes of inhoud van formulieren. */
class AdminAuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['user_id', 'user_label', 'action', 'detail', 'ip'];

    public static function record(?User $user, string $action, ?string $detail = null, ?string $label = null): void
    {
        static::create([
            'user_id' => $user?->id, 'user_label' => mb_substr($label ?? ($user ? $user->name.' <'.$user->email.'>' : ''), 0, 120) ?: null,
            'action' => mb_substr($action, 0, 80), 'detail' => $detail !== null ? mb_substr($detail, 0, 300) : null, 'ip' => request()?->ip(),
        ]);
    }
}
