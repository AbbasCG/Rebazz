<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Services\AnalyticsService;
use App\Services\PrivacyService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Eigen statistieken: alleen anonieme gebeurtenissen, geen IP/user-agent, vergeten op verzoek, rapport voor het beheer. */
class AnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private const VID = 'aaaaaaaa-1111-2222-3333-bbbbbbbbbbbb';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function admin(): array
    {
        return ['Authorization' => 'Bearer '.$this->postJson('/api/admin/login', ['password' => 'test-wachtwoord-123'])->assertOk()->json('token')];
    }

    private function ev(array $over = [], array $headers = [])
    {
        return $this->postJson('/api/analytics', $over + ['vid' => self::VID, 'sid' => 'sess-0001', 'type' => 'page', 'path' => '/'], $headers);
    }

    public function test_event_is_stored_without_ip_or_user_agent_and_with_cleaned_fields(): void
    {
        $this->ev(['path' => '/product/p1/aura?utm_source=x&email=a@b.nl#top', 'ref' => 'https://www.Google.com/search?q=secret', 'src' => ' Newsletter ', 'med' => 'EMAIL', 'camp' => 'Zomer<b>', 'device' => 'mobile'],
            ['User-Agent' => 'Mozilla/5.0 geheim'])->assertNoContent();
        $row = (array) DB::table('analytics_events')->first();
        $this->assertSame('/product/p1/aura', $row['path']);                       // query en hash weg
        $this->assertSame('google.com', $row['ref']);                              // alleen de host
        $this->assertSame('newsletter', $row['src']);
        $this->assertSame('email', $row['med']);
        $this->assertSame('zomer', $row['camp']);                                  // html-tags verwijderd
        $this->assertSame(['id', 'vid', 'sid', 'type', 'path', 'product_id', 'ref', 'src', 'med', 'camp', 'device', 'value', 'created_at'], array_keys($row)); // geen ip/ua-kolom
        $this->assertStringNotContainsString('geheim', json_encode($row));
        $this->assertStringNotContainsString('secret', json_encode($row));
    }

    public function test_admin_pages_own_referrer_and_invalid_input_are_not_stored(): void
    {
        $this->ev(['path' => '/beheer/bestellingen'])->assertNoContent();
        $this->ev(['ref' => config('shop.frontend_url')])->assertNoContent();
        $this->assertNull(DB::table('analytics_events')->whereNotNull('ref')->first());         // eigen site telt niet als bron
        $this->assertSame(1, DB::table('analytics_events')->count());
        $this->ev(['type' => 'hack'])->assertStatus(422);
        $this->ev(['vid' => 'kort'])->assertStatus(422);
        $this->ev(['product_id' => "p1'; drop"])->assertStatus(422);
        $this->ev(['device' => 'tv'])->assertStatus(422);
        $this->ev(['value' => -5, 'type' => 'purchase'])->assertStatus(422);
        $this->assertSame(1, DB::table('analytics_events')->count());
    }

    public function test_do_not_track_and_global_privacy_control_are_respected(): void
    {
        $this->ev([], ['Sec-GPC' => '1'])->assertNoContent();
        $this->ev([], ['DNT' => '1'])->assertNoContent();
        $this->assertSame(0, DB::table('analytics_events')->count());
    }

    public function test_value_is_only_kept_for_purchases(): void
    {
        $this->ev(['type' => 'page', 'value' => 50])->assertNoContent();
        $this->ev(['type' => 'purchase', 'value' => 159.9])->assertNoContent();
        $this->assertSame(['page' => null, 'purchase' => '159.90'], DB::table('analytics_events')->pluck('value', 'type')->all());
    }

    public function test_visitor_can_have_everything_forgotten_when_consent_is_withdrawn(): void
    {
        $this->ev()->assertNoContent();
        $this->ev(['vid' => 'zzzzzzzz-1111-2222-3333-yyyyyyyyyyyy', 'sid' => 'sess-0002'])->assertNoContent();
        $this->postJson('/api/analytics/forget', ['vid' => self::VID])->assertNoContent();
        $this->assertSame(['zzzzzzzz-1111-2222-3333-yyyyyyyyyyyy'], DB::table('analytics_events')->pluck('vid')->all());
        $this->postJson('/api/analytics/forget', ['vid' => 'x'])->assertStatus(422);
    }

    public function test_events_are_pruned_after_the_retention_period_by_the_daily_job(): void
    {
        $this->ev()->assertNoContent();
        $this->ev(['sid' => 'sess-0003'])->assertNoContent();
        DB::table('analytics_events')->where('sid', 'sess-0003')->update(['created_at' => now()->subMonths(14)]);
        $this->app->make(PrivacyService::class)->runRetention();
        $this->assertSame(1, DB::table('analytics_events')->count());
        $this->assertSame(13, config('shop.analytics_months'));
    }

    public function test_collection_is_rate_limited(): void
    {
        for ($i = 0; $i < 240; $i++) {
            $this->ev(['sid' => 's'.str_pad((string) $i, 7, '0', STR_PAD_LEFT)])->assertNoContent();
        }
        $this->ev()->assertStatus(429);
    }

    public function test_report_requires_admin_and_summarises_funnel_sources_products_and_devices(): void
    {
        $this->getJson('/api/admin/analytics')->assertUnauthorized();

        // Bezoeker A: via nieuwsbrief-campagne, bekijkt product, legt in winkelwagen, koopt.
        $a = ['vid' => 'visitor-a-1111-2222-3333-aaaa', 'sid' => 'sess-aaaa1', 'src' => 'newsletter', 'med' => 'email', 'camp' => 'zomer', 'device' => 'mobile'];
        $this->ev($a + ['type' => 'page', 'path' => '/'])->assertNoContent();
        $this->ev($a + ['type' => 'product', 'path' => '/product/p1/x', 'product_id' => 'p1'])->assertNoContent();
        $this->ev($a + ['type' => 'cart', 'path' => '/product/p1/x', 'product_id' => 'p1'])->assertNoContent();
        $this->ev($a + ['type' => 'checkout', 'path' => '/checkout'])->assertNoContent();
        $this->ev($a + ['type' => 'purchase', 'path' => '/bedankt', 'value' => 100])->assertNoContent();
        // Bezoeker B: via Google, bekijkt alleen een product.
        $b = ['vid' => 'visitor-b-1111-2222-3333-bbbb', 'sid' => 'sess-bbbb1', 'ref' => 'https://google.com/', 'device' => 'desktop'];
        $this->ev($b + ['type' => 'page', 'path' => '/'])->assertNoContent();
        $this->ev($b + ['type' => 'product', 'path' => '/product/p1/x', 'product_id' => 'p1'])->assertNoContent();
        // Bezoeker C: direct.
        $this->ev(['vid' => 'visitor-c-1111-2222-3333-cccc', 'sid' => 'sess-cccc1', 'type' => 'page', 'path' => '/'])->assertNoContent();
        // Een oude gebeurtenis valt buiten de periode.
        $this->ev(['vid' => 'visitor-old-1111-2222-3333-dd', 'sid' => 'sess-old01'])->assertNoContent();
        DB::table('analytics_events')->where('sid', 'sess-old01')->update(['created_at' => now()->subDays(40)]);
        Order::create(['order_no' => 'RB-AN1', 'stripe_session_id' => 'cs_an1', 'payment_intent_id' => 'pi_an1', 'status' => 'paid', 'customer_name' => 'A', 'email' => 'a@example.nl', 'phone' => '',
            'country' => 'NL', 'address' => [], 'lang' => 'nl', 'subtotal' => 100, 'total' => 100, 'cost' => 40, 'paid_at' => now()]);

        $r = $this->getJson('/api/admin/analytics?days=30', $this->admin())->assertOk()->json();
        $this->assertSame(['visitors' => 3, 'sessions' => 3, 'pageviews' => 3], $r['totals']);
        $this->assertSame(['visitors' => 3, 'product' => 2, 'cart' => 1, 'checkout' => 1, 'purchase' => 1], $r['funnel']);
        $this->assertSame(['purchases' => 1, 'revenue' => 100], $r['tracked']);
        $this->assertSame(['count' => 1, 'revenue' => 100], $r['orders']);
        $this->assertCount(30, $r['series']);
        $this->assertSame(3, end($r['series'])['visitors']);
        $this->assertSame('p1', $r['products'][0]['id']);
        $this->assertSame(['views' => 2, 'carts' => 1], ['views' => $r['products'][0]['views'], 'carts' => $r['products'][0]['carts']]);
        $this->assertNotSame('p1', $r['products'][0]['name']);                                // naam uit de catalogus
        $this->assertEqualsCanonicalizing(['newsletter', 'google.com', '(direct)'], array_column($r['sources'], 'source'));
        $news = collect($r['sources'])->firstWhere('source', 'newsletter');
        $this->assertSame([1, 1, 100.0], [$news['sessions'], $news['purchases'], (float) $news['revenue']]);
        $this->assertSame('zomer', $r['campaigns'][0]['campaign']);
        $this->assertEqualsCanonicalizing(['mobile', 'desktop', 'unknown'], array_column($r['devices'], 'device'));
        $this->assertSame('/', $r['pages'][0]['path']);
        $this->assertSame(3, $r['pages'][0]['views']);

        // Andere perioden en ongeldige waarden vallen terug op 30 dagen.
        $this->assertCount(7, $this->getJson('/api/admin/analytics?days=7', $this->admin())->json('series'));
        $this->assertCount(30, $this->getJson('/api/admin/analytics?days=9999', $this->admin())->json('series'));
        $this->assertSame(4, $this->getJson('/api/admin/analytics?days=90', $this->admin())->json('totals.visitors'));
    }

    public function test_service_ignores_non_https_garbage_referrers(): void
    {
        $s = $this->app->make(AnalyticsService::class);
        $s->record(['vid' => self::VID, 'sid' => 'sess-9999', 'type' => 'page', 'path' => '/', 'ref' => 'javascript:alert(1)']);
        $s->record(['vid' => self::VID, 'sid' => 'sess-9998', 'type' => 'page', 'path' => '/', 'ref' => 'x y z']);
        $this->assertSame(0, DB::table('analytics_events')->whereNotNull('ref')->count());
    }
}
