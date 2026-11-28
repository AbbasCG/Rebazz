<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NewsletterDelivery extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['campaign_id', 'email', 'ok'];
}
