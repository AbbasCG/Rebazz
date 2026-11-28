<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WishlistItem extends Model
{
    public $timestamps = false;

    protected $fillable = ['customer_id', 'product_id', 'created_at'];
}
