<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\Cache;

/**
 * Voorraad per product (optioneel: Product::track_stock). Afschrijven gebeurt atomair bij het vastleggen van de betaling,
 * zodat twee gelijktijdige kopers nooit dezelfde laatste stuks krijgen; wat niet meer past wordt gemeld in plaats van negatief te gaan.
 */
class StockService
{
    /**
     * Schrijft de voorraad af voor een nieuwe bestelling.
     *
     * @return array<int, string> regels waarvoor de voorraad onvoldoende was (leeg = alles gelukt)
     */
    public function deduct(Order $order): array
    {
        $short = [];
        $order->loadMissing('items');
        foreach ($order->items as $item) {
            if (! $item->product_id) {
                continue;
            }
            $product = Product::find($item->product_id);
            if (! $product || ! $product->track_stock) {
                continue;
            }
            // Eén UPDATE met voorwaarde: slaagt alleen als er nog genoeg is (geen race tussen lezen en schrijven).
            // Heeft de bestelregel een variant, dan geldt de voorraad van die variant.
            $variant = $item->variant_id ? ProductVariant::find($item->variant_id) : null;
            $done = $variant
                ? ProductVariant::whereKey($variant->getKey())->where('stock', '>=', $item->qty)->decrement('stock', $item->qty)
                : Product::whereKey($product->getKey())->where('track_stock', true)->where('stock', '>=', $item->qty)->decrement('stock', $item->qty);
            if ($done === 1) {
                $item->update(['stock_deducted' => $item->qty]);
            } else {
                $left = $variant ? (int) $variant->fresh()->stock : (int) $product->fresh()->stock;
                $short[] = $item->name.($item->variant_label ? ' — '.$item->variant_label : '').' (besteld '.$item->qty.', op voorraad '.max(0, $left).')';
            }
        }
        Cache::forget('shop.catalog'); // decrement() slaat de modelgebeurtenissen over

        return $short;
    }

    /** Boekt afgeschreven stuks terug als de bestelling nog niet is verzonden (retour van verzonden goederen is een handmatige beslissing). */
    public function restockIfUnshipped(Order $order): int
    {
        if ($order->step >= 1 || $order->cj_order_ids) {
            return 0;
        }
        $back = 0;
        $order->loadMissing('items');
        foreach ($order->items as $item) {
            if ($item->stock_deducted > 0 && $item->product_id) {
                $item->variant_id
                    ? ProductVariant::whereKey($item->variant_id)->increment('stock', $item->stock_deducted)
                    : Product::whereKey($item->product_id)->increment('stock', $item->stock_deducted);
                $back += $item->stock_deducted;
                $item->update(['stock_deducted' => 0]);
            }
        }
        if ($back) {
            Cache::forget('shop.catalog');
        }

        return $back;
    }
}
