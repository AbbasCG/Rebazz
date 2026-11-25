<?php

namespace App\Http\Controllers;

use App\Models\NewsletterSubscriber;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Services\CjService;
use App\Services\OrderRecorder;
use App\Services\Pricing;
use App\Services\StripeGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Throwable;

/** Openbare API voor de webwinkel. */
class ShopController extends Controller
{
    private const STRIPE_LOCALES = ['nl', 'en', 'de', 'fr', 'es', 'it', 'pl'];
    private const SHIP_LABEL = ['nl' => 'Verzending', 'en' => 'Shipping', 'de' => 'Versand', 'fr' => 'Livraison', 'es' => 'Envío', 'it' => 'Spedizione', 'pl' => 'Wysyłka', 'ar' => 'الشحن'];

    public function catalog(): JsonResponse
    {
        return response()->json([
            'brand' => Setting::brand(),
            'currency' => config('shop.currency'),
            'zones' => config('shop.zones'),
            'reviews' => Setting::get('reviews', []),
            'products' => Product::active()->orderBy('created_at')->orderBy('id')->get()->map->toApi()->values(),
        ]);
    }

    public function checkout(Request $request, Pricing $pricing, StripeGateway $stripe): JsonResponse
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:30'],
            'items.*.id' => ['required', 'string', 'max:40'],
            'items.*.qty' => ['required', 'integer', 'min:1', 'max:20'],
            'items.*.name' => ['nullable', 'string', 'max:200'],
            'country' => ['required', 'string', 'size:2'],
            'ship' => ['nullable', 'in:standard,express'],
            'lang' => ['nullable', 'string', Rule::in([...self::STRIPE_LOCALES, 'ar'])],
        ]);
        // Dubbele regels met hetzelfde product samenvoegen, zodat het maximum van 20 per product niet te omzeilen is.
        $merged = [];
        foreach ($data['items'] as $i) {
            $merged[$i['id']] = ['id' => $i['id'], 'name' => $i['name'] ?? null, 'qty' => ($merged[$i['id']]['qty'] ?? 0) + $i['qty']];
        }
        foreach ($merged as $m) {
            if ($m['qty'] > 20) {
                return response()->json(['error' => 'quantity_limit', 'message' => 'Maximaal 20 stuks per product.'], 422);
            }
        }
        $data['items'] = array_values($merged);
        $country = strtoupper($data['country']);
        $q = $pricing->quote($data['items'], $country, $data['ship'] ?? 'standard');
        if (isset($q['error'])) {
            return response()->json(['error' => $q['error']], 422);
        }
        if (! $stripe->configured()) {
            return response()->json(['error' => 'payment_unavailable', 'message' => 'Betalen is nog niet ingesteld.'], 503);
        }

        if ($stripe->mode() === 'live' && ! config('shop.stripe.tax')) {
            Log::error('Live-modus zonder STRIPE_TAX=on: checkout geweigerd, btw zou niet berekend worden.');

            return response()->json(['error' => 'payment_unavailable', 'message' => 'Betalen is tijdelijk niet beschikbaar.'], 503);
        }

        $lang = $data['lang'] ?? 'en';
        $names = collect($data['items'])->mapWithKeys(fn ($i) => [$i['id'] => trim(strip_tags($i['name'] ?? ''))]);
        $orderNo = 'RB-'.strtoupper(base_convert((string) (int) (microtime(true) * 1000), 10, 36)).strtoupper(Str::random(2));
        $origin = rtrim((string) config('shop.frontend_url'), '/');
        $tax = config('shop.stripe.tax');
        $packed = collect($q['lines'])->map(fn ($l) => $l['product']->id.':'.$l['qty'])->implode(',');

        try {
            $session = $stripe->createCheckoutSession(array_filter([
                'mode' => 'payment',
                'locale' => in_array($lang, self::STRIPE_LOCALES, true) ? $lang : 'auto',
                'line_items' => collect($q['lines'])->map(fn ($l) => [
                    'quantity' => $l['qty'],
                    'price_data' => array_filter([
                        'currency' => config('shop.currency'),
                        'unit_amount' => (int) round($l['product']->price * 100),
                        'tax_behavior' => $tax ? 'inclusive' : null,
                        'product_data' => array_filter([
                            'name' => mb_substr($names[$l['product']->id] ?: $l['product']->name, 0, 120),
                            'images' => str_starts_with($l['product']->image, 'https://') ? [$l['product']->image] : null,
                            'metadata' => ['sku' => $l['product']->sku, 'product_id' => $l['product']->id],
                        ]),
                    ]),
                ])->all(),
                'shipping_address_collection' => ['allowed_countries' => [$country]],
                'shipping_options' => [[
                    'shipping_rate_data' => array_filter([
                        'type' => 'fixed_amount',
                        'display_name' => ($q['express'] ? 'Express — ' : '').(self::SHIP_LABEL[$lang] ?? self::SHIP_LABEL['en']),
                        'fixed_amount' => ['amount' => (int) round($q['shipping'] * 100), 'currency' => config('shop.currency')],
                        'tax_behavior' => $tax ? 'inclusive' : null,
                        'delivery_estimate' => ['minimum' => ['unit' => 'business_day', 'value' => $q['days'][0]], 'maximum' => ['unit' => 'business_day', 'value' => $q['days'][1]]],
                    ]),
                ]],
                'billing_address_collection' => 'auto',
                'phone_number_collection' => ['enabled' => true],
                'allow_promotion_codes' => (bool) config('shop.allow_promotion_codes'),
                'tax_id_collection' => ['enabled' => true],
                'automatic_tax' => $tax ? ['enabled' => true] : null,
                'metadata' => ['order_no' => $orderNo, 'items' => $packed, 'lang' => $lang, 'country' => $country, 'ship' => $q['express'] ? 'express' : 'standard'],
                'payment_intent_data' => ['description' => 'Rebazz '.$orderNo, 'metadata' => ['order_no' => $orderNo]],
                'success_url' => $origin.'/bedankt?session_id={CHECKOUT_SESSION_ID}',
                'cancel_url' => $origin.'/checkout',
            ], fn ($v) => $v !== null));
        } catch (Throwable $e) {
            Log::error('Stripe checkout mislukt', ['error' => $e->getMessage()]);

            return response()->json(['error' => 'payment_unavailable', 'message' => 'Betalen is tijdelijk niet beschikbaar.'], 502);
        }

        return response()->json(['url' => $session['url'], 'order_no' => $orderNo]);
    }

    /** Bevestiging op de bedankpagina. Legt de bestelling ook vast als de webhook nog niet binnen is. */
    public function confirm(Request $request, StripeGateway $stripe, OrderRecorder $recorder, CjService $cj): JsonResponse
    {
        $id = (string) $request->query('session_id', '');
        if (! preg_match('/^cs_(test|live)_[A-Za-z0-9]+$/', $id)) {
            return response()->json(['error' => 'invalid'], 422);
        }
        $order = Order::where('stripe_session_id', $id)->first();
        if (! $order) {
            try {
                $s = $stripe->retrieveSession($id);
            } catch (Throwable) {
                return response()->json(['error' => 'not_found'], 404);
            }
            if (($s['payment_status'] ?? '') !== 'paid') {
                return response()->json(['order_no' => $s['metadata']['order_no'] ?? '', 'paid' => false, 'total' => ($s['amount_total'] ?? 0) / 100, 'email' => '', 'country' => '']);
            }
            $order = $recorder->record($s);
            if ($order && $order->wasRecentlyCreated && $cj->enabled()) {
                $cj->placeOrder($order);
            }
        }

        return response()->json([
            'order_no' => $order->order_no,
            'paid' => true,
            'total' => $order->total,
            'email' => preg_replace('/^(.).*(@.*)$/', '$1•••$2', $order->email),
            'country' => $order->country,
        ]);
    }

    public function track(Request $request): JsonResponse
    {
        $data = $request->validate(['order_no' => ['required', 'string', 'max:30'], 'email' => ['required', 'email', 'max:200']]);
        $orderNo = strtoupper(trim($data['order_no']));
        // Per bestelnummer begrensd (los van het IP), zodat gedistribueerd raden van e-mailadressen niet werkt.
        $lockKey = 'track:'.$orderNo;
        if (RateLimiter::tooManyAttempts($lockKey, 8)) {
            return response()->json(['error' => 'too_many_attempts'], 429);
        }
        $order = Order::where('order_no', $orderNo)->first();
        if (! $order || strcasecmp($order->email, trim($data['email'])) !== 0) {
            RateLimiter::hit($lockKey, 3600);

            return response()->json(['error' => 'not_found'], 404);
        }

        return response()->json([
            'order_no' => $order->order_no, 'date' => $order->paid_at?->toIso8601String(), 'step' => $order->step, 'carrier' => $order->carrier,
            'tracking' => $order->tracking, 'delayed' => $order->delayed, 'refunded' => $order->status === 'refunded',
        ]);
    }

    /** Herroepingsformulier: bericht aan de eigenaar en een ontvangstbevestiging aan de klant (op een duurzame drager). */
    public function withdrawal(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email:rfc', 'max:200'],
            'order_no' => ['required', 'string', 'max:30'],
            'items' => ['required', 'string', 'max:1000'],
            'message' => ['nullable', 'string', 'max:2000'],
        ]);
        $brand = Setting::brand();
        $owner = $brand['email'] ?? config('shop.brand.email');
        $name = strip_tags($data['name']);
        $orderNo = strtoupper(trim(preg_replace('/\s+/', ' ', strip_tags($data['order_no']))));
        $items = strip_tags($data['items']);
        $note = strip_tags((string) ($data['message'] ?? ''));
        $at = now()->timezone('Europe/Amsterdam')->format('d-m-Y H:i');

        try {
            Mail::raw("Herroeping ontvangen ($at)\n\nNaam: $name\nE-mail: {$data['email']}\nBestelnummer: $orderNo\nProducten: $items\nOpmerking: $note\n", function ($m) use ($owner, $data, $orderNo) {
                $m->to($owner)->replyTo($data['email'])->subject('Herroeping '.$orderNo);
            });
            Mail::raw("Beste $name,\n\nWij hebben uw herroeping van bestelling $orderNo ontvangen op $at.\nProducten: $items\n\nU ontvangt van ons binnen 24 uur het retouradres. Het aankoopbedrag wordt binnen 14 dagen na ontvangst van uw herroeping terugbetaald, volgens onze voorwaarden.\n\nMet vriendelijke groet,\n".($brand['name'] ?? 'Rebazz'), function ($m) use ($data, $orderNo) {
                $m->to($data['email'])->subject('Bevestiging herroeping '.$orderNo);
            });
        } catch (Throwable $e) {
            Log::error('Herroeping mailen mislukt', ['order' => $orderNo, 'error' => $e->getMessage()]);

            return response()->json(['error' => 'mail_failed', 'message' => 'Verzenden is niet gelukt. Mail ons direct op '.$owner.'.'], 502);
        }

        return response()->json(['ok' => true]);
    }

    public function newsletter(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email:rfc', 'max:200'], 'lang' => ['nullable', 'string', 'max:5']]);
        NewsletterSubscriber::updateOrCreate(['email' => strtolower($data['email'])], ['lang' => substr($data['lang'] ?? 'nl', 0, 2)]);

        return response()->json(['ok' => true]);
    }

    /** Stripe meldt een geslaagde betaling: bestelling opslaan en bij CJ plaatsen. */
    public function stripeWebhook(Request $request, StripeGateway $stripe, OrderRecorder $recorder, CjService $cj): JsonResponse
    {
        try {
            $event = $stripe->verifyWebhook($request->getContent(), (string) $request->header('Stripe-Signature'));
        } catch (Throwable) {
            return response()->json(['error' => 'invalid_signature'], 400);
        }
        $type = $event['type'] ?? '';
        if (in_array($type, ['charge.refunded', 'charge.dispute.created'], true)) {
            return $this->handleRefundOrDispute($type, $event['data']['object'] ?? [], $cj);
        }
        if (! in_array($type, ['checkout.session.completed', 'checkout.session.async_payment_succeeded'], true)) {
            return response()->json(['ignored' => $type]);
        }
        try {
            $session = $stripe->retrieveSession($event['data']['object']['id']);
            $order = $recorder->record($session);
            if ($order && $cj->enabled() && ! $order->cj_order_ids && $order->cj_status === '') {
                $cj->placeOrder($order);
            }
        } catch (Throwable $e) {
            Log::error('Webhook verwerken mislukt', ['error' => $e->getMessage()]);

            return response()->json(['error' => 'failed'], 500); // Stripe probeert het later opnieuw
        }

        return response()->json(['ok' => true]);
    }

    /** Terugbetaling of betwisting (ook buiten ons beheer, bijv. in het Stripe-dashboard): bestelling bijwerken en eigenaar waarschuwen. */
    private function handleRefundOrDispute(string $type, array $obj, CjService $cj): JsonResponse
    {
        $pi = $obj['payment_intent'] ?? null;
        $order = $pi ? Order::where('payment_intent_id', $pi)->first() : null;
        if (! $order) {
            return response()->json(['ignored' => 'order_not_found']);
        }
        if ($type === 'charge.refunded') {
            if (! ($obj['refunded'] ?? false)) {
                return response()->json(['ignored' => 'partial_refund']);
            }
            if ($order->status !== 'refunded') {
                $order->update(['status' => 'refunded', 'cj_wait' => false]);
                if ($order->cj_order_ids) {
                    $cj->alertOwner($order, 'Bestelling is terugbetaald in Stripe maar staat al bij CJ ('.$order->cj_order_ids.'). Annuleer de CJ-bestelling indien mogelijk.');
                }
            }
        } else {
            $order->update(['cj_wait' => false]);
            $cj->alertOwner($order, 'Betwisting (dispute) geopend voor deze bestelling. Lever bewijs aan in Stripe en stop verzending indien mogelijk.');
        }

        return response()->json(['ok' => true]);
    }
}
