<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NewsletterSubscriber extends Model
{
    protected $fillable = ['email', 'lang', 'confirmed_at', 'confirm_token'];

    protected function casts(): array
    {
        return ['confirmed_at' => 'datetime'];
    }

    public function scopeConfirmed($q)
    {
        return $q->whereNotNull('confirmed_at');
    }
}
