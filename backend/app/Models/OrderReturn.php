<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrderReturn extends Model
{
    public const REASONS = ['defective', 'damaged', 'wrong_item', 'not_as_described', 'changed_mind', 'other'];

    protected $fillable = [
        'rma_number', 'order_id', 'status', 'reason', 'comment', 'admin_note', 'return_tracking', 'refund_amount', 'refund_reference',
        'credit_note_number', 'credit_note_issued_at', 'approved_at', 'rejected_at', 'received_at', 'refunded_at',
    ];

    protected function casts(): array
    {
        return [
            'refund_amount' => 'float',
            'credit_note_issued_at' => 'datetime',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'received_at' => 'datetime',
            'refunded_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'rma_number';
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderReturnItem::class);
    }

    /** Waarde van de geretourneerde artikelen (prijs incl. btw × aantal); geen verzendkosten. */
    public function itemsValue(): float
    {
        return round($this->items->sum(fn (OrderReturnItem $i) => $i->orderItem->price * $i->qty), 2);
    }
}
