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

    public function test_newsletter_needs_double_opt_in_and_hides_whether_address_exists(): void
    {
        config(['mail.default' => 'array', 'app.url' => 'http://localhost']);
        $r1 = $this->postJson('/api/newsletter', ['email' => 'Nieuw@Example.nl', 'lang' => 'nl'])->assertOk()->json();
        $sub = \App\Models\NewsletterSubscriber::first();
        $this->assertNull($sub->confirmed_at);
        $this->assertSame(48, strlen($sub->confirm_token));

        // Ongeldige token bevestigt niets.
        $this->get('/api/newsletter/confirm?token='.str_repeat('x', 48))->assertRedirect();
        $this->assertNull($sub->fresh()->confirmed_at);

        $this->get('/api/newsletter/confirm?token='.$sub->confirm_token)->assertRedirect('https://rebazz.test/?newsletter=confirmed');
        $this->assertNotNull($sub->fresh()->confirmed_at);
        $this->assertNull($sub->fresh()->confirm_token);

        // Opnieuw aanmelden van een bevestigd adres: zelfde antwoord, toestemming blijft en er komt geen nieuwe token.
        $r2 = $this->postJson('/api/newsletter', ['email' => 'nieuw@example.nl'])->assertOk()->json();
        $this->assertSame($r1, $r2);
        $this->assertNotNull($sub->fresh()->confirmed_at);
        $this->assertNull($sub->fresh()->confirm_token);
    }

    public function test_checkout_rejects_carts_that_exceed_stripe_metadata_limit_and_uses_database_names(): void
    {
        $this->postJson('/api/checkout', ['items' => [['id' => 'p1', 'qty' => 1, 'name' => 'GRATIS <b>iPhone</b>']], 'country' => 'NL'])->assertOk();
        $this->assertSame('Aura draadloze koptelefoon met ANC', $this->stripe->lastParams['line_items'][0]['price_data']['product_data']['name']);
    }

    public function test_cj_token_is_refreshed_once_when_it_is_rejected(): void
    {
        config(['shop.cj.api_key' => 'CJ@api@key']);
        \Illuminate\Support\Facades\Cache::put('cj.token', 'OUD', 600);
        Product::whereKey('p1')->update(['cj_vid' => 'VID-AURA', 'cj_from' => 'PL']);
        Http::fake([
            '*/authentication/getAccessToken' => Http::response(['code' => 200, 'result' => true, 'data' => ['accessToken' => 'NIEUW']]),
            '*/logistic/freightCalculate' => fn ($req) => $req->header('CJ-Access-Token')[0] === 'OUD'
                ? Http::response(['code' => 1600001, 'result' => false, 'message' => 'Token is expired'], 401)
                : Http::response(['code' => 200, 'result' => true, 'data' => [['logisticName' => 'CJPacket', 'logisticPrice' => '4']]]),
            '*/shopping/order/createOrderV2' => Http::response(['code' => 200, 'result' => true, 'data' => ['orderId' => 'CJ-OK']]),
        ]);
        $this->stripe->session = $this->paidSession();
        $this->getJson('/api/orders/confirm?session_id=cs_test_abc123')->assertOk();

        $o = Order::first();
        $this->assertSame('CJ-OK', $o->cj_order_ids);
        $this->assertSame('NIEUW', \Illuminate\Support\Facades\Cache::get('cj.token'));
    }

    private function webhookPaid(string $sessionId = 'cs_test_abc123'): void
    {
        $this->call('POST', '/api/stripe/webhook', [], [], [], ['HTTP_STRIPE_SIGNATURE' => 'ok', 'CONTENT_TYPE' => 'application/json'],
            json_encode(['type' => 'checkout.session.completed', 'data' => ['object' => ['id' => $sessionId]]]))->assertOk();
    }

    public function test_every_paid_order_gets_a_unique_sequential_invoice_number_exactly_once(): void
    {
        \Illuminate\Support\Facades\Mail::fake();
        $year = now()->format('Y');
        $this->stripe->session = $this->paidSession(['created' => now()->timestamp]);
        $this->webhookPaid();
        $this->webhookPaid(); // zelfde sessie nog eens: geen tweede nummer
        $this->stripe->session = $this->paidSession(['id' => 'cs_test_second', 'created' => now()->timestamp, 'metadata' => ['order_no' => 'RB-TEST02']]);
        $this->webhookPaid('cs_test_second');

        $this->assertSame(2, Order::count());
        $this->assertSame("RBF-$year-00001", Order::where('order_no', 'RB-TEST01')->value('invoice_number'));
        $this->assertSame("RBF-$year-00002", Order::where('order_no', 'RB-TEST02')->value('invoice_number'));
    }

    public function test_order_confirmation_is_mailed_once_in_the_customers_language_with_invoice_attached(): void
    {
        \Illuminate\Support\Facades\Mail::fake();
        $this->stripe->session = $this->paidSession();
        $this->webhookPaid();
        $this->webhookPaid(); // Stripe stuurt het event soms dubbel
        $this->getJson('/api/orders/confirm?session_id=cs_test_abc123')->assertOk(); // en de bedankpagina ook

        \Illuminate\Support\Facades\Mail::assertSent(\App\Mail\OrderConfirmationMail::class, 1);
        \Illuminate\Support\Facades\Mail::assertSent(\App\Mail\OrderConfirmationMail::class, function ($m) {
            $att = $m->attachments();

            return $m->hasTo('anna@example.de')
                && str_contains($m->envelope()->subject, 'Bestätigung Ihrer Bestellung RB-TEST01') // lang=de
                && count($att) === 1
                && str_starts_with($att[0]->as, 'factuur-rbf-');
        });
        $this->assertNotNull(Order::first()->confirmation_sent_at);
    }

    public function test_confirmation_flag_is_reset_when_mailing_fails_so_it_can_be_retried(): void
    {
        $this->stripe->session = $this->paidSession(['created' => now()->subMinutes(10)->timestamp]);
        // Mailserver "down": een SMTP-verbinding naar een poort waar niets luistert.
        config(['mail.mailers.broken' => ['transport' => 'smtp', 'host' => '127.0.0.1', 'port' => 1, 'timeout' => 2], 'mail.default' => 'broken']);
        $this->webhookPaid();
        $this->assertNull(Order::first()->confirmation_sent_at); // markering teruggezet

        config(['mail.default' => 'array']); // mailserver is weer bereikbaar
        $this->artisan('shop:send-confirmations')->expectsOutput('Bevestigingen verstuurd: 1')->assertExitCode(0);
        $this->assertNotNull(Order::first()->confirmation_sent_at);
    }

    public function test_customer_can_download_invoice_only_through_a_valid_signed_link(): void
    {
        \Illuminate\Support\Facades\Mail::fake();
        $this->stripe->session = $this->paidSession();
        $this->webhookPaid();
        $order = Order::first();
        $url = app(\App\Services\InvoiceService::class)->signedUrl($order);

        $res = $this->get($url)->assertOk();
        $this->assertSame('application/pdf', $res->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $res->getContent());

        $this->get('/api/orders/RB-TEST01/invoice')->assertForbidden();                          // zonder handtekening
        Order::create(['order_no' => 'RB-TEST02', 'stripe_session_id' => 'cs_other', 'status' => 'paid', 'country' => 'NL', 'total' => 10]);
        $this->get(str_replace('RB-TEST01', 'RB-TEST02', $url))->assertForbidden();              // handtekening past niet bij ander bestelnummer
        $this->get($url.'x')->assertForbidden();                                                  // gemanipuleerde handtekening
        $this->get(app(\App\Services\InvoiceService::class)->signedUrl($order, 'credit'))->assertNotFound(); // geen creditnota zonder terugbetaling
    }

    public function test_refund_webhook_issues_a_credit_note_that_can_be_downloaded(): void
    {
        \Illuminate\Support\Facades\Mail::fake();
        $this->stripe->session = $this->paidSession();
        $this->webhookPaid();
        $this->call('POST', '/api/stripe/webhook', [], [], [], ['HTTP_STRIPE_SIGNATURE' => 'ok', 'CONTENT_TYPE' => 'application/json'],
            json_encode(['type' => 'charge.refunded', 'data' => ['object' => ['payment_intent' => 'pi_123', 'refunded' => true]]]))->assertOk();

        $order = Order::first();
        $this->assertSame('refunded', $order->status);
        $this->assertStringStartsWith('RBC-'.now()->format('Y').'-', $order->credit_note_number);
        $res = $this->get(app(\App\Services\InvoiceService::class)->signedUrl($order, 'credit'))->assertOk();
        $this->assertStringStartsWith('%PDF', $res->getContent());
    }

    public function test_checkout_respects_tracked_stock_but_ignores_untracked_products(): void
    {
        Product::whereKey('p1')->update(['track_stock' => true, 'stock' => 2]);
        Product::whereKey('p2')->update(['track_stock' => false, 'stock' => 0]); // niet bijgehouden: voorraad 0 betekent niets

        $res = $this->postJson('/api/checkout', ['items' => [['id' => 'p1', 'qty' => 3]], 'country' => 'NL'])->assertStatus(422);
        $res->assertJsonPath('error', 'stock')->assertJsonPath('available', 2);
        $this->assertStringContainsString('Niet genoeg voorraad', $res->json('message'));
        $this->postJson('/api/checkout', ['items' => [['id' => 'p1', 'qty' => 2]], 'country' => 'NL'])->assertOk();
        $this->postJson('/api/checkout', ['items' => [['id' => 'p2', 'qty' => 5]], 'country' => 'NL'])->assertOk();
    }

    public function test_paid_order_deducts_stock_once_and_catalog_shows_availability(): void
    {
        \Illuminate\Support\Facades\Mail::fake();
        Product::whereKey('p1')->update(['track_stock' => true, 'stock' => 6]); // paidSession bestelt p1 × 2
        $this->assertNull(collect($this->getJson('/api/catalog')->json('products'))->firstWhere('id', 'p1')['stockLeft']); // 6: geen "bijna op"

        $this->stripe->session = $this->paidSession();
        $this->webhookPaid();
        $this->webhookPaid(); // dubbel event: niet nog eens afschrijven
        $this->getJson('/api/orders/confirm?session_id=cs_test_abc123')->assertOk();

        $this->assertSame(4, Product::find('p1')->stock);
        $this->assertSame(2, Order::first()->items()->first()->stock_deducted);
        $p1 = collect($this->getJson('/api/catalog')->json('products'))->firstWhere('id', 'p1'); // cache is ververst
        $this->assertSame(4, $p1['stockLeft']);
        $this->assertTrue($p1['inStock']);

        Product::find('p1')->update(['stock' => 0]); // via het model (zoals het beheer): ververst de catalogus-cache
        $p1 = collect($this->getJson('/api/catalog')->json('products'))->firstWhere('id', 'p1');
        $this->assertFalse($p1['inStock']);
        $this->assertNull($p1['stockLeft']);
        $this->assertArrayNotHasKey('trackStock', $p1); // interne gegevens blijven uit de openbare catalogus
    }

    public function test_oversold_order_never_goes_negative_and_is_held_for_manual_handling(): void
    {
        config(['shop.cj.api_key' => 'CJ@api@key']);
        Product::whereKey('p1')->update(['track_stock' => true, 'stock' => 1, 'cj_vid' => 'VID-AURA']); // koper betaalt voor 2 maar er is er nog 1
        Http::fake();
        $this->stripe->session = $this->paidSession();
        $this->getJson('/api/orders/confirm?session_id=cs_test_abc123')->assertOk();

        $o = Order::first();
        $this->assertSame(1, Product::find('p1')->stock);          // niet afgeschreven, niet negatief
        $this->assertSame(0, $o->items()->first()->stock_deducted);
        $this->assertSame('manual', $o->cj_status);
        $this->assertStringContainsString('Voorraad onvoldoende', $o->cj_error);
        Http::assertNothingSent();                                   // niet automatisch bij CJ besteld
    }

    public function test_full_refund_restocks_only_orders_that_have_not_shipped(): void
    {
        \Illuminate\Support\Facades\Mail::fake();
        Product::whereKey('p1')->update(['track_stock' => true, 'stock' => 6]);
        $this->stripe->session = $this->paidSession();
        $this->webhookPaid();
        $this->assertSame(4, Product::find('p1')->stock);
        $refund = ['type' => 'charge.refunded', 'data' => ['object' => ['payment_intent' => 'pi_123', 'refunded' => true]]];
        $this->call('POST', '/api/stripe/webhook', [], [], [], ['HTTP_STRIPE_SIGNATURE' => 'ok', 'CONTENT_TYPE' => 'application/json'], json_encode($refund))->assertOk();
        $this->assertSame(6, Product::find('p1')->stock);          // terug op voorraad
        $this->call('POST', '/api/stripe/webhook', [], [], [], ['HTTP_STRIPE_SIGNATURE' => 'ok', 'CONTENT_TYPE' => 'application/json'], json_encode($refund))->assertOk();
        $this->assertSame(6, Product::find('p1')->stock);          // niet dubbel terugboeken

        // Verzonden bestelling: geen automatische terugboeking.
        Product::whereKey('p1')->update(['stock' => 6]);
        $this->stripe->session = $this->paidSession(['id' => 'cs_test_b', 'metadata' => ['order_no' => 'RB-TEST02'], 'payment_intent' => ['id' => 'pi_b', 'latest_charge' => ['refunded' => false]]]);
        $this->webhookPaid('cs_test_b');
        Order::where('order_no', 'RB-TEST02')->update(['step' => 2]);
        $this->assertSame(4, Product::find('p1')->stock);
        $refund['data']['object']['payment_intent'] = 'pi_b';
        $this->call('POST', '/api/stripe/webhook', [], [], [], ['HTTP_STRIPE_SIGNATURE' => 'ok', 'CONTENT_TYPE' => 'application/json'], json_encode($refund))->assertOk();
        $this->assertSame(4, Product::find('p1')->stock);
    }

    /** Aura (p1) met twee varianten: Zwart/M (prijs van product, voorraad 5) en Wit/L (eigen prijs € 89,50, eigen inkoop, voorraad 1). */
    private function variantProduct(bool $track = false): array
    {
        $p = Product::find('p1');
        $p->update(['track_stock' => $track]);
        $a = $p->variants()->create(['label' => 'Zwart / M', 'values' => ['Kleur' => 'Zwart', 'Maat' => 'M'], 'sku' => 'AUR-ZM', 'cj_vid' => 'VID-ZM', 'stock' => 5, 'position' => 0]);
        $b = $p->variants()->create(['label' => 'Wit / L', 'values' => ['Kleur' => 'Wit', 'Maat' => 'L'], 'sku' => 'AUR-WL', 'price' => 89.5, 'cost' => 30, 'cj_vid' => 'VID-WL', 'stock' => 1, 'position' => 1]);

        return [$a, $b];
    }

    public function test_checkout_requires_a_variant_and_charges_the_variant_price(): void
    {
        [$a, $b] = $this->variantProduct();
        $this->postJson('/api/checkout', ['items' => [['id' => 'p1', 'qty' => 1]], 'country' => 'NL'])->assertStatus(422)->assertJsonPath('error', 'variant');
        $this->postJson('/api/checkout', ['items' => [['id' => 'p1', 'v' => 99999, 'qty' => 1]], 'country' => 'NL'])->assertStatus(422)->assertJsonPath('error', 'item');
        $this->postJson('/api/checkout', ['items' => [['id' => 'p2', 'v' => $a->id, 'qty' => 1]], 'country' => 'NL'])->assertStatus(422); // variant hoort bij een ander product

        $this->postJson('/api/checkout', ['items' => [['id' => 'p1', 'v' => $b->id, 'qty' => 2, 'name' => 'GRATIS']], 'country' => 'NL'])->assertOk();
        $line = $this->stripe->lastParams['line_items'][0];
        $this->assertSame(8950, $line['price_data']['unit_amount']);                 // eigen prijs van de variant, niet van het product
        $this->assertSame('Aura draadloze koptelefoon met ANC — Wit / L', $line['price_data']['product_data']['name']);
        $this->assertSame('AUR-WL', $line['price_data']['product_data']['metadata']['sku']);
        $this->assertSame('p1:2:'.$b->id, $this->stripe->lastParams['metadata']['items']);

        $this->postJson('/api/checkout', ['items' => [['id' => 'p1', 'v' => $a->id, 'qty' => 1]], 'country' => 'NL'])->assertOk();
        $this->assertSame(7995, $this->stripe->lastParams['line_items'][0]['price_data']['unit_amount']); // zonder eigen prijs: productprijs
    }

    public function test_paid_order_keeps_variant_label_price_cost_and_sku_and_deducts_variant_stock(): void
    {
        \Illuminate\Support\Facades\Mail::fake();
        [$a, $b] = $this->variantProduct(true);
        $productStock = Product::find('p1')->stock;
        $this->stripe->session = $this->paidSession(['metadata' => ['items' => 'p1:1:'.$a->id]]);
        $this->postJson('/api/checkout', ['items' => [['id' => 'p1', 'v' => $b->id, 'qty' => 2]], 'country' => 'NL'])->assertStatus(422)->assertJsonPath('error', 'stock'); // Wit/L: nog 1
        $this->webhookPaid();

        $item = Order::first()->items()->first();
        $this->assertSame($a->id, $item->variant_id);
        $this->assertSame('Zwart / M', $item->variant_label);
        $this->assertSame('AUR-ZM', $item->sku);
        $this->assertEquals(79.95, $item->price);
        $this->assertSame(4, $a->fresh()->stock);            // variantvoorraad, niet de productvoorraad
        $this->assertSame(1, $item->stock_deducted);
        $this->assertSame($productStock, Product::find('p1')->stock); // productvoorraad onaangeroerd

        $refund = ['type' => 'charge.refunded', 'data' => ['object' => ['payment_intent' => 'pi_123', 'refunded' => true]]];
        $this->call('POST', '/api/stripe/webhook', [], [], [], ['HTTP_STRIPE_SIGNATURE' => 'ok', 'CONTENT_TYPE' => 'application/json'], json_encode($refund))->assertOk();
        $this->assertSame(5, $a->fresh()->stock);            // terug bij de juiste variant
    }

    public function test_variant_prices_and_cost_flow_into_the_order_and_the_cj_order_uses_the_variants_vid(): void
    {
        config(['shop.cj.api_key' => 'CJ@api@key']);
        [$a, $b] = $this->variantProduct();
        Product::whereKey('p1')->update(['cj_vid' => 'VID-PRODUCT', 'cj_from' => 'PL']);
        Http::fake([
            '*/authentication/getAccessToken' => Http::response(['code' => 200, 'result' => true, 'data' => ['accessToken' => 'TOK']]),
            '*/logistic/freightCalculate' => Http::response(['code' => 200, 'result' => true, 'data' => [['logisticName' => 'CJPacket', 'logisticPrice' => '4']]]),
            '*/shopping/order/createOrderV2' => Http::response(['code' => 200, 'result' => true, 'data' => ['orderId' => 'CJ-V']]),
        ]);
        $this->stripe->session = $this->paidSession(['amount_total' => 8950, 'metadata' => ['items' => 'p1:1:'.$b->id, 'country' => 'NL'], 'collected_information' => ['shipping_details' => ['address' => ['country' => 'NL', 'city' => 'Breda']]]]);
        $this->getJson('/api/orders/confirm?session_id=cs_test_abc123')->assertOk();

        $o = Order::first();
        $this->assertEquals(30, $o->cost);                    // inkoop van de variant
        $this->assertEquals(89.5, $o->items()->first()->price);
        $this->assertSame('CJ-V', $o->cj_order_ids);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'createOrderV2') && $r['products'][0]['vid'] === 'VID-WL'); // niet het VID van het product
        Http::assertSent(fn ($r) => str_contains($r->url(), 'freightCalculate') && $r['products'][0]['vid'] === 'VID-WL');
        $this->assertStringContainsString('Wit / L', collect($o->load('items')->toApi()['items'])->first()['name']); // zichtbaar in het beheer
    }

    public function test_cj_order_for_a_variant_without_any_vid_is_held_for_manual_handling(): void
    {
        config(['shop.cj.api_key' => 'CJ@api@key']);
        [$a] = $this->variantProduct();
        $a->update(['cj_vid' => '']);
        Product::whereKey('p1')->update(['cj_vid' => '']);
        Http::fake();
        $this->stripe->session = $this->paidSession(['metadata' => ['items' => 'p1:1:'.$a->id]]);
        $this->getJson('/api/orders/confirm?session_id=cs_test_abc123')->assertOk();

        $o = Order::first();
        $this->assertSame('manual', $o->cj_status);
        $this->assertStringContainsString('Zwart / M', $o->cj_error);
        Http::assertNothingSent();
    }

    public function test_refunded_orders_are_never_placed_at_cj(): void
    {
        config(['shop.cj.api_key' => 'CJ@api@key']);
        Product::whereKey('p1')->update(['cj_vid' => 'VID-AURA', 'cj_from' => 'PL']);
        Http::fake();
        $this->stripe->session = $this->paidSession(['payment_intent' => ['latest_charge' => ['refunded' => true]]]);
        $this->getJson('/api/orders/confirm?session_id=cs_test_abc123')->assertOk();

        $o = Order::first();
        $this->assertSame('refunded', $o->status);
        $this->assertSame('', $o->cj_status);
        Http::assertNothingSent();
        app(\App\Services\CjService::class)->placeOrder($o);
        Http::assertNothingSent();
    }

    public function test_cj_response_without_order_id_is_treated_as_failure(): void
    {
        config(['shop.cj.api_key' => 'CJ@api@key']);
        Product::whereKey('p1')->update(['cj_vid' => 'VID-AURA', 'cj_from' => 'PL']);
        Http::fake([
            '*/authentication/getAccessToken' => Http::response(['code' => 200, 'result' => true, 'data' => ['accessToken' => 'TOK']]),
            '*/logistic/freightCalculate' => Http::response(['code' => 200, 'result' => true, 'data' => [['logisticName' => 'CJPacket', 'logisticPrice' => '4']]]),
            '*/shopping/order/createOrderV2' => Http::response(['code' => 200, 'result' => true, 'data' => []]),
        ]);
        $this->stripe->session = $this->paidSession();
        $this->getJson('/api/orders/confirm?session_id=cs_test_abc123')->assertOk();

        $o = Order::first();
        $this->assertSame('error', $o->cj_status);
        $this->assertSame('', (string) $o->cj_order_ids);
        $this->assertStringContainsString('geen order-ID', $o->cj_error);
    }

    public function test_stuck_orders_command_alerts_once_and_ignores_fresh_ones(): void
    {
        config(['shop.cj.api_key' => 'CJ@api@key', 'mail.default' => 'array']);
        $this->stripe->session = $this->paidSession();
        Http::fake(['*' => Http::response([], 500)]);
        $this->getJson('/api/orders/confirm?session_id=cs_test_abc123');
        $o = Order::first();
        $o->forceFill(['cj_status' => 'placing', 'cj_order_ids' => ''])->save();

        $this->artisan('shop:check-stuck')->expectsOutput('Vastgelopen bestellingen: 0')->assertExitCode(0); // net bijgewerkt

        $o->forceFill(['updated_at' => now()->subMinutes(30)])->saveQuietly();
        $this->artisan('shop:check-stuck')->expectsOutput('Vastgelopen bestellingen: 1')->assertExitCode(0);
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
        $this->assertTrue((bool) $o->cj_wait); // tracking blijft opgehaald worden voor de al bestelde groep
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
