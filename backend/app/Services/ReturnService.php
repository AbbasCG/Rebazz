<?php

namespace App\Services;

use App\Mail\ReturnMail;
use App\Models\Order;
use App\Models\OrderReturn;
use App\Models\Setting;
use DomainException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Retouren (RMA): aanvraag door de klant, beoordeling door de winkel, ontvangst (met terugboeken van voorraad) en
 * (gedeeltelijke) terugbetaling via Stripe met een creditnota per retour.
 */
class ReturnService
{
    public function __construct(private InvoiceService $invoices, private StockService $stock) {}

    /**
     * Wat de klant voor deze bestelling nog kan retourneren.
     *
     * @return array{eligible: bool, deadline: ?string, items: array<int, array{id: int, name: string, qty: int, returnable: int}>}
     */
    public function overview(Order $order): array
    {
        $order->loadMissing('items', 'returns.items');
        $days = max(1, (int) config('shop.return_days'));
        // De termijn loopt vanaf aflevering. Is het nog niet afgeleverd, dan mag de klant ook al herroepen: ruime grens vanaf de betaling.
        $deadline = $order->delivered_at ? $order->delivered_at->copy()->addDays($days) : ($order->paid_at ?? $order->created_at)->copy()->addDays(90);

        $reserved = [];
        foreach ($order->returns->where('status', '!=', 'rejected') as $r) {
            foreach ($r->items as $ri) {
                $reserved[$ri->order_item_id] = ($reserved[$ri->order_item_id] ?? 0) + $ri->qty;
            }
        }
        $items = $order->items->map(fn ($i) => [
            'id' => $i->id,
            'name' => $i->name.($i->variant_label ? ' — '.$i->variant_label : ''),
            'qty' => $i->qty,
            'returnable' => max(0, $i->qty - ($reserved[$i->id] ?? 0)),
        ])->values()->all();
        $eligible = $order->status === 'paid' && now()->lte($deadline) && collect($items)->contains(fn ($i) => $i['returnable'] > 0);

        return ['eligible' => $eligible, 'deadline' => $deadline->toDateString(), 'items' => $items];
    }

    /** @param  array<int, array{id: int, qty: int}>  $items */
    public function request(Order $order, array $items, string $reason, ?string $comment): OrderReturn
    {
        $overview = $this->overview($order);
        if (! $overview['eligible']) {
            throw new DomainException('Voor deze bestelling kan geen retour (meer) worden aangevraagd.');
        }
        if (! in_array($reason, OrderReturn::REASONS, true)) {
            throw new DomainException('Ongeldige reden.');
        }
        $available = collect($overview['items'])->keyBy('id');
        $wanted = [];
        foreach ($items as $it) {
            $line = $available->get((int) $it['id']);
            $qty = (int) $it['qty'];
            if (! $line || $qty < 1 || $qty > $line['returnable']) {
                throw new DomainException('Ongeldig aantal voor "'.($line['name'] ?? 'onbekend artikel').'".');
            }
            $wanted[(int) $it['id']] = ($wanted[(int) $it['id']] ?? 0) + $qty;
            if ($wanted[(int) $it['id']] > $line['returnable']) {
                throw new DomainException('Ongeldig aantal voor "'.$line['name'].'".');
            }
        }
        if (! $wanted) {
            throw new DomainException('Kies minstens één artikel.');
        }

        $return = DB::transaction(function () use ($order, $wanted, $reason, $comment) {
            $r = OrderReturn::create([
                'rma_number' => $this->invoices->nextNumber('RMA', (int) now()->format('Y')),
                'order_id' => $order->id, 'status' => 'requested', 'reason' => $reason,
                'comment' => $comment !== null ? mb_substr(trim(strip_tags($comment)), 0, 1000) : null,
            ]);
            foreach ($wanted as $itemId => $qty) {
                $r->items()->create(['order_item_id' => $itemId, 'qty' => $qty]);
            }

            return $r;
        });
        $return->load('items.orderItem');
        $this->mailCustomer($return, 'requested');
        $this->notifyOwner('Nieuwe retouraanvraag '.$return->rma_number, "Bestelling {$order->order_no} ({$order->customer_name}, {$order->email})\nReden: {$reason}\nToelichting: ".($return->comment ?: '-')."\n\nBeoordeel de aanvraag in het beheer onder Retouren.");

        return $return;
    }

    public function approve(OrderReturn $r, ?string $note, ?string $tracking): OrderReturn
    {
        $this->assertStatus($r, ['requested']);
        $r->update(['status' => 'approved', 'approved_at' => now(), 'admin_note' => $this->clean($note), 'return_tracking' => (string) $this->clean($tracking)]);
        $this->mailCustomer($r->load('items.orderItem'), 'approved');

        return $r;
    }

    public function reject(OrderReturn $r, string $note): OrderReturn
    {
        $this->assertStatus($r, ['requested', 'approved']);
        $r->update(['status' => 'rejected', 'rejected_at' => now(), 'admin_note' => $this->clean($note)]);
        $this->mailCustomer($r->load('items.orderItem'), 'rejected');

        return $r;
    }

    /** Pakket ontvangen. Optioneel de artikelen terug op voorraad zetten (alleen voor producten/varianten met voorraadbeheer). */
    public function receive(OrderReturn $r, bool $restock): OrderReturn
    {
        $this->assertStatus($r, ['approved']);
        $r->load('items.orderItem');
        if ($restock) {
            foreach ($r->items as $ri) {
                $back = $this->stock->restockItem($ri->orderItem, $ri->qty - $ri->restocked);
                $ri->update(['restocked' => $ri->restocked + $back]);
            }
        }
        $r->update(['status' => 'received', 'received_at' => now()]);

        return $r;
    }

    /** Betaalt $amount (incl. btw) terug via Stripe, maakt de creditnota en sluit de retour af. Is daarmee de hele bestelling terugbetaald, dan wordt die ook zo gemarkeerd. */
    public function refund(OrderReturn $r, float $amount, StripeGateway $stripe): OrderReturn
    {
        $this->assertStatus($r, ['approved', 'received']);
        $order = $r->order;
        $remaining = round((float) $order->total - $this->invoices->returnRefunds($order), 2);
        $amount = round($amount, 2);
        if ($order->status !== 'paid' || ! $order->payment_intent_id) {
            throw new DomainException('Deze bestelling kan niet (meer) worden terugbetaald.');
        }
        if ($amount < 0.01 || $amount > $remaining + 0.001) {
            throw new DomainException('Het bedrag moet tussen € 0,01 en € '.number_format($remaining, 2, ',', '.').' liggen.');
        }

        // Eerst Stripe (met idempotency-sleutel per retour: een herhaalde klik betaalt nooit dubbel terug), daarna pas de administratie.
        // Het vlaggetje houdt de Stripe-webhook "charge.refunded" even buiten de deur, zodat die niet ook nog een eigen creditnota aanmaakt.
        $flag = 'rma-refunding.'.$order->id;
        Cache::put($flag, 1, 180);
        try {
            $refundId = $stripe->refundAmount($order->payment_intent_id, (int) round($amount * 100), 'rma-'.$r->rma_number);
            DB::transaction(function () use ($r, $order, $amount, $refundId, $remaining) {
                $r->update(['status' => 'refunded', 'refunded_at' => now(), 'refund_amount' => $amount, 'refund_reference' => $refundId]);
                $this->invoices->ensureReturnCreditNote($r);
                if ($remaining - $amount < 0.01) {
                    $order->update(['status' => 'refunded', 'cj_wait' => false]); // volledig terugbetaald (de retour-creditnota's dekken dit)
                }
            });
        } finally {
            Cache::forget($flag);
        }
        $this->mailCustomer($r->load('items.orderItem'), 'refunded');

        return $r;
    }

    private function assertStatus(OrderReturn $r, array $allowed): void
    {
        if (! in_array($r->status, $allowed, true)) {
            throw new DomainException('Deze actie kan niet bij status "'.$r->status.'".');
        }
    }

    private function clean(?string $s): ?string
    {
        $s = $s === null ? null : trim(strip_tags($s));

        return $s === '' ? null : mb_substr((string) $s, 0, 1000);
    }

    private function mailCustomer(OrderReturn $r, string $kind): void
    {
        try {
            if ($r->order->email) {
                Mail::to($r->order->email)->send(new ReturnMail($r, $kind));
            }
        } catch (Throwable $e) {
            Log::warning('Retourmail mislukt', ['rma' => $r->rma_number, 'kind' => $kind, 'error' => $e->getMessage()]);
        }
    }

    private function notifyOwner(string $subject, string $body): void
    {
        $to = config('shop.owner_alert_email') ?: (Setting::brand()['email'] ?? null);
        if (! $to) {
            return;
        }
        try {
            Mail::raw($body, fn ($m) => $m->to($to)->subject($subject));
        } catch (Throwable $e) {
            Log::warning('Melding aan eigenaar mislukt', ['error' => $e->getMessage()]);
        }
    }
}
