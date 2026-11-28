<?php

namespace Tests\Feature;

use App\Mail\LoginCodeMail;
use App\Models\Customer;
use App\Models\LoginCode;
use App\Models\NewsletterSubscriber;
use App\Models\Order;
use App\Models\Product;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** Klantaccounts: inloggen met een eenmalige code, afscherming van het beheer, eigen bestellingen/retouren/verlanglijst/gegevens. */
class AccountTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Mail::fake();
    }

    /** Vraagt een code aan en geeft hem terug (uit de verstuurde mail). */
    private function requestCode(string $email): string
    {
        $code = '';
        $this->postJson('/api/account/login', ['email' => $email, 'lang' => 'de'])->assertOk()->assertJson(['ok' => true]);
        Mail::assertSent(LoginCodeMail::class, function ($m) use (&$code) {
            $code = $m->code;

            return true;
        });

        return $code;
    }

    private function loginAs(string $email): string
    {
        $code = $this->requestCode($email);

        return $this->postJson('/api/account/verify', ['email' => $email, 'code' => $code])->assertOk()->json('token');
    }

    /** Elke aanvraag met een ander token start met een schone guard (Sanctum onthoudt de gebruiker binnen één test). */
    private function as(string $token): array
    {
        $this->app['auth']->forgetGuards();

        return ['Authorization' => 'Bearer '.$token];
    }

    private function order(string $no, string $email, array $extra = []): Order
    {
        $o = Order::create($extra + [
            'order_no' => $no, 'stripe_session_id' => 'cs_'.$no, 'payment_intent_id' => 'pi_'.$no, 'status' => 'paid', 'customer_name' => 'Anna',
            'email' => $email, 'phone' => '+31600000', 'country' => 'NL', 'address' => ['city' => 'Breda'], 'lang' => 'nl',
            'subtotal' => 80, 'total' => 80, 'cost' => 30, 'paid_at' => now()->subDays(3), 'step' => 4,
        ]);
        $o->items()->create(['product_id' => 'p1', 'sku' => 'RB-1', 'name' => 'Aura', 'price' => 80, 'cost' => 30, 'qty' => 2]);

        return $o;
    }

    public function test_login_code_flow_is_single_use_expires_locks_after_five_wrong_tries_and_is_rate_limited(): void
    {
        $code = $this->requestCode('Anna@Example.nl');
        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);
        Mail::assertSent(LoginCodeMail::class, fn ($m) => $m->hasTo('anna@example.nl') && $m->lang === 'de');
        $this->assertDatabaseMissing('login_codes', ['code_hash' => $code]);                              // de code zelf wordt nergens bewaard
        $this->assertSame(LoginCode::hash('anna@example.nl', $code), LoginCode::first()->code_hash);

        $wrong = $code === '000000' ? '111111' : '000000';
        $this->postJson('/api/account/verify', ['email' => 'anna@example.nl', 'code' => $wrong])->assertStatus(422)->assertJsonPath('error', 'invalid_code');
        $this->postJson('/api/account/verify', ['email' => 'anna@example.nl', 'code' => 'abc'])->assertStatus(422);        // validatie
        $res = $this->postJson('/api/account/verify', ['email' => 'Anna@Example.nl', 'code' => $code])->assertOk();
        $this->assertNotEmpty($res->json('token'));
        $this->assertSame('anna@example.nl', $res->json('customer.email'));
        $this->assertSame(1, Customer::count());
        $this->postJson('/api/account/verify', ['email' => 'anna@example.nl', 'code' => $code])->assertStatus(422);       // één keer bruikbaar

        // Tweede login: zelfde account, nieuwe code.
        $this->loginAs('anna@example.nl');
        $this->assertSame(1, Customer::count());

        // Verlopen na 10 minuten.
        $code = $this->requestCode('late@example.nl');
        $this->travel(11)->minutes();
        $this->postJson('/api/account/verify', ['email' => 'late@example.nl', 'code' => $code])->assertStatus(422);
        $this->travelBack();

        // Na 5 foute pogingen is de code onbruikbaar, ook de juiste.
        $code = $this->requestCode('brute@example.nl');
        $wrong = $code === '123456' ? '654321' : '123456';
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/account/verify', ['email' => 'brute@example.nl', 'code' => $wrong])->assertStatus(422);
        }
        $this->postJson('/api/account/verify', ['email' => 'brute@example.nl', 'code' => $code])->assertStatus(422);
        $this->assertNull(Customer::where('email', 'brute@example.nl')->first());

        // Per adres maximaal 5 codes per uur: daarna geen mails meer (zelfde antwoord).
        for ($i = 0; $i < 7; $i++) {
            $this->postJson('/api/account/login', ['email' => 'spam@example.nl'])->assertOk();
        }
        Mail::assertSent(LoginCodeMail::class, fn ($m) => $m->hasTo('spam@example.nl') && true);
        $this->assertSame(5, Mail::sent(LoginCodeMail::class, fn ($m) => $m->hasTo('spam@example.nl'))->count());
    }

    public function test_customer_and_admin_tokens_cannot_cross_into_each_others_areas(): void
    {
        $token = $this->loginAs('anna@example.nl');
        $adminToken = $this->postJson('/api/admin/login', ['password' => 'test-wachtwoord-123'])->assertOk()->json('token');

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/account/me')->assertUnauthorized();                                          // zonder token
        $this->getJson('/api/account/me', $this->as($token))->assertOk()->assertJsonPath('email', 'anna@example.nl');
        $this->getJson('/api/admin/orders', $this->as($token))->assertForbidden();                          // klanttoken: geen beheer
        $this->getJson('/api/admin/privacy/lookup?email=anna@example.nl', $this->as($token))->assertForbidden();
        $this->getJson('/api/account/me', $this->as($adminToken))->assertForbidden();                      // beheertoken: geen klantaccount
        $this->getJson('/api/admin/orders', $this->as($adminToken))->assertOk();
    }

    public function test_customer_sees_only_own_orders_and_can_download_invoice_and_request_a_return(): void
    {
        $mine = $this->order('RB-MINE', 'anna@example.nl');                       // als gast besteld met hetzelfde e-mailadres
        $linked = $this->order('RB-LINKED', 'ander-adres@example.nl');            // aan het account gekoppeld (ander e-mailadres)
        $other = $this->order('RB-OTHER', 'iemand@example.nl');
        $token = $this->loginAs('anna@example.nl');
        Order::where('order_no', 'RB-LINKED')->update(['customer_id' => Customer::first()->id]);

        $list = $this->getJson('/api/account/orders', $this->as($token))->assertOk()->json('orders');
        $this->assertEqualsCanonicalizing(['RB-MINE', 'RB-LINKED'], array_column($list, 'order_no'));
        $this->assertNotContains('RB-OTHER', array_column($list, 'order_no'));
        $this->assertTrue($list[0]['returnable']);

        $detail = $this->getJson('/api/account/orders/rb-mine', $this->as($token))->assertOk()->json();
        $this->assertSame(2, $detail['items'][0]['qty']);
        $this->assertSame(2, $detail['returnItems'][0]['returnable']);                                           // retourgegevens uit de gedeelde logica
        $pdf = $this->get($detail['invoiceUrl'])->assertOk();                                               // ondertekende factuurlink
        $this->assertStringStartsWith('%PDF', $pdf->getContent());

        $this->getJson('/api/account/orders/RB-OTHER', $this->as($token))->assertNotFound();               // andermans bestelling: 404

        $itemId = $mine->items()->first()->id;
        $res = $this->postJson('/api/account/returns', ['order_no' => 'RB-MINE', 'items' => [['id' => $itemId, 'qty' => 1]], 'reason' => 'defective', 'comment' => 'Kapot'], $this->as($token))->assertCreated();
        $this->assertStringStartsWith('RMA-', $res->json('rma'));
        $this->assertSame(1, $res->json('returnItems.0.returnable'));
        $otherItem = $other->items()->first()->id;
        $this->postJson('/api/account/returns', ['order_no' => 'RB-OTHER', 'items' => [['id' => $otherItem, 'qty' => 1]], 'reason' => 'defective'], $this->as($token))->assertNotFound();
        $this->assertDatabaseCount('order_returns', 1);
    }

    public function test_wishlist_add_remove_and_merge_ignore_unknown_or_hidden_products(): void
    {
        $token = $this->loginAs('anna@example.nl');
        $h = fn () => $this->as($token);

        $this->postJson('/api/account/wishlist/p1', [], $h())->assertOk()->assertJsonPath('ids', ['p1']);
        $this->postJson('/api/account/wishlist/p1', [], $h())->assertOk();                                    // dubbel toevoegen: nog steeds één
        $this->assertCount(1, $this->getJson('/api/account/wishlist', $h())->json('ids'));
        $this->postJson('/api/account/wishlist/bestaat-niet', [], $h())->assertNotFound();
        $draft = Product::where('status', '!=', 'active')->first();
        if ($draft) {
            $this->postJson('/api/account/wishlist/'.$draft->id, [], $h())->assertNotFound();                // concept-producten zijn niet zichtbaar
        }

        $merged = $this->postJson('/api/account/wishlist/merge', ['ids' => ['p2', 'p3', 'p1', 'onzin']], $h())->assertOk()->json('ids');
        $this->assertEqualsCanonicalizing(['p1', 'p2', 'p3'], $merged);                                        // samengevoegd, onbekend genegeerd
        $this->deleteJson('/api/account/wishlist/p2', [], $h())->assertOk();
        $this->assertNotContains('p2', $this->getJson('/api/account/wishlist', $h())->json('ids'));
    }

    public function test_profile_name_and_newsletter_toggle(): void
    {
        $token = $this->loginAs('anna@example.nl');
        $this->patchJson('/api/account/me', ['name' => '<b>Anna</b> Becker'], $this->as($token))->assertOk()->assertJsonPath('name', 'Anna Becker');   // HTML weg

        $this->patchJson('/api/account/me', ['newsletter' => true, 'lang' => 'de'], $this->as($token))->assertOk()->assertJsonPath('newsletter', true);
        $sub = NewsletterSubscriber::where('email', 'anna@example.nl')->first();
        $this->assertNotNull($sub->confirmed_at);                                                           // bewust aangezet door een geverifieerde klant
        $this->assertSame('de', $sub->lang);
        $this->patchJson('/api/account/me', ['newsletter' => false], $this->as($token))->assertOk()->assertJsonPath('newsletter', false);
        $this->assertDatabaseMissing('newsletter_subscribers', ['email' => 'anna@example.nl']);
    }

    public function test_export_contains_account_data_and_erase_removes_the_account_revokes_tokens_and_keeps_legal_records(): void
    {
        $this->order('RB-MINE', 'anna@example.nl');
        $this->order('RB-OTHER', 'iemand@example.nl');
        $token = $this->loginAs('anna@example.nl');
        $this->postJson('/api/account/wishlist/p1', [], $this->as($token))->assertOk();

        $export = $this->get('/api/account/export', $this->as($token))->assertOk();
        $this->assertStringContainsString('attachment', $export->headers->get('Content-Disposition'));
        $json = $export->json();
        $this->assertSame(['RB-MINE'], array_column($json['orders'], 'order_number'));
        $this->assertSame(['p1'], $json['account']['wishlist']);
        $this->assertStringNotContainsString('iemand@example.nl', $export->getContent());

        $res = $this->postJson('/api/account/erase', [], $this->as($token))->assertOk()->json();
        $this->assertTrue($res['account_deleted']);
        $this->assertSame(1, $res['orders_minimized']);                                                     // binnen de bewaartermijn: alleen minimaliseren
        $this->assertSame(0, Customer::count());
        $this->assertDatabaseCount('wishlist_items', 0);
        $this->assertSame('', Order::where('order_no', 'RB-MINE')->value('phone'));
        $this->getJson('/api/account/me', $this->as($token))->assertUnauthorized();                         // token is ingetrokken
    }

    public function test_logout_revokes_the_token(): void
    {
        $token = $this->loginAs('anna@example.nl');
        $this->postJson('/api/account/logout', [], $this->as($token))->assertOk();
        $this->getJson('/api/account/me', $this->as($token))->assertUnauthorized();
    }

    public function test_retention_removes_expired_codes_and_accounts_unused_for_three_years(): void
    {
        $old = Customer::create(['email' => 'oud@example.nl', 'last_login_at' => now()->subYears(4)]);
        $old->createToken('shop', ['customer']);
        Customer::create(['email' => 'actief@example.nl', 'last_login_at' => now()->subMonth()]);
        LoginCode::create(['email' => 'x@example.nl', 'code_hash' => 'h', 'expires_at' => now()->subDays(2), 'created_at' => now()->subDays(2)]);

        $this->artisan('shop:gdpr-retention')->assertExitCode(0);

        $this->assertSame(['actief@example.nl'], Customer::pluck('email')->all());
        $this->assertDatabaseCount('login_codes', 0);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }
}
