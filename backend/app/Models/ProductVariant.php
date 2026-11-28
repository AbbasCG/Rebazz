<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;

class ProductVariant extends Model
{
    public $timestamps = false;

    protected $fillable = ['product_id', 'label', 'values', 'sku', 'price', 'cost', 'cj_vid', 'stock', 'image', 'position'];

    protected static function booted(): void
    {
        $forget = function () { // de openbare catalogus en de productfeed tonen varianten en voorraad
            Cache::forget('shop.catalog');
            Cache::forget(\App\Services\ProductFeed::CACHE);
        };
        static::saved($forget);
        static::deleted($forget);
    }

    protected function casts(): array
    {
        return ['values' => 'array', 'price' => 'float', 'cost' => 'float', 'stock' => 'integer', 'position' => 'integer'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
