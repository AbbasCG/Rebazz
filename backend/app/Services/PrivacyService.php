<?php

namespace App\Services;

use App\Models\NewsletterSubscriber;
use App\Models\Order;
use App\Models\OrderReturn;
use App\Models\PrivacyRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * AVG/GDPR voor een winkel zonder klantaccounts: inzage/export, wissen en bewaartermijnen.
 *
 * Wissen kan niet alles: factuur- en bestelgegevens moeten fiscaal 7 jaar bewaard blijven (wettelijke verplichting, art. 17 lid 3 sub b AVG).
 * Daarom: de nieuwsbriefaanmelding wordt direct verwijderd, overbodige gegevens (telefoon, vrije tekst) direct gewist, de rest van een
 * bestelling blijft bewaard tot het einde van de bewaartermijn en wordt daarna automatisch geanonimiseerd.
 */
class PrivacyService
{
    public const REMOVED = 'Geanonimiseerd';

    public function retentionYears(): int
    {
        return max(1, (int) config('shop.retention_years'));
    }

    /** Vanaf welk moment een bestelling niet meer bewaard hoeft te worden (betaaldatum + bewaartermijn). */
    public function retainUntil(Order $order): Carbon
    {
        return ($order->invoice_issued_at ?? $order->paid_at ?? $order->created_at)->copy()->addYears($this->retentionYears());
    }

    private function ordersFor(string $email)
    {
        return Order::whereRaw('lower(email) = ?', [strtolower(trim($email))])->orderBy('id');
    }

    /** @return array{orders: int, returns: int, newsletter: bool} wat er over dit adres bekend is (zonder de gegevens zelf) */
    public function summary(string $email): array
    {
        $orders = $this->ordersFor($email)->get();

        return [
            'orders' => $orders->count(),
            'returns' => OrderReturn::whereIn('order_id', $orders->pluck('id'))->count(),
            'newsletter' => NewsletterSubscriber::whereRaw('lower(email) = ?', [strtolower(trim($email))])->exists(),
        ];
    }

    public function hasData(string $email): bool
    {
        $s = $this->summary($email);

        return $s['orders'] > 0 || $s['newsletter'];
    }

    /** Alle gegevens die we over dit e-mailadres hebben, in een leesbare, machineleesbare vorm (art. 15 en 20 AVG). Zonder interne gegevens zoals inkoopprijzen. */
    public function export(string $email): array
    {
        $email = strtolower(trim($email));
        $orders = $this->ordersFor($email)->with('items', 'returns.items.orderItem')->get();
        $sub = NewsletterSubscriber::whereRaw('lower(email) = ?', [$email])->first();

        return [
            'generated_at' => now()->toIso8601String(),
            'subject_email' => $email,
            'controller' => \App\Models\Setting::brand(),
            'newsletter' => $sub ? ['email' => $sub->email, 'language' => $sub->lang, 'subscribed_at' => $sub->created_at?->toIso8601String(), 'consent_confirmed_at' => $sub->confirmed_at?->toIso8601String()] : null,
            'orders' => $orders->map(fn (Order $o) => [
                'order_number' => $o->order_no,
                'date' => $o->paid_at?->toIso8601String(),
                'status' => $o->status,
                'name' => $o->customer_name, 'email' => $o->email, 'phone' => $o->phone,
                'address' => $o->address, 'country' => $o->country, 'language' => $o->lang,
                'payment_method' => $o->payment_method,
                'shipping_method' => $o->ship_method, 'carrier' => $o->carrier, 'tracking' => $o->tracking,
                'totals' => ['subtotal' => $o->subtotal, 'discount' => $o->discount, 'shipping' => $o->shipping, 'total' => $o->total, 'vat' => $o->tax_amount, 'currency' => 'EUR'],
                'invoice_number' => $o->invoice_number, 'credit_note_number' => $o->credit_note_number,
                'items' => $o->items->map(fn ($i) => ['name' => $i->name, 'variant' => $i->variant_label ?: null, 'sku' => $i->sku, 'quantity' => $i->qty, 'unit_price' => $i->price])->values(),
                'returns' => $o->returns->map(fn (OrderReturn $r) => [
                    'rma_number' => $r->rma_number, 'status' => $r->status, 'reason' => $r->reason, 'comment' => $r->comment, 'message_from_shop' => $r->admin_note,
                    'refund_amount' => $r->refund_amount, 'credit_note_number' => $r->credit_note_number,
                    'items' => $r->items->map(fn ($i) => ['name' => $i->orderItem->name, 'quantity' => $i->qty])->values(),
                ])->values(),
                'anonymized_at' => $o->anonymized_at?->toIso8601String(),
                'retained_until' => $this->retainUntil($o)->toDateString(),
            ])->values(),
            'recipients' => 'Betalingen worden verwerkt door Stripe; bestellingen worden uitgevoerd door CJdropshipping (leverancier); e-mail via onze mailprovider. Zij hebben de gegevens nodig om uw bestelling uit te voeren.',
            'retention' => 'Bestel- en factuurgegevens worden '.$this->retentionYears().' jaar bewaard (wettelijke bewaarplicht) en daarna geanonimiseerd. Nieuwsbriefgegevens bewaren we tot u zich afmeldt.',
        ];
    }

    /**
     * Wist wat gewist kan worden en laat de rest bewaren onder de wettelijke plicht.
     *
     * @return array{newsletter_deleted: bool, orders_minimized: int, orders_anonymized: int, orders_retained: array<int, array{order: string, until: string}>}
     */
    public function erase(string $email): array
    {
        $email = strtolower(trim($email));
        $result = ['newsletter_deleted' => false, 'orders_minimized' => 0, 'orders_anonymized' => 0, 'orders_retained' => []];

        DB::transaction(function () use ($email, &$result) {
            $result['newsletter_deleted'] = NewsletterSubscriber::whereRaw('lower(email) = ?', [$email])->delete() > 0;
            \App\Models\AbandonedCart::where('email', $email)->delete();
            \App\Models\NewsletterDelivery::where('email', $email)->delete();
            foreach ($this->ordersFor($email)->with('returns')->get() as $order) {
                if ($order->anonymized_at) {
                    continue;
                }
                if (now()->greaterThanOrEqualTo($this->retainUntil($order))) {
                    $this->anonymize($order);
                    $result['orders_anonymized']++;

                    continue;
                }
                // Binnen de bewaartermijn: naam, adres en e-mail blijven (factuur), maar telefoonnummer en vrije tekst zijn niet nodig.
                $order->update(['phone' => '']);
                $order->returns()->update(['comment' => null]);
                $result['orders_minimized']++;
                $result['orders_retained'][] = ['order' => $order->order_no, 'until' => $this->retainUntil($order)->toDateString()];
            }
        });

        return $result;
    }

    /** Verwijdert alle persoonsgegevens uit een bestelling; bedragen en regels blijven voor de administratie en btw-aangifte. */
    public function anonymize(Order $order): void
    {
        $order->update([
            'customer_name' => self::REMOVED, 'email' => 'anon-'.$order->id.'@deleted.invalid', 'phone' => '', 'address' => [],
            'tracking' => '', 'anonymized_at' => now(),
        ]);
        $order->returns()->update(['comment' => null, 'admin_note' => null]);
    }

    /**
     * Periodieke opruiming (dagelijks): bestellingen na de bewaartermijn anonimiseren, niet-bevestigde nieuwsbriefaanmeldingen (30 dagen) verwijderen
     * en oude verzoeklogboeken (3 jaar) opruimen.
     *
     * @return array{anonymized: int, newsletter_pending_deleted: int, requests_deleted: int, accounts_deleted: int}
     */
    public function runRetention(): array
    {
        $cut = now()->subYears($this->retentionYears());
        $anonymized = 0;
        Order::whereNull('anonymized_at')
            ->whereRaw('coalesce(invoice_issued_at, paid_at, created_at) <= ?', [$cut])
            ->chunkById(100, function ($orders) use (&$anonymized) {
                foreach ($orders as $o) {
                    $this->anonymize($o);
                    $anonymized++;
                }
            });
        $pending = NewsletterSubscriber::whereNull('confirmed_at')->where('created_at', '<', now()->subDays(30))->delete();
        $requests = PrivacyRequest::where('requested_at', '<', now()->subYears(3))->delete();
        // Klantaccounts: verlopen inlogcodes opruimen en accounts die 3 jaar niet zijn gebruikt verwijderen (opslagbeperking).
        \App\Models\LoginCode::where('expires_at', '<', now()->subDay())->delete();
        app(MarketingService::class)->prune();
        app(AnalyticsService::class)->prune();
        $stale = \App\Models\Customer::where(fn ($q) => $q->where('last_login_at', '<', now()->subYears(3))->orWhere(fn ($n) => $n->whereNull('last_login_at')->where('created_at', '<', now()->subYears(3))))->pluck('id');
        if ($stale->isNotEmpty()) {
            DB::table('personal_access_tokens')->where('tokenable_type', \App\Models\Customer::class)->whereIn('tokenable_id', $stale)->delete();
            \App\Models\Customer::whereIn('id', $stale)->delete();
        }

        return ['anonymized' => $anonymized, 'newsletter_pending_deleted' => $pending, 'requests_deleted' => $requests, 'accounts_deleted' => $stale->count()];
    }

    public function log(string $email, string $type, string $source, array $result = [], bool $completed = true): PrivacyRequest
    {
        return PrivacyRequest::create([
            'email_hash' => PrivacyRequest::hashEmail($email), 'type' => $type, 'source' => $source, 'status' => $completed ? 'completed' : 'pending',
            'result' => $result ?: null, 'requested_at' => now(), 'completed_at' => $completed ? now() : null,
        ]);
    }
}
