<?php

namespace Tests\Feature;

use App\Mail\PrivacyRequestMail;
use App\Models\NewsletterSubscriber;
use App\Models\Order;
use App\Models\OrderReturn;
use App\Models\PrivacyRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/** AVG: inzage/export, wissen met wettelijke bewaartermijn, bewaartermijnen en afmelden. */
class PrivacyTest extends TestCase
{
    use RefreshDatabase;

    private function order(string $no, string $email, ?string $paidAt = null, array $extra = []): Order
    {
        $o = Order::create($extra + [
            'order_no' => $no, 'stripe_session_id' => 'cs_'.$no, 'payment_intent_id' => 'pi_'.$no, 'status' => 'paid', 'customer_name' => 'Anna Becker',
            'email' => $email, 'phone' => '+4930123456', 'country' => 'DE', 'address' => ['line1' => 'Hauptstr. 1', 'city' => 'Berlin'], 'lang' => 'de',
            'subtotal' => 100, 'total' => 100, 'cost' => 40, 'paid_at' => $paidAt ?? now()->subYear(), 'invoice_issued_at' => $paidAt ?? now()->subYear(),
        ]);
        $o->items()->create(['product_id' => 'p1', 'sku' => 'RB-1', 'name' => 'Aura', 'price' => 100, 'cost' => 40, 'qty' => 1]);

        return $o;
    }

    private function sentUrl(string $type): string
    {
        $url = null;
        Mail::assertSent(PrivacyRequestMail::class, function ($m) use ($type, &$url) {
            if ($m->type === $type) {
                $url = $m->url;
            }

            return $m->type === $type;
        });

        return (string) $url;
    }

    public function test_request_answers_identically_for_known_and_unknown_addresses_and_mails_only_known_ones(): void
    {
        Mail::fake();
        $this->order('RB-P1', 'anna@example.de');

        $known = $this->postJson('/api/privacy/request', ['email' => 'Anna@Example.de', 'type' => 'export'])->assertOk()->json();
        $unknown = $this->postJson('/api/privacy/request', ['email' => 'niemand@example.de', 'type' => 'export'])->assertOk()->json();
        $this->assertSame($known, $unknown);                                    // niet te achterhalen of een adres bij ons bekend is
        Mail::assertSent(PrivacyRequestMail::class, 1);
        Mail::assertSent(PrivacyRequestMail::class, fn ($m) => $m->hasTo('anna@example.de') && $m->lang === 'de');

        $this->postJson('/api/privacy/request', ['email' => 'anna@example.de', 'type' => 'nonsens'])->assertStatus(422);
        $this->postJson('/api/privacy/request', ['email' => 'geen-mail', 'type' => 'export'])->assertStatus(422);

        // Per adres maximaal 3 per uur: daarna geen extra mails meer (zelfde antwoord).
        $this->postJson('/api/privacy/request', ['email' => 'anna@example.de', 'type' => 'export'])->assertOk();
        $this->postJson('/api/privacy/request', ['email' => 'anna@example.de', 'type' => 'export'])->assertOk();
        $this->postJson('/api/privacy/request', ['email' => 'anna@example.de', 'type' => 'export'])->assertOk();
        Mail::assertSent(PrivacyRequestMail::class, 3);
    }

    public function test_export_link_returns_only_that_persons_data_without_internal_fields_and_expires(): void
    {
        Mail::fake();
        $mine = $this->order('RB-MINE', 'anna@example.de');
        $this->order('RB-OTHER', 'ander@example.de');
        NewsletterSubscriber::create(['email' => 'anna@example.de', 'lang' => 'de', 'confirmed_at' => now()]);
        OrderReturn::create(['rma_number' => 'RMA-X-1', 'order_id' => $mine->id, 'status' => 'requested', 'reason' => 'other', 'comment' => 'Past niet'])
            ->items()->create(['order_item_id' => $mine->items()->first()->id, 'qty' => 1]);
        $this->postJson('/api/privacy/request', ['email' => 'anna@example.de', 'type' => 'export'])->assertOk();
        $url = $this->sentUrl('export');

        $res = $this->get($url)->assertOk();
        $this->assertStringContainsString('attachment', $res->headers->get('Content-Disposition'));
        $json = $res->json();
        $this->assertSame(['RB-MINE'], array_column($json['orders'], 'order_number'));          // alleen eigen bestellingen
        $this->assertSame('Anna Becker', $json['orders'][0]['name']);
        $this->assertSame('Past niet', $json['orders'][0]['returns'][0]['comment']);
        $this->assertSame('anna@example.de', $json['newsletter']['email']);
        $this->assertNotEmpty($json['orders'][0]['retained_until']);
        $raw = $res->getContent();
        foreach (['"cost"', 'cj_', 'stripe_session', 'payment_intent', 'margin'] as $internal) {
            $this->assertStringNotContainsString($internal, $raw);                              // geen interne gegevens zoals inkoop
        }

        $this->get($url.'x')->assertForbidden();                                                  // gemanipuleerde handtekening
        $this->get(str_replace('anna%40example.de', 'ander%40example.de', $url))->assertForbidden(); // ander adres, zelfde handtekening
        $this->travel(2)->hours();
        $this->get($url)->assertForbidden();                                                      // na 1 uur verlopen
    }

    public function test_erase_keeps_what_the_law_requires_minimizes_the_rest_and_anonymizes_old_orders(): void
    {
        Mail::fake();
        $recent = $this->order('RB-RECENT', 'anna@example.de', now()->subYear()->toDateTimeString());
        $old = $this->order('RB-OLD', 'anna@example.de', now()->subYears(8)->toDateTimeString());
        OrderReturn::create(['rma_number' => 'RMA-X-2', 'order_id' => $recent->id, 'status' => 'requested', 'reason' => 'other', 'comment' => 'Persoonlijke toelichting']);
        NewsletterSubscriber::create(['email' => 'anna@example.de', 'lang' => 'de', 'confirmed_at' => now()]);

        $this->postJson('/api/privacy/request', ['email' => 'anna@example.de', 'type' => 'erase'])->assertOk();
        $url = $this->sentUrl('erase');
        $this->assertStringContainsString('/privacy-verzoek/bevestigen?', $url);           // bevestigingspagina van de winkel, niet direct de API
        $query = parse_url($url, PHP_URL_QUERY);

        $this->postJson('/api/privacy/erase?email=anna%40example.de')->assertForbidden();  // zonder handtekening
        $res = $this->postJson('/api/privacy/erase?'.$query)->assertOk()->json();

        $this->assertTrue($res['newsletter_deleted']);
        $this->assertSame(1, $res['orders_minimized']);
        $this->assertSame(1, $res['orders_anonymized']);
        $this->assertSame('RB-RECENT', $res['orders_retained'][0]['order']);
        $this->assertDatabaseMissing('newsletter_subscribers', ['email' => 'anna@example.de']);

        $recent = $recent->fresh();
        $this->assertSame('Anna Becker', $recent->customer_name);       // factuurgegevens blijven (bewaarplicht)
        $this->assertSame('', $recent->phone);                           // telefoon is niet nodig: gewist
        $this->assertNull($recent->returns()->first()->comment);          // vrije tekst gewist
        $this->assertNull($recent->anonymized_at);
        $old = $old->fresh();
        $this->assertSame('Geanonimiseerd', $old->customer_name);
        $this->assertSame('anon-'.$old->id.'@deleted.invalid', $old->email);
        $this->assertSame([], $old->address);
        $this->assertNotNull($old->anonymized_at);
        $this->assertEquals(100, $old->total);                           // bedragen blijven voor de administratie

        $log = PrivacyRequest::where('type', 'erase')->where('status', 'completed')->first();
        $this->assertSame(hash('sha256', 'anna@example.de'), $log->email_hash);                // alleen een hash, nooit het adres zelf
        $this->assertStringNotContainsString('anna@example.de', json_encode($log->toArray()));
    }

    public function test_retention_command_anonymizes_expired_orders_and_cleans_up_stale_data(): void
    {
        $old = $this->order('RB-8Y', 'a@example.de', now()->subYears(8)->toDateTimeString());
        $fresh = $this->order('RB-1Y', 'b@example.de', now()->subYear()->toDateTimeString());
        NewsletterSubscriber::create(['email' => 'pending@example.de', 'lang' => 'nl'])->forceFill(['created_at' => now()->subDays(40)])->save();
        NewsletterSubscriber::create(['email' => 'recentpending@example.de', 'lang' => 'nl']);
        NewsletterSubscriber::create(['email' => 'ok@example.de', 'lang' => 'nl', 'confirmed_at' => now()->subDays(100)])->forceFill(['created_at' => now()->subDays(100)])->save();
        PrivacyRequest::create(['email_hash' => 'x', 'type' => 'export', 'requested_at' => now()->subYears(4)]);
        PrivacyRequest::create(['email_hash' => 'y', 'type' => 'export', 'requested_at' => now()->subDay()]);

        $this->artisan('shop:gdpr-retention')->assertExitCode(0);

        $this->assertNotNull($old->fresh()->anonymized_at);
        $this->assertNull($fresh->fresh()->anonymized_at);
        $this->assertSame(['ok@example.de', 'recentpending@example.de'], NewsletterSubscriber::orderBy('email')->pluck('email')->all()); // alleen het oude niet-bevestigde adres is weg
        $this->assertSame(['y'], PrivacyRequest::pluck('email_hash')->all());
    }

    public function test_rate_limits_are_counted_per_route_not_shared_across_the_whole_api(): void
    {
        // Zonder eigen prefix deelt elke throttle-route per IP één teller (met het langste venster): na 10 privacyverzoeken
        // zou ook "bestelling volgen" een uur lang een 429 geven. Elke route moet zijn eigen teller hebben.
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/privacy/request', ['email' => "x$i@example.com", 'type' => 'export'])->assertOk();
        }
        $this->postJson('/api/privacy/request', ['email' => 'x11@example.com', 'type' => 'export'])->assertStatus(429);   // eigen limiet bereikt
        $this->postJson('/api/orders/track', ['order_no' => 'RB-NOPE', 'email' => 'x@example.com'])->assertNotFound();    // andere route: onaangetast (geen 429)
        $this->postJson('/api/newsletter', ['email' => 'nieuw@example.com'])->assertOk();
    }

    public function test_newsletter_unsubscribe_needs_a_valid_signed_link(): void
    {
        NewsletterSubscriber::create(['email' => 'anna@example.de', 'lang' => 'nl', 'confirmed_at' => now()]);
        $this->get('/api/newsletter/unsubscribe?email=anna%40example.de')->assertForbidden();
        $this->assertDatabaseHas('newsletter_subscribers', ['email' => 'anna@example.de']);

        $this->get(URL::signedRoute('newsletter.unsubscribe', ['email' => 'anna@example.de']))->assertRedirect();
        $this->assertDatabaseMissing('newsletter_subscribers', ['email' => 'anna@example.de']);
    }

    public function test_admin_tools_require_login_and_show_retention_dates_and_a_hashed_request_log(): void
    {
        $this->order('RB-ADM', 'anna@example.de');
        $this->getJson('/api/admin/privacy/lookup?email=anna@example.de')->assertUnauthorized();
        $h = ['Authorization' => 'Bearer '.$this->postJson('/api/admin/login', ['password' => 'test-wachtwoord-123'])->assertOk()->json('token')];

        $look = $this->getJson('/api/admin/privacy/lookup?email=Anna@Example.de', $h)->assertOk()->json();
        $this->assertSame(1, $look['summary']['orders']);
        $this->assertSame(7, $look['retentionYears']);
        $this->assertSame(now()->subYear()->addYears(7)->toDateString(), $look['orders'][0]['retainedUntil']);

        $exp = $this->get('/api/admin/privacy/export?email=anna@example.de', $h)->assertOk();
        $this->assertSame('RB-ADM', $exp->json('orders.0.order_number'));
        $this->postJson('/api/admin/privacy/erase', ['email' => 'anna@example.de'], $h)->assertOk()->assertJsonPath('orders_minimized', 1);

        $log = $this->getJson('/api/admin/privacy/requests', $h)->assertOk()->json('requests');
        $this->assertCount(2, $log);                                    // export + erase, beide als "admin"
        $this->assertSame(8, strlen($log[0]['who']));                  // alleen het begin van de hash
        $this->assertStringNotContainsString('anna@example.de', json_encode($log));
    }
}
