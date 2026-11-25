<?php

namespace App\Services;

use App\Models\Product;

/** Berekent een bestelling uit de database-prijzen (nooit uit de browser). */
class Pricing
{
    public function zoneFor(string $country): ?array
    {
        foreach (config('shop.zones') as $z) {
            if (in_array($country, $z['countries'], true)) {
                return $z;
            }
        }

        return null;
    }

    /**
     * @param  array<int, array{id: string, qty: int}>  $items
     * @return array{error?: string, zone?: array, lines?: array, subtotal?: float, shipping?: float, total?: float, days?: array, express?: bool}
     */
    public function quote(array $items, string $country, string $shipMethod = 'standard'): array
    {
        $zone = $this->zoneFor($country);
        if (! $zone) {
            return ['error' => 'country'];
        }
        $ids = array_column($items, 'id');
        $products = Product::active()->whereIn('id', $ids)->get()->keyBy('id');
        $lines = [];
        foreach ($items as $it) {
            $p = $products->get($it['id']);
            $qty = (int) $it['qty'];
            if (! $p || $qty < 1 || $qty > 20) {
                return ['error' => 'item'];
            }
            $lines[] = ['product' => $p, 'qty' => $qty];
        }
        if (! $lines || count($lines) > 30) {
            return ['error' => 'empty'];
        }
        $subtotal = array_reduce($lines, fn ($s, $l) => $s + $l['product']->price * $l['qty'], 0.0);
        $min = array_reduce($lines, fn ($m, $l) => max($m, $l['product']->days_min), $zone['days'][0]);
        $max = array_reduce($lines, fn ($m, $l) => max($m, $l['product']->days_max), $zone['days'][1]);
        $express = $shipMethod === 'express' && $zone['express'] > 0;
        $shipping = $express ? $zone['express'] : ($subtotal >= $zone['freeFrom'] ? 0.0 : $zone['rate']);
        $days = $express ? [max(2, (int) ceil($min / 2)), (int) ceil($max / 2) + 1] : [$min, $max];

        return [
            'zone' => $zone, 'lines' => $lines, 'subtotal' => round($subtotal, 2), 'shipping' => (float) $shipping,
            'total' => round($subtotal + $shipping, 2), 'days' => $days, 'express' => $express,
        ];
    }
}
