<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    protected $fillable = [
        'order_no', 'stripe_session_id', 'payment_intent_id', 'livemode', 'status', 'customer_name', 'email', 'phone', 'country',
        'address', 'lang', 'subtotal', 'discount', 'shipping', 'total', 'tax_amount', 'cost', 'payment_method', 'ship_method', 'step', 'carrier',
        'tracking', 'delayed', 'cj_status', 'cj_order_ids', 'cj_error', 'cj_wait', 'paid_at',
        'invoice_number', 'invoice_issued_at', 'credit_note_number', 'credit_note_issued_at', 'confirmation_sent_at',
    ];

    protected function casts(): array
    {
        return [
            'livemode' => 'boolean',
            'address' => 'array',
            'subtotal' => 'float',
            'discount' => 'float',
            'shipping' => 'float',
            'total' => 'float',
            'tax_amount' => 'float',
            'cost' => 'float',
            'step' => 'integer',
            'delayed' => 'boolean',
            'cj_wait' => 'boolean',
            'paid_at' => 'datetime',
            'invoice_issued_at' => 'datetime',
            'credit_note_issued_at' => 'datetime',
            'confirmation_sent_at' => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function getRouteKeyName(): string
    {
        return 'order_no';
    }

    public function addressLine(): string
    {
        $a = $this->address ?? [];

        return implode(', ', array_filter([
            $a['line1'] ?? null, $a['line2'] ?? null,
            trim(($a['postal_code'] ?? '').' '.($a['city'] ?? '')),
            $a['state'] ?? null, $a['country'] ?? null,
        ]));
    }

    /** Vorm zoals het dashboard die verwacht. */
    public function toApi(): array
    {
        return [
            'id' => $this->order_no,
            'pi' => (string) $this->payment_intent_id,
            'live' => $this->livemode,
            'date' => optional($this->paid_at ?? $this->created_at)->toIso8601String(),
            'customer' => $this->customer_name,
            'email' => $this->email,
            'phone' => $this->phone,
            'country' => $this->country,
            'address' => $this->addressLine(),
            'items' => $this->items->map(fn (OrderItem $i) => [
                'id' => (string) $i->product_id, 'sku' => $i->sku, 'name' => $i->name.($i->variant_label ? ' — '.$i->variant_label : ''), 'price' => $i->price, 'cost' => $i->cost,
                'qty' => $i->qty, 'supplier' => $i->supplier, 'supplierUrl' => $i->supplier_url,
            ])->values()->all(),
            'subtotal' => $this->subtotal,
            'discount' => $this->discount,
            'shipping' => $this->shipping,
            'total' => $this->total,
            'cost' => $this->cost,
            'payment' => $this->payment_method,
            'shipMethod' => $this->ship_method,
            'lang' => $this->lang,
            'step' => $this->step,
            'carrier' => $this->carrier,
            'tracking' => $this->tracking,
            'delayed' => $this->delayed,
            'refunded' => $this->status === 'refunded',
            'paid' => true,
            'cjStatus' => $this->cj_status,
            'cjOrders' => $this->cj_order_ids,
            'cjError' => $this->cj_error,
        ];
    }
}
