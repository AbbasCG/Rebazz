<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Services\StripeGateway;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Test de webwinkel-API samen met de PostgreSQL-database (Stripe en CJ worden nagebootst). */
class ShopApiTest extends TestCase
{
    use RefreshDatabase;

    private FakeStripe $stripe;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->stripe = new FakeStripe;
        $this->app->instance(StripeGateway::class, $this->stripe);
    }

    private function paidSession(array $overrides = []): array
    {
        return array_replace_recursive([
            'id' => 'cs_test_abc123', 'created' => time(), 'livemode' => false, 'payment_status' => 'paid',
            'amount_subtotal' => 15990, 'amount_total' => 15990, 'total_details' => ['amount_shipping' => 0, 'amount_discount' => 0],
            'metadata' => ['order_no' => 'RB-TEST01', 'items' => 'p1:2', 'lang' => 'de', 'country' => 'DE', 'ship' => 'standard'],
            'customer_details' => ['email' => 'anna@example.de', 'name' => 'Anna Becker', 'phone' => '+4930123'],
            'collected_information' => ['shipping_details' => ['name' => 'Anna Becker', 'address' => ['line1' => 'Hauptstr. 1', 'postal_code' => '10115', 'city' => 'Berlin', 'country' => 'DE']]],
            'payment_intent' => ['id' => 'pi_123', 'latest_charge' => ['payment_method_details' => ['type' => 'card'], 'refunded' => false]],
        ], $overrides);
    }

    public function test_catalog_returns_only_active_products_without_purchase_prices(): void
    {
        $res = $this->getJson('/api/catalog')->assertOk();
        $this->assertCount(9, $res->json('products'));
        $this->assertArrayNotHasKey('cost', $res->json('products.0'));
        $this->assertSame('90147146', $res->json('brand.kvk'));
        $this->assertCount(5, $res->json('zones'));
    }

    public function test_checkout_uses_database_prices_not_browser_input(): void
    {
        $this->postJson('/api/checkout', ['items' => [['id' => 'p1', 'qty' => 2, 'name' => 'Aura', 'price' => 0.01]], 'country' => 'DE', 'lang' => 'de'])
            ->assertOk()->assertJsonPath('url', 'https://checkout.stripe.test/pay');
        $p = $this->stripe->lastParams;
        $this->assertSame(7995, $p['line_items'][0]['price_data']['unit_amount']);
        $this->assertSame(2, $p['line_items'][0]['quantity']);
        $this->assertSame(0, $p['shipping_options'][0]['shipping_rate_data']['fixed_amount']['amount']); // gratis vanaf € 69
        $this->assertSame(['DE'], $p['shipping_address_collection']['allowed_countries']);
        $this->assertSame('de', $p['locale']);
        $this->assertSame('p1:2', $p['metadata']['items']);
    }

    public function test_checkout_rejects_hidden_products_and_unknown_countries(): void
    {
        $this->postJson('/api/checkout', ['items' => [['id' => 'p4', 'qty' => 1]], 'country' => 'NL'])->assertStatus(422);
        $this->postJson('/api/checkout', ['items' => [['id' => 'p1', 'qty' => 1]], 'country' => 'XX'])->assertStatus(422);
        $this->postJson('/api/checkout', ['items' => [['id' => 'p1', 'qty' => 99]], 'country' => 'NL'])->assertStatus(422);
    }

    public function test_express_shipping_is_charged(): void
    {
        $this->postJson('/api/checkout', ['items' => [['id' => 'p1', 'qty' => 1]], 'country' => 'NL', 'ship' => 'express'])->assertOk();
        $this->assertSame(1295, $this->stripe->lastParams['shipping_options'][0]['shipping_rate_data']['fixed_amount']['amount']);
    }

    public function test_webhook_records_order_once_and_flags_manual_without_cj(): void
    {
        $this->stripe->session = $this->paidSession();
        $payload = json_encode(['type' => 'checkout.session.completed', 'data' => ['object' => ['id' => 'cs_test_abc123']]]);
        $this->call('POST', '/api/stripe/webhook', [], [], [], ['HTTP_STRIPE_SIGNATURE' => 'ok', 'CONTENT_TYPE' => 'application/json'], $payload)->assertOk();
        $this->call('POST', '/api/stripe/webhook', [], [], [], ['HTTP_STRIPE_SIGNATURE' => 'ok', 'CONTENT_TYPE' => 'application/json'], $payload)->assertOk();

        $this->assertSame(1, Order::count());
        $o = Order::first();
        $this->assertSame('RB-TEST01', $o->order_no);
        $this->assertSame(159.9, $o->total);
        $this->assertSame(48.2, $o->cost);
        $this->assertSame('Hauptstr. 1, 10115 Berlin, DE', $o->addressLine());
        $this->assertSame(2, $o->items()->first()->qty);
    }

    public function test_webhook_with_bad_signature_is_rejected(): void
    {
        $this->call('POST', '/api/stripe/webhook', [], [], [], ['HTTP_STRIPE_SIGNATURE' => 'fout'], '{}')->assertStatus(400);
        $this->assertSame(0, Order::count());
    }

    public function test_thank_you_page_confirms_and_records_order(): void
    {
        $this->stripe->session = $this->paidSession();
        $this->getJson('/api/orders/confirm?session_id=cs_test_abc123')
            ->assertOk()->assertJson(['order_no' => 'RB-TEST01', 'paid' => true, 'email' => 'a•••@example.de']);
        $this->assertSame(1, Order::count());
    }

    public function test_tracking_requires_matching_email(): void
    {
        $this->stripe->session = $this->paidSession();
        $this->getJson('/api/orders/confirm?session_id=cs_test_abc123');
        $this->postJson('/api/orders/track', ['order_no' => 'RB-TEST01', 'email' => 'fout@example.de'])->assertNotFound();
        $this->postJson('/api/orders/track', ['order_no' => 'rb-test01', 'email' => 'ANNA@example.de'])->assertOk()->assertJsonPath('step', 0);
    }

    public function test_tracking_locks_per_order_after_repeated_wrong_emails(): void
    {
        $this->stripe->session = $this->paidSession();
        $this->getJson('/api/orders/confirm?session_id=cs_test_abc123');
        for ($i = 0; $i < 8; $i++) {
            $this->postJson('/api/orders/track', ['order_no' => 'RB-TEST01', 'email' => "fout$i@example.de"])->assertNotFound();
        }
        $this->postJson('/api/orders/track', ['order_no' => 'RB-TEST01', 'email' => 'anna@example.de'])->assertStatus(429);
    }

    public function test_withdrawal_form_mails_owner_and_confirms_to_customer(): void
    {
        config(['mail.default' => 'array']);
        $this->postJson('/api/withdrawal', ['name' => 'Anna <b>Becker</b>', 'email' => 'anna@example.de', 'order_no' => 'rb-test01', 'items' => 'Aura', 'message' => ''])->assertOk();
        $this->postJson('/api/withdrawal', ['name' => '', 'email' => 'geen-mail', 'order_no' => '', 'items' => ''])->assertStatus(422);
    }

    public function test_newsletter_signup_is_stored_once(): void
    {
        $this->postJson('/api/newsletter', ['email' => 'Klant@Example.nl', 'lang' => 'nl'])->assertOk();
        $this->postJson('/api/newsletter', ['email' => 'klant@example.nl', 'lang' => 'en'])->assertOk();
        $this->assertDatabaseCount('newsletter_subscribers', 1);
        $this->assertDatabaseHas('newsletter_subscribers', ['email' => 'klant@example.nl', 'lang' => 'en']);
    }

    public function test_cj_order_is_placed_per_warehouse_with_ioss(): void
    {
        config(['shop.cj.api_key' => 'CJ@api@key', 'shop.cj.auto_pay' => true]);
        Product::whereKey('p1')->update(['cj_vid' => 'VID-AURA', 'cj_from' => 'PL']);
        Product::whereKey('p3')->update(['cj_vid' => 'VID-CHRONO', 'cj_from' => 'CN']);
        Http::fake([
            '*/authentication/getAccessToken' => Http::response(['code' => 200, 'result' => true, 'data' => ['accessToken' => 'TOK']]),
            '*/logistic/freightCalculate' => Http::response(['code' => 200, 'result' => true, 'data' => [['logisticName' => 'DHL', 'logisticPrice' => '19'], ['logisticName' => 'CJPacket Ordinary', 'logisticPrice' => '4']]]),
            '*/shopping/order/createOrderV2' => fn ($req) => Http::response(['code' => 200, 'result' => true, 'data' => ['orderId' => 'CJ-'.$req['fromCountryCode']]]),
        ]);
        $this->stripe->session = $this->paidSession(['metadata' => ['items' => 'p1:1,p3:1', 'country' => 'NL'], 'collected_information' => ['shipping_details' => ['address' => ['country' => 'NL', 'city' => 'Breda']]]]);
        $this->getJson('/api/orders/confirm?session_id=cs_test_abc123')->assertOk();

        $o = Order::first();
        $this->assertSame('paid', $o->cj_status);
        $this->assertSame('CJ-PL,CJ-CN', $o->cj_order_ids);
        $this->assertSame(1, $o->step);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'createOrderV2') && $r['fromCountryCode'] === 'CN' && $r['iossType'] === 3 && $r['logisticName'] === 'CJPacket Ordinary' && $r['payType'] === 2 && $r->header('CJ-Access-Token')[0] === 'TOK');
        Http::assertSent(fn ($r) => str_contains($r->url(), 'createOrderV2') && $r['fromCountryCode'] === 'PL' && ! isset($r['iossType']));
    }

    private function webhook(array $event): \Illuminate\Testing\TestResponse
    {
        return $this->call('POST', '/api/stripe/webhook', [], [], [], ['HTTP_STRIPE_SIGNATURE' => 'ok', 'CONTENT_TYPE' => 'application/json'], json_encode($event));
    }

    public function test_checkout_merges_duplicate_lines_and_enforces_quantity_cap(): void
    {
        $this->postJson('/api/checkout', ['items' => [['id' => 'p1', 'qty' => 15], ['id' => 'p1', 'qty' => 15]], 'country' => 'NL'])->assertStatus(422);
        $this->postJson('/api/checkout', ['items' => [['id' => 'p1', 'qty' => 1], ['id' => 'p1', 'qty' => 2]], 'country' => 'NL'])->assertOk();
        $this->assertSame(3, $this->stripe->lastParams['line_items'][0]['quantity']);
        $this->assertCount(1, $this->stripe->lastParams['line_items']);
    }

    public function test_order_records_actual_stripe_tax_only_when_automatic_tax_is_on(): void
    {
        $this->stripe->session = $this->paidSession(['automatic_tax' => ['enabled' => true], 'total_details' => ['amount_tax' => 2775]]);
        $this->getJson('/api/orders/confirm?session_id=cs_test_abc123')->assertOk();
        $this->assertSame(27.75, Order::first()->tax_amount);

        Order::query()->delete();
        $this->stripe->session = $this->paidSession(['id' => 'cs_test_zzz', 'metadata' => ['order_no' => 'RB-TEST02'], 'automatic_tax' => ['enabled' => false], 'total_details' => ['amount_tax' => 0]]);
        $this->getJson('/api/orders/confirm?session_id=cs_test_zzz')->assertOk();
        $this->assertNull(Order::first()->tax_amount); // Stripe Tax uit: geen echte btw bekend
    }

    public function test_live_mode_checkout_is_refused_without_stripe_tax(): void
    {
        $live = new class extends FakeStripe { public function mode(): string { return 'live'; } };
        $this->app->instance(StripeGateway::class, $live);
        config(['shop.stripe.tax' => false]);
        $this->postJson('/api/checkout', ['items' => [['id' => 'p1', 'qty' => 1]], 'country' => 'NL'])->assertStatus(503);
        config(['shop.stripe.tax' => true]);
        $this->postJson('/api/checkout', ['items' => [['id' => 'p1', 'qty' => 1]], 'country' => 'NL'])->assertOk();
    }

    public function test_checkout_rejects_unknown_language(): void
    {
        $this->postJson('/api/checkout', ['items' => [['id' => 'p1', 'qty' => 1]], 'country' => 'NL', 'lang' => 'xx'])->assertStatus(422);
    }

    public function test_cj_order_is_not_placed_twice_when_already_claimed(): void
    {
        config(['shop.cj.api_key' => 'CJ@api@key']);
        Product::whereKey('p1')->update(['cj_vid' => 'VID-AURA', 'cj_from' => 'PL']);
        Http::fake(['*' => Http::response(['code' => 200, 'result' => true, 'data' => ['orderId' => 'CJ-1', 'accessToken' => 'TOK']])]);
        $this->stripe->session = $this->paidSession();
        $this->getJson('/api/orders/confirm?session_id=cs_test_abc123')->assertOk();
        $order = Order::first();
        $order->update(['cj_order_ids' => '', 'cj_status' => 'placing']); // een ander proces is al bezig
        app(\App\Services\CjService::class)->placeOrder($order);
        $this->assertSame('placing', $order->fresh()->cj_status);
        $this->assertSame('', (string) $order->fresh()->cj_order_ids);
    }

    public function test_margin_guard_blocks_auto_order_when_discount_makes_it_unprofitable(): void
    {
        config(['shop.cj.api_key' => 'CJ@api@key']);
        Product::whereKey('p1')->update(['cj_vid' => 'VID-AURA', 'cj_from' => 'PL']);
        Http::fake();
        // Betaald bedrag € 10 (bijv. 100%-achtige korting) terwijl de inkoop € 48,20 is.
        $this->stripe->session = $this->paidSession(['amount_total' => 1000, 'total_details' => ['amount_discount' => 14990]]);
        $this->getJson('/api/orders/confirm?session_id=cs_test_abc123')->assertOk();

        $o = Order::first();
        $this->assertSame('manual', $o->cj_status);
        $this->assertStringContainsString('Margebewaking', $o->cj_error);
        Http::assertNothingSent();
    }

    public function test_promotion_codes_can_be_disabled(): void
    {
        config(['shop.allow_promotion_codes' => false]);
        $this->postJson('/api/checkout', ['items' => [['id' => 'p1', 'qty' => 1]], 'country' => 'NL'])->assertOk();
        $this->assertFalse($this->stripe->lastParams['allow_promotion_codes']);
    }

    public function test_partial_cj_failure_keeps_first_order_id_and_flags_error(): void
    {
        config(['shop.cj.api_key' => 'CJ@api@key']);
        Product::whereKey('p1')->update(['cj_vid' => 'VID-AURA', 'cj_from' => 'PL']);
        Product::whereKey('p3')->update(['cj_vid' => 'VID-CHRONO', 'cj_from' => 'CN']);
        $calls = 0;
        Http::fake([
            '*/authentication/getAccessToken' => Http::response(['code' => 200, 'result' => true, 'data' => ['accessToken' => 'TOK']]),
            '*/logistic/freightCalculate' => Http::response(['code' => 200, 'result' => true, 'data' => [['logisticName' => 'CJPacket', 'logisticPrice' => '4']]]),
            '*/shopping/order/createOrderV2' => function () use (&$calls) {
                return ++$calls === 1
                    ? Http::response(['code' => 200, 'result' => true, 'data' => ['orderId' => 'CJ-FIRST']])
                    : Http::response(['code' => 500, 'result' => false, 'message' => 'boom'], 500);
            },
        ]);
        $this->stripe->session = $this->paidSession(['metadata' => ['items' => 'p1:1,p3:1', 'country' => 'NL'], 'collected_information' => ['shipping_details' => ['address' => ['country' => 'NL', 'city' => 'Breda']]]]);
        $this->getJson('/api/orders/confirm?session_id=cs_test_abc123')->assertOk();

        $o = Order::first();
        $this->assertSame('error', $o->cj_status);
        $this->assertSame('CJ-FIRST', $o->cj_order_ids);
        $this->assertStringContainsString('DEELS BESTELD', $o->cj_error);
    }

    public function test_refund_webhook_marks_order_refunded_and_ignores_partial_refunds(): void
    {
        $this->stripe->session = $this->paidSession();
        $this->webhook(['type' => 'checkout.session.completed', 'data' => ['object' => ['id' => 'cs_test_abc123']]])->assertOk();

        $this->webhook(['type' => 'charge.refunded', 'data' => ['object' => ['payment_intent' => 'pi_123', 'refunded' => false]]])->assertOk();
        $this->assertSame('paid', Order::first()->status);

        $this->webhook(['type' => 'charge.refunded', 'data' => ['object' => ['payment_intent' => 'pi_123', 'refunded' => true]]])->assertOk();
        $o = Order::first();
        $this->assertSame('refunded', $o->status);
        $this->assertFalse((bool) $o->cj_wait);
    }

    public function test_dispute_webhook_stops_tracking_sync_and_unknown_orders_are_ignored(): void
    {
        $this->stripe->session = $this->paidSession();
        $this->webhook(['type' => 'checkout.session.completed', 'data' => ['object' => ['id' => 'cs_test_abc123']]])->assertOk();
        Order::first()->update(['cj_wait' => true]);

        $this->webhook(['type' => 'charge.dispute.created', 'data' => ['object' => ['payment_intent' => 'pi_onbekend']]])->assertOk()->assertJson(['ignored' => 'order_not_found']);
        $this->assertTrue((bool) Order::first()->cj_wait);

        $this->webhook(['type' => 'charge.dispute.created', 'data' => ['object' => ['payment_intent' => 'pi_123']]])->assertOk();
        $this->assertFalse((bool) Order::first()->cj_wait);
    }
}

/** Nagebootste Stripe voor tests. */
class FakeStripe extends StripeGateway
{
    public array $lastParams = [];
    public array $session = [];
    public array $refunds = [];

    public function configured(): bool { return true; }
    public function mode(): string { return 'test'; }
    public function createCheckoutSession(array $params): array { $this->lastParams = $params; return ['id' => 'cs_test_new', 'url' => 'https://checkout.stripe.test/pay']; }
    public function retrieveSession(string $id): array { return $this->session; }
    public function listPaidSessions(int $max = 500): array { return [$this->session]; }
    public function refund(string $paymentIntentId): void { $this->refunds[] = $paymentIntentId; }
    public function verifyWebhook(string $payload, string $signature): array
    {
        if ($signature !== 'ok') { throw new \RuntimeException('bad signature'); }
        return json_decode($payload, true);
    }
}
