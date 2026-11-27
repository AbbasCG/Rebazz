<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Product;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Slaat een betaalde Stripe Checkout-sessie op als bestelling in de database (idempotent). */
class OrderRecorder
{
    public function record(array $s): ?Order
    {
        if (($s['payment_status'] ?? '') !== 'paid') {
            return null;
        }
        $existing = Order::where('stripe_session_id', $s['id'])->first();
        if ($existing) {
            return $existing->load('items');
        }

        $md = $s['metadata'] ?? [];
        $pi = $s['payment_intent'] ?? null;
        $piId = is_array($pi) ? ($pi['id'] ?? null) : $pi;
        $charge = is_array($pi) && is_array($pi['latest_charge'] ?? null) ? $pi['latest_charge'] : [];
        $cd = $s['customer_details'] ?? [];
        $ship = $s['collected_information']['shipping_details'] ?? $s['shipping_details'] ?? [];
        $addr = $ship['address'] ?? $cd['address'] ?? [];
        $td = $s['total_details'] ?? [];

        $items = collect(explode(',', (string) ($md['items'] ?? '')))->filter()->map(function ($pair) {
            [$id, $qty] = array_pad(explode(':', $pair), 2, 1);

            return ['id' => $id, 'qty' => max(1, (int) $qty)];
        });
        $products = Product::whereIn('id', $items->pluck('id'))->get()->keyBy('id');

        try {
            return $this->create($s, $md, $piId, $charge, $cd, $ship, $addr, $td, $items, $products);
        } catch (UniqueConstraintViolationException) {
            // Webhook en bedankpagina kwamen tegelijk binnen: gebruik de al opgeslagen bestelling.
            return Order::where('stripe_session_id', $s['id'])->firstOrFail()->load('items');
        }
    }

    private function create(array $s, array $md, ?string $piId, array $charge, array $cd, array $ship, array $addr, array $td, $items, $products): Order
    {
        return DB::transaction(function () use ($s, $md, $piId, $charge, $cd, $ship, $addr, $td, $items, $products) {
            $order = Order::create([
                'order_no' => $md['order_no'] ?? ('RB-'.strtoupper(substr($s['id'], -8))),
                'stripe_session_id' => $s['id'],
                'payment_intent_id' => $piId,
                'livemode' => (bool) ($s['livemode'] ?? false),
                'status' => ($charge['refunded'] ?? false) ? 'refunded' : 'paid',
                'customer_name' => $ship['name'] ?? $cd['name'] ?? '',
                'email' => $cd['email'] ?? '',
                'phone' => $cd['phone'] ?? '',
                'country' => $addr['country'] ?? ($md['country'] ?? ''),
                'address' => $addr ?: [],
                'lang' => $md['lang'] ?? 'nl',
                'subtotal' => ($s['amount_subtotal'] ?? 0) / 100,
                'discount' => ($td['amount_discount'] ?? 0) / 100,
                'shipping' => ($td['amount_shipping'] ?? 0) / 100,
                'total' => ($s['amount_total'] ?? 0) / 100,
                // Alleen vastleggen als Stripe Tax de btw echt berekend heeft; anders blijft het null en schat het btw-overzicht.
                'tax_amount' => ($s['automatic_tax']['enabled'] ?? false) ? ($td['amount_tax'] ?? 0) / 100 : null,
                'cost' => $items->sum(fn ($i) => ($products->get($i['id'])?->cost ?? 0) * $i['qty']),
                'payment_method' => $charge['payment_method_details']['type'] ?? '',
                'ship_method' => $md['ship'] ?? 'standard',
                'paid_at' => Carbon::createFromTimestamp($s['created'] ?? time()),
            ]);
            // Factuurnummer in dezelfde transactie als de bestelling: komt de bestelling er niet, dan ontbreekt er ook geen nummer.
            app(InvoiceService::class)->ensureInvoice($order);
            foreach ($items as $i) {
                $p = $products->get($i['id']);
                $order->items()->create([
                    'product_id' => $p?->id,
                    'sku' => $p?->sku ?? '',
                    'name' => $p?->name ?? $i['id'],
                    'price' => $p?->price ?? 0,
                    'cost' => $p?->cost ?? 0,
                    'qty' => $i['qty'],
                    'supplier' => $p?->supplier ?? '',
                    'supplier_url' => $p?->supplier_url ?? '',
                ]);
            }

            return $order->load('items');
        });
    }
}
