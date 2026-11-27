<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class Product extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id', 'sku', 'name', 'category', 'price', 'compare_price', 'cost', 'image', 'icon', 'hue', 'supplier', 'supplier_url',
        'cj_vid', 'cj_from', 'cj_logistic', 'warehouse', 'days_min', 'days_max', 'stock', 'track_stock', 'rating', 'reviews_count', 'status',
        'description', 'features', 'options', 'images', 'manufacturer', 'eu_responsible', 'safety_info',
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
            'track_stock' => 'boolean',
            'reviews_count' => 'integer',
            'features' => 'array',
            'options' => 'array',
            'images' => 'array',
        ];
    }

    public function scopeActive($q)
    {
        return $q->where('status', 'active');
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class)->orderBy('position')->orderBy('id');
    }

    /** Varianten zonder lazy loading: gebruikt de al geladen relatie (with('variants')) of haalt ze op met één query. */
    public function variantList(): Collection
    {
        return $this->relationLoaded('variants') ? $this->variants : $this->variants()->get();
    }

    public function hasVariants(): bool
    {
        return $this->variantList()->isNotEmpty();
    }

    public function variantById(?int $id): ?ProductVariant
    {
        return $id ? $this->variantList()->firstWhere('id', $id) : null;
    }

    public function unitPrice(?ProductVariant $v = null): float
    {
        return (float) ($v?->price ?? $this->price);
    }

    public function unitCost(?ProductVariant $v = null): float
    {
        return (float) ($v?->cost ?? $this->cost);
    }

    /** Beschikbaarheid van een (variant van een) product, rekening houdend met voorraadbeheer. */
    public function availableStock(?ProductVariant $v = null): ?int
    {
        if (! $this->track_stock) {
            return null; // onbeperkt: voorraad wordt niet bijgehouden
        }

        return max(0, (int) ($v ? $v->stock : $this->stock));
    }

    private function inStock(): bool
    {
        if (! $this->track_stock) {
            return true;
        }
        $variants = $this->variantList();

        return $variants->isNotEmpty() ? $variants->contains(fn ($v) => $v->stock > 0) : $this->stock > 0;
    }

    /** Vorm zoals de frontend die verwacht. $admin = inclusief inkoop en leveranciersgegevens. */
    public function toApi(bool $admin = false): array
    {
        $variants = $this->variantList();
        $out = [
            'id' => $this->id,
            'slug' => Str::slug($this->name),
            'sku' => $this->sku,
            'name' => $this->name,
            'category' => $this->category,
            'price' => $this->price,
            'compare' => $this->compare_price,
            'image' => $this->image,
            'images' => array_values(array_filter((array) $this->images)),
            'icon' => $this->icon,
            'hue' => $this->hue,
            'warehouse' => $this->warehouse,
            'days' => [$this->days_min, $this->days_max],
            'rating' => $this->rating,
            'reviews' => $this->reviews_count,
            'status' => $this->status,
            'description' => (string) $this->description,
            'features' => $this->features ?? [],
            'options' => array_values((array) $this->options),
            // Voorraad (openbaar alleen als er iets te melden is): uitverkocht, of nog maar een paar stuks.
            'inStock' => $this->inStock(),
            'stockLeft' => $variants->isEmpty() && $this->track_stock && $this->stock > 0 && $this->stock <= 5 ? $this->stock : null,
            'variants' => $variants->map(fn (ProductVariant $v) => [
                'id' => $v->id,
                'label' => $v->label,
                'values' => (object) ($v->values ?? []),
                'sku' => $v->sku ?: $this->sku,
                'price' => $this->unitPrice($v),
                'image' => $v->image,
                'inStock' => ! $this->track_stock || $v->stock > 0,
                'stockLeft' => $this->track_stock && $v->stock > 0 && $v->stock <= 5 ? $v->stock : null,
            ] + ($admin ? ['ownPrice' => $v->price, 'cost' => $v->cost, 'cjVid' => $v->cj_vid, 'stock' => $v->stock] : []))->values()->all(),
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
                'trackStock' => (bool) $this->track_stock,
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
            'images' => array_values(array_unique(array_filter($d['images'] ?? []))),
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
            'track_stock' => (bool) ($d['trackStock'] ?? false),
            'status' => $d['status'],
            'description' => $d['description'] ?? '',
            'features' => array_values($d['features'] ?? []),
            'options' => self::cleanOptions($d['options'] ?? []),
            'manufacturer' => trim(strip_tags((string) ($d['manufacturer'] ?? ''))),
            'eu_responsible' => trim(strip_tags((string) ($d['euResponsible'] ?? ''))),
            'safety_info' => trim(strip_tags((string) ($d['safetyInfo'] ?? ''))),
        ];
    }

    /** [{name, values[]}] zonder lege namen/waarden en zonder HTML. */
    public static function cleanOptions(array $options): array
    {
        $out = [];
        foreach ($options as $o) {
            $name = trim(strip_tags((string) ($o['name'] ?? '')));
            $values = array_values(array_unique(array_filter(array_map(fn ($v) => trim(strip_tags((string) $v)), (array) ($o['values'] ?? [])))));
            if ($name !== '' && $values) {
                $out[] = ['name' => mb_substr($name, 0, 40), 'values' => array_map(fn ($v) => mb_substr($v, 0, 40), $values)];
            }
        }

        return array_slice($out, 0, 3);
    }

    /**
     * Synchroniseert de varianten met wat het beheer stuurt: bestaande (met id) bijwerken, nieuwe aanmaken, ontbrekende verwijderen.
     * Bestellingen bewaren hun eigen kopie van naam, prijs en variantnaam.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    public function syncVariants(array $rows): void
    {
        $keep = [];
        foreach (array_values($rows) as $i => $r) {
            $attrs = [
                'label' => mb_substr(trim(strip_tags((string) $r['label'])), 0, 120),
                'values' => array_map(fn ($v) => mb_substr(trim(strip_tags((string) $v)), 0, 40), (array) ($r['values'] ?? [])),
                'sku' => trim(strip_tags((string) ($r['sku'] ?? ''))),
                'price' => isset($r['ownPrice']) && $r['ownPrice'] !== '' ? (float) $r['ownPrice'] : null,
                'cost' => isset($r['cost']) && $r['cost'] !== '' ? (float) $r['cost'] : null,
                'cj_vid' => trim(strip_tags((string) ($r['cjVid'] ?? ''))),
                'stock' => max(0, (int) ($r['stock'] ?? 0)),
                'image' => (string) ($r['image'] ?? ''),
                'position' => $i,
            ];
            $existing = ! empty($r['id']) ? $this->variants()->whereKey((int) $r['id'])->first() : null;
            if ($existing) {
                $existing->update($attrs);
                $keep[] = $existing->id;
            } else {
                $keep[] = $this->variants()->create($attrs)->id;
            }
        }
        $this->variants()->whereNotIn('id', $keep ?: [0])->delete();
        $this->unsetRelation('variants');
        Cache::forget('shop.catalog');
    }
}
