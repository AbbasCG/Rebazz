<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PrivacyRequest extends Model
{
    public $timestamps = false;

    protected $fillable = ['email_hash', 'type', 'source', 'status', 'result', 'requested_at', 'completed_at'];

    protected function casts(): array
    {
        return ['result' => 'array', 'requested_at' => 'datetime', 'completed_at' => 'datetime'];
    }

    public static function hashEmail(string $email): string
    {
        return hash('sha256', strtolower(trim($email)));
    }
}
