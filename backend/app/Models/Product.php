<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class Product extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id', 'sku', 'name', 'category', 'price', 'compare_price', 'cost', 'image', 'icon', 'hue', 'supplier', 'supplier_url',
        'cj_vid', 'cj_from', 'cj_logistic', 'warehouse', 'days_min', 'days_max', 'stock', 'rating', 'reviews_count', 'status',
        'description', 'features', 'manufacturer', 'eu_responsible', 'safety_info',
    ];

    protected static function booted(): void
    {
        // De openbare catalogus wordt even gecachet; elke wijziging maakt de cache meteen ongeldig.
        $forget = fn () => Cache::forget('shop.catalog');
        static::saved($forget);
        static::deleted($forget);
    }

    protected function casts(): array
    {
        return [
            'price' => 'float',
            'compare_price' => 'float',
            'cost' => 'float',
            'rating' => 'float',
            'hue' => 'integer',
            'days_min' => 'integer',
            'days_max' => 'integer',
            'stock' => 'integer',
            'reviews_count' => 'integer',
            'features' => 'array',
        ];
    }

    public function scopeActive($q)
    {
        return $q->where('status', 'active');
    }

    /** Vorm zoals de frontend die verwacht. $admin = inclusief inkoop en leveranciersgegevens. */
    public function toApi(bool $admin = false): array
    {
        $out = [
            'id' => $this->id,
            'sku' => $this->sku,
            'name' => $this->name,
            'category' => $this->category,
            'price' => $this->price,
            'compare' => $this->compare_price,
            'image' => $this->image,
            'icon' => $this->icon,
            'hue' => $this->hue,
            'warehouse' => $this->warehouse,
            'days' => [$this->days_min, $this->days_max],
            'rating' => $this->rating,
            'reviews' => $this->reviews_count,
            'status' => $this->status,
            'description' => (string) $this->description,
            'features' => $this->features ?? [],
            // GPSR-gegevens zijn bewust openbaar: consumenten moeten ze bij het product kunnen zien.
            'manufacturer' => (string) $this->manufacturer,
            'euResponsible' => (string) $this->eu_responsible,
            'safetyInfo' => (string) $this->safety_info,
        ];
        if ($admin) {
            $out += [
                'cost' => $this->cost,
                'supplier' => $this->supplier,
                'supplierUrl' => $this->supplier_url,
                'cjVid' => $this->cj_vid,
                'cjFrom' => $this->cj_from,
                'cjLogistic' => $this->cj_logistic,
                'stock' => $this->stock,
            ];
        }

        return $out;
    }

    /** Zet frontend-velden om naar kolommen. */
    public static function attributesFromApi(array $d): array
    {
        return [
            'sku' => $d['sku'],
            'name' => $d['name'],
            'category' => $d['category'],
            'price' => $d['price'],
            'compare_price' => $d['compare'] ?? null,
            'cost' => $d['cost'] ?? 0,
            'image' => $d['image'] ?? '',
            'icon' => $d['icon'] ?? 'package',
            'hue' => $d['hue'] ?? 220,
            'supplier' => $d['supplier'] ?? '',
            'supplier_url' => $d['supplierUrl'] ?? '',
            'cj_vid' => $d['cjVid'] ?? '',
            'cj_from' => strtoupper(substr($d['cjFrom'] ?? 'CN', 0, 2)),
            'cj_logistic' => $d['cjLogistic'] ?? '',
            'warehouse' => $d['warehouse'] ?? '',
            'days_min' => $d['days'][0] ?? 3,
            'days_max' => max($d['days'][1] ?? 7, $d['days'][0] ?? 3),
            'stock' => $d['stock'] ?? 0,
            'status' => $d['status'],
            'description' => $d['description'] ?? '',
            'features' => array_values($d['features'] ?? []),
            'manufacturer' => trim(strip_tags((string) ($d['manufacturer'] ?? ''))),
            'eu_responsible' => trim(strip_tags((string) ($d['euResponsible'] ?? ''))),
            'safety_info' => trim(strip_tags((string) ($d['safetyInfo'] ?? ''))),
        ];
    }
}
