<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AbandonedCart extends Model
{
    protected $fillable = ['session_id', 'email', 'lang', 'recovery_url', 'checkout_started_at', 'sent_at'];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime', 'checkout_started_at' => 'datetime'];
    }
}
