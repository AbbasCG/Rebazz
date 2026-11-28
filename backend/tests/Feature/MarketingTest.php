<?php

namespace Tests\Feature;

use App\Mail\AbandonedCartMail;
use App\Mail\NewsletterMail;
use App\Models\AbandonedCart;
use App\Models\NewsletterCampaign;
use App\Models\NewsletterSubscriber;
use App\Models\Order;
use App\Services\MarketingService;
use App\Services\PrivacyService;
use App\Services\StripeGateway;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** Marketing met toestemming: herinnering bij verlaten kassa (alleen opt-in, één keer) en nieuwsbriefcampagnes. */
class MarketingTest extends TestCase
{
    use RefreshDatabase;

    private FakeStripe $stripe;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->stripe = new FakeStripe;
        $this->app->instance(StripeGateway::class, $this->stripe);
        Mail::fake();
    }

    private function admin(): array
    {
        return ['Authorization' => 'Bearer '.$this->postJson('/api/admin/login', ['password' => 'test-wachtwoord-123'])->assertOk()->json('token')];
    }

    private function expiredEvent(array $over = []): array
    {
        return ['type' => 'checkout.session.expired', 'data' => ['object' => array_replace_recursive([
            'id' => 'cs_test_exp1', 'created' => time() - 4000, 'metadata' => ['lang' => 'de'],
            'customer_details' => ['email' => 'Anna@Example.de'], 'consent' => ['promotions' => 'opt_in'],
            'after_expiration' => ['recovery' => ['enabled' => true, 'url' => 'https://checkout.stripe.com/c/pay/cs_test_exp1#abc']],
        ], $over)]];
    }

    private function webhook(array $event)
    {
        return $this->call('POST', '/api/stripe/webhook', [], [], [], ['HTTP_STRIPE_SIGNATURE' => 'ok', 'CONTENT_TYPE' => 'application/json'], json_encode($event));
    }

    public function test_checkout_asks_stripe_for_consent_and_a_recovery_link(): void
    {
        $this->postJson('/api/checkout', ['items' => [['id' => 'p1', 'qty' => 1]], 'country' => 'DE', 'lang' => 'de'])->assertOk();
        $p = $this->stripe->lastParams;
        $this->assertSame(['promotions' => 'auto'], $p['consent_collection']);
        $this->assertTrue($p['after_expiration']['recovery']['enabled']);
        $this->assertGreaterThan(time() + 1700, $p['expires_at']);
        $this->assertLessThan(time() + 86400, $p['expires_at']);
    }

    public function test_cart_reminders_can_be_switched_off(): void
    {
        config(['shop.abandoned_cart' => false]);
        $this->postJson('/api/checkout', ['items' => [['id' => 'p1', 'qty' => 1]], 'country' => 'DE', 'lang' => 'de'])->assertOk();
        $this->assertArrayNotHasKey('consent_collection', $this->stripe->lastParams);
        $this->assertArrayNotHasKey('after_expiration', $this->stripe->lastParams);
        $this->webhook($this->expiredEvent())->assertOk();
        $this->assertSame(0, AbandonedCart::count());
    }

    public function test_expired_session_with_consent_sends_exactly_one_reminder_in_the_customers_language(): void
    {
        $this->webhook($this->expiredEvent())->assertOk();
        $this->webhook($this->expiredEvent())->assertOk();                      // Stripe stuurt gebeurtenissen soms dubbel
        $this->assertSame(1, AbandonedCart::count());
        $this->assertSame('anna@example.de', AbandonedCart::first()->email);

        $m = $this->app->make(MarketingService::class);
        $this->assertSame(1, $m->sendCartReminders());
        $this->assertSame(0, $m->sendCartReminders());                           // nooit een tweede herinnering
        Mail::assertSent(AbandonedCartMail::class, fn ($mail) => $mail->hasTo('anna@example.de') && $mail->lang === 'de' && str_starts_with($mail->url, 'https://checkout.stripe.com/'));
        Mail::assertSent(AbandonedCartMail::class, 1);
        $this->assertNull(AbandonedCart::first()->recovery_url);                 // de link wordt na verzending niet bewaard
    }

    public function test_no_reminder_without_consent_or_email_or_recovery_link(): void
    {
        $this->webhook($this->expiredEvent(['id' => 'cs_a', 'consent' => ['promotions' => 'opt_out']]))->assertOk();
        $this->webhook($this->expiredEvent(['id' => 'cs_b', 'consent' => ['promotions' => null]]))->assertOk();
        $ev = $this->expiredEvent(['id' => 'cs_c']);
        unset($ev['data']['object']['consent']);
        $this->webhook($ev)->assertOk();
        $this->webhook($this->expiredEvent(['id' => 'cs_d', 'customer_details' => ['email' => 'geen-mail']]))->assertOk();
        $this->webhook($this->expiredEvent(['id' => 'cs_e', 'after_expiration' => ['recovery' => ['url' => 'http://onveilig.example/x']]]))->assertOk();
        $this->assertSame(0, AbandonedCart::count());
        $this->assertSame(0, $this->app->make(MarketingService::class)->sendCartReminders());
        Mail::assertNothingSent();
    }

    public function test_no_reminder_when_the_customer_ordered_in_the_meantime(): void
    {
        $this->webhook($this->expiredEvent())->assertOk();
        Order::create([
            'order_no' => 'RB-LATER', 'stripe_session_id' => 'cs_later', 'payment_intent_id' => 'pi_later', 'status' => 'paid', 'customer_name' => 'Anna',
            'email' => 'anna@example.de', 'phone' => '', 'country' => 'DE', 'address' => [], 'lang' => 'de', 'subtotal' => 10, 'total' => 10, 'cost' => 4, 'paid_at' => now(),
        ]);
        $this->assertSame(0, $this->app->make(MarketingService::class)->sendCartReminders());
        Mail::assertNothingSent();
        $this->assertNotNull(AbandonedCart::first()->sent_at);
    }

    public function test_old_unsent_reminders_are_not_sent_and_old_rows_are_pruned(): void
    {
        $this->webhook($this->expiredEvent())->assertOk();
        AbandonedCart::query()->update(['created_at' => now()->subHours(60)]);
        $this->assertSame(0, $this->app->make(MarketingService::class)->sendCartReminders());
        AbandonedCart::query()->update(['created_at' => now()->subDays(31)]);
        $this->assertSame(1, $this->app->make(MarketingService::class)->prune()['carts']);
    }

    public function test_erasing_a_person_removes_their_reminder_and_delivery_records(): void
    {
        $this->webhook($this->expiredEvent())->assertOk();
        $c = NewsletterCampaign::create(['subject' => 'S', 'body' => 'B', 'status' => 'sent']);
        $c->deliveries()->create(['email' => 'anna@example.de']);
        $this->app->make(PrivacyService::class)->erase('Anna@Example.de');
        $this->assertSame(0, AbandonedCart::count());
        $this->assertSame(0, $c->deliveries()->count());
    }

    /* ---------------- Nieuwsbrief ---------------- */

    private function subscribers(): void
    {
        NewsletterSubscriber::create(['email' => 'a@example.nl', 'lang' => 'nl', 'confirmed_at' => now()]);
        NewsletterSubscriber::create(['email' => 'B@example.de', 'lang' => 'de', 'confirmed_at' => now()]);
        NewsletterSubscriber::create(['email' => 'c@example.de', 'lang' => 'de', 'confirmed_at' => now()]);
        NewsletterSubscriber::create(['email' => 'pending@example.nl', 'lang' => 'nl']);      // niet bevestigd: nooit mailen
    }

    private function campaign(array $over = []): int
    {
        return $this->postJson('/api/admin/newsletters', $over + ['subject' => 'Nieuw: Aura', 'body' => "Hallo!\n\nNieuwe collectie <b>live</b>.", 'cta_label' => 'Bekijk', 'cta_url' => 'https://rebazz.nl/'], $this->admin())
            ->assertCreated()->json('id');
    }

    public function test_newsletter_admin_requires_login_and_validates_input(): void
    {
        $this->getJson('/api/admin/newsletters')->assertUnauthorized();
        $h = $this->admin();
        $this->postJson('/api/admin/newsletters', ['subject' => '', 'body' => ''], $h)->assertStatus(422);
        $this->postJson('/api/admin/newsletters', ['subject' => 'S', 'body' => 'B', 'cta_label' => 'Klik', 'cta_url' => 'http://onveilig.example'], $h)->assertStatus(422);
        $this->postJson('/api/admin/newsletters', ['subject' => 'S', 'body' => 'B', 'cta_label' => 'Klik'], $h)->assertStatus(422);       // label zonder link
        $this->postJson('/api/admin/newsletters', ['subject' => 'S', 'body' => 'B', 'lang' => 'xx'], $h)->assertStatus(422);
    }

    public function test_campaign_goes_only_to_confirmed_subscribers_in_batches_without_duplicates(): void
    {
        $this->subscribers();
        $id = $this->campaign();
        $h = $this->admin();
        $this->assertSame(3, $this->getJson('/api/admin/newsletters', $h)->json('campaigns.0.audience'));
        $this->postJson("/api/admin/newsletters/$id/send", [], $h)->assertOk()->assertJsonPath('status', 'sending');
        $this->postJson("/api/admin/newsletters/$id/send", [], $h)->assertStatus(422);                    // niet twee keer starten
        $this->putJson("/api/admin/newsletters/$id", ['subject' => 'x', 'body' => 'y'], $h)->assertStatus(422); // lopend = vergrendeld

        $m = $this->app->make(MarketingService::class);
        $this->assertSame(2, $m->sendNewsletterBatch(2));
        $this->assertSame('sending', NewsletterCampaign::find($id)->status);
        $this->assertSame(1, $m->sendNewsletterBatch(2));
        $this->assertSame(0, $m->sendNewsletterBatch(2));
        $c = NewsletterCampaign::find($id);
        $this->assertSame('sent', $c->status);
        $this->assertSame(3, $c->sent_count);
        $this->assertNotNull($c->finished_at);
        Mail::assertSent(NewsletterMail::class, 3);
        Mail::assertNotSent(NewsletterMail::class, fn ($mail) => $mail->hasTo('pending@example.nl'));
        Mail::assertSent(NewsletterMail::class, fn ($mail) => $mail->hasTo('b@example.de') && $mail->lang === 'de');
    }

    public function test_language_filter_and_unsubscribe_during_sending(): void
    {
        $this->subscribers();
        $id = $this->campaign(['lang' => 'de']);
        $h = $this->admin();
        $this->postJson("/api/admin/newsletters/$id/send", [], $h)->assertOk();
        $m = $this->app->make(MarketingService::class);
        $this->assertSame(1, $m->sendNewsletterBatch(1));
        NewsletterSubscriber::whereRaw('lower(email) = ?', ['c@example.de'])->orWhereRaw('lower(email) = ?', ['b@example.de'])->delete(); // wie zich afmeldt krijgt niets meer
        $this->assertSame(0, $m->sendNewsletterBatch(10));
        $this->assertSame('sent', NewsletterCampaign::find($id)->status);
        Mail::assertSent(NewsletterMail::class, 1);
        Mail::assertNotSent(NewsletterMail::class, fn ($mail) => $mail->hasTo('a@example.nl'));
    }

    public function test_cannot_send_to_an_empty_audience(): void
    {
        $id = $this->campaign();
        $this->postJson("/api/admin/newsletters/$id/send", [], $this->admin())->assertStatus(422);
    }

    public function test_mail_escapes_content_and_contains_a_working_signed_unsubscribe_link(): void
    {
        $c = NewsletterCampaign::create(['subject' => 'S', 'body' => "Hallo <script>alert(1)</script>\n\nTweede alinea", 'cta_label' => 'Bekijk', 'cta_url' => 'https://rebazz.nl/']);
        $mail = new NewsletterMail($c, 'Anna@Example.nl', 'en');
        $html = $mail->render();
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringContainsString('Unsubscribe from this newsletter', $html);
        $this->assertStringContainsString('Beemd 15', $html);                                                // afzender/adres
        $this->assertStringContainsString('List-Unsubscribe', json_encode($mail->headers()->text));

        NewsletterSubscriber::create(['email' => 'anna@example.nl', 'lang' => 'en', 'confirmed_at' => now()]);
        $url = $mail->unsubscribeUrl();
        $this->get($url)->assertRedirect();
        $this->assertSame(0, NewsletterSubscriber::count());
        NewsletterSubscriber::create(['email' => 'anna@example.nl', 'lang' => 'en', 'confirmed_at' => now()]);
        $this->post($url)->assertRedirect();                                                                 // eén-klik afmelden (RFC 8058) is een POST
        $this->assertSame(0, NewsletterSubscriber::count());
        $this->get(preg_replace('/email=[^&]+/', 'email=ander%40example.nl', $url))->assertForbidden();       // gewijzigde link werkt niet
    }

    public function test_cta_without_https_is_not_rendered_and_test_mail_goes_to_one_address_only(): void
    {
        $this->subscribers();
        $id = $this->campaign();
        $h = $this->admin();
        $this->postJson("/api/admin/newsletters/$id/test", ['email' => 'eigenaar@example.nl'], $h)->assertOk()->assertJsonPath('to', 'eigenaar@example.nl');
        Mail::assertSent(NewsletterMail::class, 1);
        Mail::assertSent(NewsletterMail::class, fn ($mail) => $mail->hasTo('eigenaar@example.nl') && str_starts_with($mail->campaign->subject, '[TEST] '));
        $this->assertSame('draft', NewsletterCampaign::find($id)->status);                                  // een test start niets
        $this->assertSame(0, NewsletterCampaign::find($id)->sent_count);
    }

    public function test_drafts_can_be_edited_and_deleted_sent_ones_not_edited(): void
    {
        $id = $this->campaign();
        $h = $this->admin();
        $this->putJson("/api/admin/newsletters/$id", ['subject' => 'Nieuw onderwerp', 'body' => 'Tekst'], $h)->assertOk()->assertJsonPath('subject', 'Nieuw onderwerp')->assertJsonPath('cta_label', null);
        $this->deleteJson("/api/admin/newsletters/$id", [], $h)->assertOk();
        $this->assertSame(0, NewsletterCampaign::count());
        $sent = NewsletterCampaign::create(['subject' => 'S', 'body' => 'B', 'status' => 'sent']);
        $this->putJson("/api/admin/newsletters/{$sent->id}", ['subject' => 'x', 'body' => 'y'], $h)->assertStatus(422);
    }
}
