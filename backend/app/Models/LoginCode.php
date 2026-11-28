<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LoginCode extends Model
{
    public $timestamps = false;

    protected $fillable = ['email', 'code_hash', 'attempts', 'expires_at', 'created_at'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'created_at' => 'datetime'];
    }

    /** HMAC met APP_KEY: een gelekte tabel levert geen bruikbare codes op. */
    public static function hash(string $email, string $code): string
    {
        return hash_hmac('sha256', strtolower($email).'|'.$code, (string) config('app.key'));
    }
}
