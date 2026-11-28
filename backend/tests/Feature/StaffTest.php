<?php

namespace Tests\Feature;

use App\Models\AdminAuditLog;
use App\Models\User;
use App\Services\TwoFactorService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/** Personeelsrollen, tweestapsverificatie (TOTP + herstelcodes), teambeheer en het activiteitenlogboek. */
class StaffTest extends TestCase
{
    use RefreshDatabase;

    private const PW = 'een-lang-wachtwoord-1';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class); // de blokkades die we hier testen zitten in RateLimiter-sleutels, niet in de route-throttle
    }

    /** Elke aanvraag met een ander token start met een schone guard (Sanctum onthoudt de gebruiker binnen één test). */
    private function h(string $token): array
    {
        $this->app['auth']->forgetGuards();

        return ['Authorization' => 'Bearer '.$token];
    }

    private function owner(): string
    {
        return $this->postJson('/api/admin/login', ['password' => 'test-wachtwoord-123'])->assertOk()->json('token');
    }

    private function member(string $role, ?string $email = null): array
    {
        $email ??= $role.'@example.nl';
        $this->postJson('/api/admin/team', ['name' => ucfirst($role), 'email' => $email, 'role' => $role, 'password' => self::PW], $this->h($this->owner()))->assertCreated();

        return [$email, $this->postJson('/api/admin/login', ['email' => $email, 'password' => self::PW])->assertOk()->json('token')];
    }

    private function totp(string $secret, int $offset = 0): string
    {
        return app(TwoFactorService::class)->codeAt($secret, intdiv(time(), 30) + $offset);
    }

    /** Zet 2FA voor een token aan en geeft [geheim, herstelcodes] terug. */
    private function enroll(string $token): array
    {
        $secret = $this->postJson('/api/admin/me/2fa/setup', [], $this->h($token))->assertOk()->json('secret');
        $codes = $this->postJson('/api/admin/me/2fa/enable', ['code' => $this->totp($secret)], $this->h($token))->assertOk()->json('recoveryCodes');

        return [$secret, $codes];
    }

    /* ---------------- Rollen ---------------- */

    public function test_roles_limit_what_each_member_can_reach(): void
    {
        [, $support] = $this->member('support');
        [, $marketing] = $this->member('marketing');
        [, $manager] = $this->member('manager');

        // support: bestellingen en retouren, niets anders
        $this->getJson('/api/admin/orders', $this->h($support))->assertOk();
        $this->getJson('/api/admin/returns', $this->h($support))->assertOk();
        $this->getJson('/api/admin/status', $this->h($support))->assertOk();
        foreach (['/api/admin/products', '/api/admin/analytics', '/api/admin/newsletters', '/api/admin/tax?year=2026&quarter=1', '/api/admin/privacy/requests', '/api/admin/team'] as $url) {
            $this->getJson($url, $this->h($support))->assertForbidden();
        }
        // marketing: nieuwsbrief, statistieken, producten; geen klantgegevens
        $this->getJson('/api/admin/analytics', $this->h($marketing))->assertOk();
        $this->getJson('/api/admin/newsletters', $this->h($marketing))->assertOk();
        $this->getJson('/api/admin/products', $this->h($marketing))->assertOk();
        foreach (['/api/admin/orders', '/api/admin/returns', '/api/admin/tax?year=2026&quarter=1', '/api/admin/privacy/lookup?email=a@b.nl', '/api/admin/team'] as $url) {
            $this->getJson($url, $this->h($marketing))->assertForbidden();
        }
        // manager: alles behalve team
        foreach (['/api/admin/orders', '/api/admin/products', '/api/admin/analytics', '/api/admin/tax?year=2026&quarter=1', '/api/admin/privacy/requests'] as $url) {
            $this->getJson($url, $this->h($manager))->assertOk();
        }
        $this->getJson('/api/admin/team', $this->h($manager))->assertForbidden();
        $this->getJson('/api/admin/team', $this->h($this->owner()))->assertOk();
    }

    public function test_refunds_are_limited_to_owner_and_manager_but_returns_can_be_handled_by_support(): void
    {
        [, $support] = $this->member('support');
        $this->postJson('/api/admin/orders/RB-ONBEKEND/refund', [], $this->h($support))->assertForbidden();
        $this->postJson('/api/admin/returns/RMA-X/refund', ['amount' => 1], $this->h($support))->assertForbidden();
        $this->postJson('/api/admin/returns/RMA-X/approve', [], $this->h($support))->assertNotFound();      // wel toegestaan (bestaat alleen niet)
        [, $manager] = $this->member('manager');
        $this->postJson('/api/admin/orders/RB-ONBEKEND/refund', [], $this->h($manager))->assertNotFound();    // niet verboden: het order bestaat alleen niet
    }

    public function test_settings_writes_depend_on_the_key_and_role(): void
    {
        [, $marketing] = $this->member('marketing');
        [, $support] = $this->member('support');
        $this->putJson('/api/admin/settings/campaigns', ['value' => []], $this->h($marketing))->assertOk();
        $this->putJson('/api/admin/settings/brand', ['value' => ['name' => 'Hack']], $this->h($marketing))->assertForbidden();
        $this->putJson('/api/admin/settings/campaigns', ['value' => []], $this->h($support))->assertForbidden();
        $this->getJson('/api/admin/settings/checklist', $this->h($support))->assertOk();                      // lezen mag iedere medewerker
    }

    public function test_customer_tokens_and_unauthenticated_calls_never_reach_the_admin(): void
    {
        $this->getJson('/api/admin/me')->assertUnauthorized();
        $customerToken = \App\Models\Customer::forceCreate(['email' => 'k@example.nl', 'name' => ''])->createToken('c', ['customer'])->plainTextToken;
        $this->getJson('/api/admin/me', $this->h($customerToken))->assertForbidden();
    }

    /* ---------------- Inloggen ---------------- */

    public function test_staff_login_is_generic_rate_limited_and_the_owner_email_is_not_a_login(): void
    {
        $this->member('support', 'anna@example.nl');
        $this->postJson('/api/admin/login', ['email' => 'anna@example.nl', 'password' => 'fout'])->assertStatus(401)->assertJsonPath('message', 'Onjuiste inloggegevens.');
        $this->postJson('/api/admin/login', ['email' => 'bestaat-niet@example.nl', 'password' => 'fout'])->assertStatus(401)->assertJsonPath('message', 'Onjuiste inloggegevens.'); // zelfde melding
        // het break-glass-account werkt niet via e-mail, ook niet met het juiste wachtwoord
        $this->owner();
        $this->postJson('/api/admin/login', ['email' => User::OWNER_EMAIL, 'password' => 'test-wachtwoord-123'])->assertStatus(401);
        // na 10 mislukte pogingen voor één account volgt een blokkade (ook met het juiste wachtwoord)
        RateLimiter::clear('admin-login-user:anna@example.nl');
        for ($i = 0; $i < 10; $i++) {
            RateLimiter::hit('admin-login-user:anna@example.nl', 3600);
        }
        $this->postJson('/api/admin/login', ['email' => 'anna@example.nl', 'password' => self::PW])->assertStatus(429);
    }

    public function test_deactivating_or_changing_a_member_logs_them_out_immediately(): void
    {
        [$email, $token] = $this->member('support', 'bob@example.nl');
        $id = User::where('email', $email)->value('id');
        $this->getJson('/api/admin/orders', $this->h($token))->assertOk();
        $this->putJson("/api/admin/team/$id", ['active' => false], $this->h($this->owner()))->assertOk();
        $this->getJson('/api/admin/orders', $this->h($token))->assertUnauthorized();                          // token ingetrokken
        $this->postJson('/api/admin/login', ['email' => $email, 'password' => self::PW])->assertStatus(401);  // niet meer actief
        $this->putJson("/api/admin/team/$id", ['active' => true, 'role' => 'marketing'], $this->h($this->owner()))->assertOk()->assertJsonPath('role', 'marketing');
        $new = $this->postJson('/api/admin/login', ['email' => $email, 'password' => self::PW])->assertOk()->json('token');
        $this->getJson('/api/admin/orders', $this->h($new))->assertForbidden();                               // nieuwe rol geldt
    }

    public function test_team_management_rules(): void
    {
        $o = $this->h($this->owner());
        $ownerId = User::where('email', User::OWNER_EMAIL)->value('id');
        $this->postJson('/api/admin/team', ['name' => 'X', 'email' => 'x@example.nl', 'role' => 'superadmin', 'password' => self::PW], $o)->assertStatus(422);
        $this->postJson('/api/admin/team', ['name' => 'X', 'email' => 'x@example.nl', 'role' => 'support', 'password' => 'kort'], $this->h($this->owner()))->assertStatus(422);
        $this->postJson('/api/admin/team', ['name' => 'X', 'email' => User::OWNER_EMAIL, 'role' => 'support', 'password' => self::PW], $this->h($this->owner()))->assertStatus(422);
        $this->member('support', 'dubbel@example.nl');
        $this->postJson('/api/admin/team', ['name' => 'Y', 'email' => 'Dubbel@Example.nl', 'role' => 'support', 'password' => self::PW], $this->h($this->owner()))->assertStatus(422);
        // het eigenaarsaccount en jezelf zijn beschermd
        $this->putJson("/api/admin/team/$ownerId", ['active' => false], $this->h($this->owner()))->assertStatus(422);
        $this->putJson("/api/admin/team/$ownerId", ['role' => 'support'], $this->h($this->owner()))->assertStatus(422);
        $this->deleteJson("/api/admin/team/$ownerId", [], $this->h($this->owner()))->assertStatus(422);
        // verwijderen
        $id = User::where('email', 'dubbel@example.nl')->value('id');
        $this->deleteJson("/api/admin/team/$id", [], $this->h($this->owner()))->assertOk();
        $this->assertNull(User::find($id));
        // een tweede eigenaar mag zichzelf niet degraderen of verwijderen
        [$email, $second] = $this->member('owner', 'tweede@example.nl');
        $sid = User::where('email', $email)->value('id');
        $this->putJson("/api/admin/team/$sid", ['role' => 'support'], $this->h($second))->assertStatus(422);
        $this->deleteJson("/api/admin/team/$sid", [], $this->h($second))->assertStatus(422);
    }

    public function test_members_can_change_their_own_password_and_other_sessions_end(): void
    {
        [$email, $t1] = $this->member('support', 'carl@example.nl');
        $t2 = $this->postJson('/api/admin/login', ['email' => $email, 'password' => self::PW])->assertOk()->json('token');
        $this->putJson('/api/admin/me/password', ['current_password' => 'fout-wachtwoord', 'password' => 'nieuw-lang-wachtwoord-2'], $this->h($t1))->assertStatus(422);
        $this->putJson('/api/admin/me/password', ['current_password' => self::PW, 'password' => 'kort'], $this->h($t1))->assertStatus(422);
        $this->putJson('/api/admin/me/password', ['current_password' => self::PW, 'password' => 'nieuw-lang-wachtwoord-2'], $this->h($t1))->assertOk();
        $this->getJson('/api/admin/me', $this->h($t1))->assertOk();                                           // huidige sessie blijft
        $this->getJson('/api/admin/me', $this->h($t2))->assertUnauthorized();                                 // andere sessies uitgelogd
        $this->postJson('/api/admin/login', ['email' => $email, 'password' => self::PW])->assertStatus(401);
        $this->postJson('/api/admin/login', ['email' => $email, 'password' => 'nieuw-lang-wachtwoord-2'])->assertOk();
        // de eigenaar wijzigt het wachtwoord via .env, niet hier
        $this->putJson('/api/admin/me/password', ['current_password' => 'test-wachtwoord-123', 'password' => 'nieuw-lang-wachtwoord-2'], $this->h($this->owner()))->assertStatus(422);
    }

    /* ---------------- Tweestapsverificatie ---------------- */

    public function test_totp_matches_the_rfc_6238_test_vectors_and_rejects_replays(): void
    {
        $tf = app(TwoFactorService::class);
        $secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ'; // ASCII "12345678901234567890" (RFC 6238, SHA-1)
        $this->assertSame('287082', $tf->codeAt($secret, intdiv(59, 30)));            // T = 59 → 94287082, laatste 6 cijfers
        $this->assertSame('081804', $tf->codeAt($secret, intdiv(1111111109, 30)));
        $this->assertSame('050471', $tf->codeAt($secret, intdiv(1111111111, 30)));
        $this->assertSame('005924', $tf->codeAt($secret, intdiv(1234567890, 30)));
        $now = 1111111109;
        $step = $tf->verifyTotp($secret, '081804', null, $now);
        $this->assertSame(intdiv($now, 30), $step);
        $this->assertNull($tf->verifyTotp($secret, '081804', $step, $now));          // dezelfde stap kan niet opnieuw
        $this->assertNotNull($tf->verifyTotp($secret, '081804', null, $now + 30));   // ±1 stap klokverschil
        $this->assertNull($tf->verifyTotp($secret, '081804', null, $now + 120));     // te oud
        $this->assertNull($tf->verifyTotp($secret, '12345', null, $now));            // geen 6 cijfers
        $this->assertNull($tf->verifyTotp($secret, 'abcdef', null, $now));
    }

    public function test_enrolling_two_factor_then_login_requires_a_valid_single_use_code(): void
    {
        [$email, $token] = $this->member('support', 'dina@example.nl');
        $setup = $this->postJson('/api/admin/me/2fa/setup', [], $this->h($token))->assertOk()->json();
        $this->assertMatchesRegularExpression('/^[A-Z2-7]{32}$/', $setup['secret']);
        $this->assertStringStartsWith('otpauth://totp/', $setup['uri']);
        $this->assertStringContainsString('secret='.$setup['secret'], $setup['uri']);
        $this->assertFalse($this->getJson('/api/admin/me', $this->h($token))->json('twoFactor.enabled'));     // pas actief na bevestigen
        $this->postJson('/api/admin/me/2fa/enable', ['code' => '000000'], $this->h($token))->assertStatus(422);
        $res = $this->postJson('/api/admin/me/2fa/enable', ['code' => $this->totp($setup['secret'])], $this->h($token))->assertOk();
        $this->assertCount(8, $res->json('recoveryCodes'));
        $this->assertTrue($res->json('twoFactor.enabled'));
        $this->assertSame(8, $res->json('twoFactor.recoveryLeft'));
        $this->assertStringNotContainsString($res->json('recoveryCodes.0'), (string) User::where('email', $email)->value('two_factor_recovery')); // alleen hashes
        $this->assertNotSame($setup['secret'], (string) \DB::table('users')->where('email', $email)->value('two_factor_secret'));            // versleuteld opgeslagen
        $this->postJson('/api/admin/me/2fa/setup', [], $this->h($token))->assertStatus(409);

        // inloggen: zonder code → 422, fout → 401, goed → token; dezelfde code nogmaals → 401 (alleen deze minuut één keer)
        $this->postJson('/api/admin/login', ['email' => $email, 'password' => self::PW])->assertStatus(422)->assertJsonPath('error', 'two_factor_required');
        $this->postJson('/api/admin/login', ['email' => $email, 'password' => self::PW, 'code' => '123456'])->assertStatus(401);
        $code = $this->totp($setup['secret'], 1);                                                              // stap na de gebruikte
        $this->postJson('/api/admin/login', ['email' => $email, 'password' => self::PW, 'code' => $code])->assertOk()->assertJsonStructure(['token']);
        $this->postJson('/api/admin/login', ['email' => $email, 'password' => self::PW, 'code' => $code])->assertStatus(401);
        // het wachtwoord moet ook kloppen: een goede code alleen is niet genoeg
        $this->postJson('/api/admin/login', ['email' => $email, 'password' => 'fout', 'code' => $this->totp($setup['secret'], -1)])->assertStatus(401);
    }

    public function test_recovery_codes_work_once_and_wrong_codes_lock_the_account_for_a_while(): void
    {
        [$email, $token] = $this->member('support', 'eva@example.nl');
        [$secret, $codes] = $this->enroll($token);
        $this->postJson('/api/admin/login', ['email' => $email, 'password' => self::PW, 'code' => strtoupper($codes[0])])->assertOk();   // hoofdletters/streepje maken niet uit
        $this->postJson('/api/admin/login', ['email' => $email, 'password' => self::PW, 'code' => $codes[0]])->assertStatus(401);         // eenmalig
        $this->assertSame(7, $this->getJson('/api/admin/me', $this->h($token))->json('twoFactor.recoveryLeft'));
        for ($i = 0; $i < 4; $i++) {                                                                      // 1 foute herstelcode hierboven + 4 = 5 pogingen
            $this->postJson('/api/admin/login', ['email' => $email, 'password' => self::PW, 'code' => '000000'])->assertStatus(401);
        }
        $this->postJson('/api/admin/login', ['email' => $email, 'password' => self::PW, 'code' => $codes[1]])->assertStatus(429);         // geblokkeerd, ook met een goede herstelcode
    }

    public function test_disabling_and_regenerating_need_password_and_code(): void
    {
        [$email, $token] = $this->member('support', 'finn@example.nl');
        [$secret, $codes] = $this->enroll($token);
        $this->postJson('/api/admin/me/2fa/recovery', ['password' => 'fout', 'code' => $codes[0]], $this->h($token))->assertStatus(422);
        $new = $this->postJson('/api/admin/me/2fa/recovery', ['password' => self::PW, 'code' => $codes[0]], $this->h($token))->assertOk()->json('recoveryCodes');
        $this->assertCount(8, $new);
        $this->assertNotContains($codes[1], $new);
        $this->postJson('/api/admin/login', ['email' => $email, 'password' => self::PW, 'code' => $codes[1]])->assertStatus(401);         // oude codes vervallen
        $this->postJson('/api/admin/me/2fa/disable', ['password' => self::PW, 'code' => '000000'], $this->h($token))->assertStatus(422);
        $this->postJson('/api/admin/me/2fa/disable', ['password' => self::PW, 'code' => $new[0]], $this->h($token))->assertOk()->assertJsonPath('twoFactor.enabled', false);
        $this->postJson('/api/admin/login', ['email' => $email, 'password' => self::PW])->assertOk();                                   // weer zonder code
    }

    public function test_the_owner_can_use_two_factor_too_and_the_owner_can_reset_a_members_2fa(): void
    {
        [$email, $token] = $this->member('support', 'gijs@example.nl');          // eerst het lid aanmaken: daarna vraagt het eigenaarsaccount om een code
        $this->enroll($token);
        $id = User::where('email', $email)->value('id');
        $owner = $this->owner();
        [$secret, $ownerCodes] = $this->enroll($owner);
        $this->postJson('/api/admin/login', ['password' => 'test-wachtwoord-123'])->assertStatus(422)->assertJsonPath('error', 'two_factor_required');
        $this->postJson('/api/admin/login', ['password' => 'test-wachtwoord-123', 'code' => $this->totp($secret, 1)])->assertOk();

        $ownerAgain = $this->postJson('/api/admin/login', ['password' => 'test-wachtwoord-123', 'code' => $ownerCodes[0]])->json('token');          // de TOTP-stap is net gebruikt: nu een herstelcode
        $this->assertNotNull($ownerAgain);
        $this->postJson("/api/admin/team/$id/reset-2fa", [], $this->h($ownerAgain))->assertOk()->assertJsonPath('twoFactor', false);
        $this->postJson('/api/admin/login', ['email' => $email, 'password' => self::PW])->assertOk();
    }

    public function test_required_two_factor_blocks_everything_until_it_is_set_up(): void
    {
        [, $token] = $this->member('support', 'hans@example.nl');
        config(['shop.require_2fa' => true]);
        $this->getJson('/api/admin/orders', $this->h($token))->assertForbidden()->assertJsonPath('error', 'two_factor_required');
        $this->getJson('/api/admin/status', $this->h($token))->assertForbidden();
        $this->getJson('/api/admin/me', $this->h($token))->assertOk()->assertJsonPath('require2fa', true);   // wel bereikbaar om 2FA in te stellen
        $secret = $this->postJson('/api/admin/me/2fa/setup', [], $this->h($token))->assertOk()->json('secret');
        $this->postJson('/api/admin/me/2fa/enable', ['code' => $this->totp($secret)], $this->h($token))->assertOk();
        $this->getJson('/api/admin/orders', $this->h($token))->assertOk();
        $this->postJson('/api/admin/me/2fa/disable', ['password' => self::PW, 'code' => $this->totp($secret, 1)], $this->h($token))->assertStatus(422); // niet uitzetten als verplicht
    }

    public function test_artisan_command_resets_two_factor_in_an_emergency(): void
    {
        [$email, $token] = $this->member('support', 'ine@example.nl');
        $this->enroll($token);
        $this->artisan('staff:reset-2fa', ['email' => 'INE@example.nl'])->assertExitCode(0);
        $this->postJson('/api/admin/login', ['email' => $email, 'password' => self::PW])->assertOk();
        $this->artisan('staff:reset-2fa', ['email' => 'niemand@example.nl'])->assertExitCode(1);
        $this->assertTrue(AdminAuditLog::where('action', '2FA gereset via server')->exists());
    }

    /* ---------------- Logboek ---------------- */

    public function test_audit_log_records_logins_and_changes_but_never_secrets(): void
    {
        $this->postJson('/api/admin/login', ['password' => 'fout-wachtwoord-x'])->assertStatus(401);
        [$email, $token] = $this->member('manager', 'jan@example.nl');
        $this->putJson('/api/admin/settings/automations', ['value' => []], $this->h($token))->assertOk();
        $this->getJson('/api/admin/orders', $this->h($token))->assertOk();                                    // lezen wordt niet gelogd

        $log = $this->getJson('/api/admin/team/audit', $this->h($this->owner()))->assertOk()->json('log');
        $actions = array_column($log, 'action');
        $this->assertContains('login mislukt', $actions);
        $this->assertContains('login', $actions);
        $this->assertContains('medewerker toegevoegd', $actions);
        $this->assertContains('PUT api/admin/settings/{key}', $actions);
        $this->assertNotContains('GET api/admin/orders', $actions);
        $json = json_encode($log);
        $this->assertStringNotContainsString(self::PW, $json);
        $this->assertStringNotContainsString('test-wachtwoord-123', $json);
        $this->assertStringContainsString('jan@example.nl', $json);
        $this->getJson('/api/admin/team/audit', $this->h($token))->assertForbidden();                         // alleen de eigenaar
    }

    public function test_audit_log_is_pruned_after_twelve_months(): void
    {
        AdminAuditLog::record(null, 'oud');
        AdminAuditLog::record(null, 'nieuw');
        AdminAuditLog::where('action', 'oud')->update(['created_at' => now()->subMonths(13)]);
        Artisan::call('shop:gdpr-retention');
        $this->assertSame(['nieuw'], AdminAuditLog::pluck('action')->all());
    }
}
