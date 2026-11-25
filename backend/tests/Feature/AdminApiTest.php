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
