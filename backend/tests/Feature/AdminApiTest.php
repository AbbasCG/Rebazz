<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Services\StripeGateway;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Test het beheerdashboard-API: inloggen, bestellingen, producten, btw en instellingen. */
class AdminApiTest extends TestCase
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

    private function token(): string
    {
        return $this->postJson('/api/admin/login', ['password' => 'test-wachtwoord-123'])->assertOk()->json('token');
    }

    private function auth(): array
    {
        return ['Authorization' => 'Bearer '.$this->token()];
    }

    private function makeOrder(string $no, string $country, float $total, string $date): Order
    {
        $o = Order::create(['order_no' => $no, 'stripe_session_id' => 'cs_'.$no, 'payment_intent_id' => 'pi_'.$no, 'status' => 'paid', 'country' => $country,
            'total' => $total, 'subtotal' => $total, 'email' => 'k@example.com', 'customer_name' => 'Klant', 'paid_at' => $date]);
        $o->items()->create(['product_id' => 'p1', 'sku' => 'RB-AUD-001', 'name' => 'Aura', 'price' => $total, 'qty' => 1]);

        return $o;
    }

    public function test_login_requires_correct_password_and_protects_routes(): void
    {
        $this->postJson('/api/admin/login', ['password' => 'fout-wachtwoord'])->assertStatus(401);
        $this->getJson('/api/admin/orders')->assertStatus(401);
        $this->getJson('/api/admin/status', $this->auth())->assertOk()->assertJson(['stripe' => 'test', 'cj' => false]);
    }

    public function test_product_crud_is_saved_in_database_and_visible_in_shop(): void
    {
        $h = $this->auth();
        $new = ['id' => 'pnew', 'sku' => 'RB-NEW-1', 'name' => 'Testlamp', 'category' => 'Wonen', 'price' => 39.95, 'compare' => null, 'cost' => 9.5,
            'image' => 'https://cdn.example.com/lamp.jpg', 'days' => [3, 6], 'status' => 'active', 'features' => ['Dimbaar'], 'cjVid' => 'VID-1', 'cjFrom' => 'pl'];
        $this->postJson('/api/admin/products', $new, $h)->assertCreated()->assertJsonPath('product.cjFrom', 'PL');
        $this->getJson('/api/catalog')->assertJsonFragment(['name' => 'Testlamp']);

        $this->putJson('/api/admin/products/pnew', ['price' => 44.95, 'status' => 'draft'] + $new, $h)->assertOk()->assertJsonPath('product.price', 44.95);
        $this->assertSame(0, collect($this->getJson('/api/catalog')->json('products'))->where('id', 'pnew')->count());

        $this->postJson('/api/admin/products', ['compare' => 10] + $new + ['id' => 'pbad', 'sku' => 'RB-BAD'], $h)->assertStatus(422); // van-prijs lager dan prijs
        $this->deleteJson('/api/admin/products/pnew', [], $h)->assertOk();
        $this->assertNull(Product::find('pnew'));
    }

    public function test_order_update_and_refund(): void
    {
        $h = $this->auth();
        $this->makeOrder('RB-A1', 'NL', 79.95, now()->toDateTimeString());
        $this->getJson('/api/admin/orders', $h)->assertOk()->assertJsonPath('orders.0.id', 'RB-A1');
        $this->patchJson('/api/admin/orders/RB-A1', ['step' => 2, 'carrier' => 'PostNL', 'tracking' => '3S<script>123'], $h)
            ->assertOk()->assertJsonPath('order.tracking', '3Sscript123')->assertJsonPath('order.step', 2);
        $this->postJson('/api/admin/orders/RB-A1/refund', [], $h)->assertOk()->assertJsonPath('order.refunded', true);
        $this->assertSame(['pi_RB-A1'], $this->stripe->refunds);
        $this->postJson('/api/admin/orders/RB-A1/refund', [], $h)->assertStatus(409); // niet dubbel terugbetalen
    }

    public function test_tax_summary_per_country_and_quarter(): void
    {
        $h = $this->auth();
        $this->makeOrder('RB-NL', 'NL', 121.00, '2026-02-10 10:00:00');
        $this->makeOrder('RB-DE', 'DE', 119.00, '2026-03-05 10:00:00');
        $this->makeOrder('RB-US', 'US', 100.00, '2026-03-06 10:00:00');
        $this->makeOrder('RB-Q2', 'NL', 50.00, '2026-04-02 10:00:00');
        $this->putJson('/api/admin/settings/tax.input.2026-1', ['value' => 5], $h)->assertOk();

        $r = $this->getJson('/api/admin/tax?year=2026&quarter=1', $h)->assertOk();
        $this->assertSame(3, $r->json('totals.orders'));
        $this->assertEquals(21.0, $r->json('nl.vat'));
        $this->assertEquals(16.0, $r->json('nl.pay')); // 21 - 5 voorbelasting
        $this->assertEquals(19.0, $r->json('oss.vat'));
        $this->assertEquals(100.0, $r->json('export.incl'));
        $this->assertEquals(100.0, $r->json('crossBorderYear'));
        $this->assertSame('2026-04-30', $r->json('deadlines.0.date'));

        $this->putJson('/api/admin/settings/tax.oss', ['value' => false], $h)->assertOk();
        $r = $this->getJson('/api/admin/tax?year=2026&quarter=1', $h);
        $this->assertEquals(0.0, $r->json('oss.vat')); // zonder OSS: Duitse verkoop tegen NL-tarief
    }

    public function test_tax_summary_prefers_actual_stripe_tax_over_estimate(): void
    {
        $h = $this->auth();
        $this->makeOrder('RB-DE2', 'DE', 119.00, '2026-03-05 10:00:00');
        // Stripe Tax rekende werkelijk € 10,00 btw (bijv. ander tarief dan de vaste tabel): dat moet leidend zijn.
        \App\Models\Order::where('order_no', 'RB-DE2')->update(['tax_amount' => 10.00]);

        $r = $this->getJson('/api/admin/tax?year=2026&quarter=1', $h)->assertOk();
        $this->assertEquals(10.0, $r->json('oss.vat'));
        $this->assertEquals(109.0, $r->json('totals.excl'));
        $this->assertEquals(9.17, $r->json('lines.0.rate'));
    }

    public function test_settings_values_are_validated_per_key(): void
    {
        $h = $this->auth();
        $this->putJson('/api/admin/settings/tax.oss', ['value' => 'ja-hoor'], $h)->assertStatus(422);
        $this->putJson('/api/admin/settings/tax.input.2026-1', ['value' => -5], $h)->assertStatus(422);
        $this->putJson('/api/admin/settings/campaigns', ['value' => 'geen-array'], $h)->assertStatus(422);
        $this->putJson('/api/admin/settings/tax.input.2026-1', ['value' => 0], $h)->assertOk();
    }

    public function test_gpsr_fields_are_public_and_required_for_active_products_when_enforced(): void
    {
        $h = $this->auth();
        $p = $this->getJson('/api/admin/products', $h)->json('products.0');
        $p['status'] = 'active';
        $p['manufacturer'] = '';
        $p['euResponsible'] = '';

        $this->putJson('/api/admin/products/'.$p['id'], $p, $h)->assertOk(); // niet afgedwongen: toegestaan

        config(['shop.require_gpsr' => true]);
        $this->putJson('/api/admin/products/'.$p['id'], $p, $h)->assertStatus(422)->assertJsonValidationErrors(['manufacturer', 'euResponsible']);

        $p['manufacturer'] = 'Acme Ltd, 1 Road, Shenzhen, CN <script>';
        $p['euResponsible'] = 'Acme EU BV, Straat 1, 4811 AA Breda, NL, eu@acme.example';
        $p['safetyInfo'] = 'Niet geschikt voor kinderen onder 3 jaar.';
        $this->putJson('/api/admin/products/'.$p['id'], $p, $h)->assertOk();

        $pub = collect($this->getJson('/api/catalog')->json('products'))->firstWhere('id', $p['id']);
        $this->assertSame('Acme EU BV, Straat 1, 4811 AA Breda, NL, eu@acme.example', $pub['euResponsible']);
        $this->assertStringNotContainsString('<script>', $pub['manufacturer']);
        $this->assertSame('Niet geschikt voor kinderen onder 3 jaar.', $pub['safetyInfo']);
    }

    public function test_cached_catalog_is_refreshed_immediately_after_a_product_change(): void
    {
        $h = $this->auth();
        $before = collect($this->getJson('/api/catalog')->json('products'))->first();
        $p = $this->getJson('/api/admin/products', $h)->json('products.0');
        $this->assertSame($p['id'], $before['id']);
        $p['price'] = 77.77;
        $p['compare'] = null;
        $this->putJson('/api/admin/products/'.$p['id'], $p, $h)->assertOk();

        $after = collect($this->getJson('/api/catalog')->json('products'))->firstWhere('id', $p['id']);
        $this->assertEquals(77.77, $after['price']); // geen verouderde prijs uit de cache
    }

    public function test_admin_cannot_reorder_while_placing_and_brand_email_is_validated(): void
    {
        $h = $this->auth();
        $o = $this->makeOrder('RB-PL', 'NL', 100.0, '2026-03-05 10:00:00');
        $o->forceFill(['cj_status' => 'placing'])->save();
        $this->postJson('/api/admin/orders/RB-PL/cj-order', [], $h)->assertStatus(409);

        $this->putJson('/api/admin/settings/brand', ['value' => ['email' => 'geen-adres']], $h)->assertStatus(422);
        $this->putJson('/api/admin/settings/brand', ['value' => ['email' => 'info@rebazz.nl', 'name' => ['x']]], $h)->assertOk();
    }

    public function test_admin_can_download_invoice_and_credit_note_and_old_orders_get_a_number_lazily(): void
    {
        $h = $this->auth();
        $o = $this->makeOrder('RB-OLD', 'DE', 119.00, '2026-03-05 10:00:00');
        $this->assertNull($o->invoice_number);

        $this->getJson('/api/admin/orders/RB-OLD/invoice')->assertUnauthorized();
        $res = $this->get('/api/admin/orders/RB-OLD/invoice', $h)->assertOk();
        $this->assertStringStartsWith('%PDF', $res->getContent());
        $this->assertStringStartsWith('RBF-2026-', $o->fresh()->invoice_number);
        $first = $o->fresh()->invoice_number;
        $this->get('/api/admin/orders/RB-OLD/invoice', $h)->assertOk();
        $this->assertSame($first, $o->fresh()->invoice_number); // nummer verandert niet

        $this->get('/api/admin/orders/RB-OLD/credit-note', $h)->assertNotFound(); // nog niet terugbetaald
        $o->update(['payment_intent_id' => 'pi_RB-OLD']);
        $this->postJson('/api/admin/orders/RB-OLD/refund', [], $h)->assertOk();
        $this->assertStringStartsWith('RBC-', $o->fresh()->credit_note_number);
        $this->assertStringStartsWith('%PDF', $this->get('/api/admin/orders/RB-OLD/credit-note', $h)->assertOk()->getContent());
    }

    private function colorProduct(array $h, array $extra = []): array
    {
        $p = $this->getJson('/api/admin/products', $h)->json('products.0');
        $p = array_merge($p, [
            'options' => [['name' => 'Kleur', 'values' => ['Zwart', 'Wit']], ['name' => 'Maat', 'values' => ['M', 'L']]],
            'images' => ['https://img.example/a.jpg', '/media/products/'.str_repeat('a', 32).'.jpg'],
            'variants' => [
                ['label' => 'Zwart / M', 'values' => ['Kleur' => 'Zwart', 'Maat' => 'M'], 'sku' => 'AUR-ZM', 'ownPrice' => null, 'cost' => null, 'cjVid' => 'VID-ZM', 'stock' => 5],
                ['label' => 'Wit / L', 'values' => ['Kleur' => 'Wit', 'Maat' => 'L'], 'sku' => 'AUR-WL', 'ownPrice' => 89.5, 'cost' => 30, 'cjVid' => 'VID-WL', 'stock' => 0],
            ],
        ], $extra);

        return $p;
    }

    public function test_variants_and_gallery_are_saved_synced_and_only_safe_fields_are_public(): void
    {
        $h = $this->auth();
        $p = $this->colorProduct($h);
        $saved = $this->putJson('/api/admin/products/'.$p['id'], $p, $h)->assertOk()->json('product');
        $this->assertCount(2, $saved['variants']);
        $this->assertEquals(30, $saved['variants'][1]['cost']);   // beheer ziet inkoop en CJ
        $this->assertSame('VID-WL', $saved['variants'][1]['cjVid']);
        $this->assertNull($saved['variants'][0]['ownPrice']);
        $this->assertEquals($saved['price'], $saved['variants'][0]['price']); // zonder eigen prijs: prijs van het product
        $this->assertEquals(89.5, $saved['variants'][1]['price']);

        $pub = collect($this->getJson('/api/catalog')->json('products'))->firstWhere('id', $p['id']);
        $this->assertCount(2, $pub['variants']);
        $this->assertArrayNotHasKey('cost', $pub['variants'][1]);
        $this->assertArrayNotHasKey('cjVid', $pub['variants'][1]);
        $this->assertArrayNotHasKey('stock', $pub['variants'][1]);
        $this->assertSame(['Kleur', 'Maat'], array_column($pub['options'], 'name'));
        $this->assertCount(2, $pub['images']);

        // Synchroniseren: eerste variant behouden (id), tweede weg, nieuwe erbij.
        $keepId = $saved['variants'][0]['id'];
        $p['variants'] = [
            ['id' => $keepId, 'label' => 'Zwart / M (nieuw)', 'values' => ['Kleur' => 'Zwart', 'Maat' => 'M'], 'stock' => 9],
            ['label' => 'Wit / M', 'values' => ['Kleur' => 'Wit', 'Maat' => 'M'], 'stock' => 1],
        ];
        $again = $this->putJson('/api/admin/products/'.$p['id'], $p, $h)->assertOk()->json('product');
        $this->assertCount(2, $again['variants']);
        $this->assertSame($keepId, $again['variants'][0]['id']);
        $this->assertSame('Zwart / M (nieuw)', $again['variants'][0]['label']);
        $this->assertSame(0, \App\Models\ProductVariant::where('label', 'Wit / L')->count());

        $p['variants'] = [];
        $this->putJson('/api/admin/products/'.$p['id'], $p, $h)->assertOk();
        $this->assertSame(0, \App\Models\ProductVariant::where('product_id', $p['id'])->count());
    }

    public function test_variant_and_gallery_input_is_validated(): void
    {
        $h = $this->auth();
        $p = $this->colorProduct($h);
        $bad = $p;
        $bad['images'] = ['javascript:alert(1)'];
        $this->putJson('/api/admin/products/'.$p['id'], $bad, $h)->assertStatus(422)->assertJsonValidationErrors(['images.0']);
        $bad = $p;
        $bad['variants'][0]['label'] = '';
        $this->putJson('/api/admin/products/'.$p['id'], $bad, $h)->assertStatus(422)->assertJsonValidationErrors(['variants.0.label']);
        $bad = $p;
        $bad['variants'][0]['stock'] = -1;
        $this->putJson('/api/admin/products/'.$p['id'], $bad, $h)->assertStatus(422);
        $bad = $p;
        $bad['variants'][0]['label'] = '<b>Zwart</b>';
        $ok = $this->putJson('/api/admin/products/'.$p['id'], $bad, $h)->assertOk()->json('product');
        $this->assertSame('Zwart', $ok['variants'][0]['label']); // HTML wordt weggehaald
    }

    public function test_image_upload_accepts_real_images_only_and_serves_them_publicly(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');
        $h = $this->auth();
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
        $file = \Illuminate\Http\UploadedFile::fake()->createWithContent('foto.png', $png);

        $this->postJson('/api/admin/uploads', ['image' => $file])->assertUnauthorized(); // zonder login geen uploads
        $res = $this->post('/api/admin/uploads', ['image' => $file], $h + ['Accept' => 'application/json'])->assertCreated();
        $url = $res->json('url');
        $this->assertMatchesRegularExpression('#^/media/products/[a-z0-9]{32}\.png$#', $url);
        \Illuminate\Support\Facades\Storage::disk('public')->assertExists('products/'.basename($url));

        $img = $this->get($url)->assertOk();
        $this->assertStringContainsString('immutable', $img->headers->get('Cache-Control'));
        $this->assertSame('nosniff', $img->headers->get('X-Content-Type-Options'));

        // Een PHP-script met .png-naam en een SVG worden geweigerd; onbekende of vreemde bestandsnamen geven 404.
        $fake = \Illuminate\Http\UploadedFile::fake()->createWithContent('x.png', '<?php echo "boem"; ?>');
        $this->post('/api/admin/uploads', ['image' => $fake], $h + ['Accept' => 'application/json'])->assertStatus(422);
        $svg = \Illuminate\Http\UploadedFile::fake()->createWithContent('x.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
        $this->post('/api/admin/uploads', ['image' => $svg], $h + ['Accept' => 'application/json'])->assertStatus(422);
        $this->get('/media/products/'.str_repeat('b', 32).'.png')->assertNotFound();
        $this->get('/media/products/..%2F..%2F.env')->assertNotFound();
    }

    public function test_negative_stock_is_rejected(): void
    {
        $h = $this->auth();
        $p = $this->getJson('/api/admin/products', $h)->json('products.0');
        $p['stock'] = -3;
        $this->putJson('/api/admin/products/'.$p['id'], $p, $h)->assertStatus(422);
    }

    public function test_settings_only_allow_known_keys_and_brand_fields(): void
    {
        $h = $this->auth();
        $this->putJson('/api/admin/settings/hack', ['value' => 1], $h)->assertNotFound();
        $this->putJson('/api/admin/settings/brand', ['value' => ['email' => 'info@rebazz.nl', 'evil' => 'x']], $h)->assertOk();
        $this->getJson('/api/catalog')->assertJsonPath('brand.email', 'info@rebazz.nl')->assertJsonMissingPath('brand.evil');
    }
}
