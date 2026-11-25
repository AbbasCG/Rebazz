<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class Setting extends Model
{
    public $incrementing = false;
    protected $primaryKey = 'key';
    protected $keyType = 'string';
    protected $fillable = ['key', 'value'];

    protected static function booted(): void
    {
        $forget = fn () => Cache::forget('shop.catalog');
        static::saved($forget);
        static::deleted($forget);
    }

    protected function casts(): array
    {
        return ['value' => 'json'];
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $row = static::find($key);

        return $row ? $row->value : $default;
    }

    public static function put(string $key, mixed $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => $value]);
    }

    /** Bedrijfsgegevens: standaard uit config, aangepast in het dashboard. */
    public static function brand(): array
    {
        return array_merge(config('shop.brand'), (array) static::get('brand', []));
    }
}
