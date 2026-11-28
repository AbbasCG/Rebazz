<?php

namespace App\Services;

use App\Models\User;

/**
 * Tweestapsverificatie met TOTP (RFC 6238: HMAC-SHA1, 6 cijfers, 30 seconden) en eenmalige herstelcodes.
 * Werkt met elke authenticator-app (Google Authenticator, Microsoft Authenticator, 1Password, Authy, ...).
 */
class TwoFactorService
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    public const PERIOD = 30;

    public function newSecret(): string
    {
        return $this->base32Encode(random_bytes(20));
    }

    /** otpauth-adres voor authenticator-apps (kan ook als QR-code worden getoond of handmatig worden ingevoerd). */
    public function uri(User $user, string $secret): string
    {
        $issuer = (string) config('shop.brand.name');

        return 'otpauth://totp/'.rawurlencode($issuer.':'.$user->email).'?secret='.$secret.'&issuer='.rawurlencode($issuer).'&algorithm=SHA1&digits=6&period='.self::PERIOD;
    }

    private function base32Encode(string $bin): string
    {
        $bits = '';
        foreach (str_split($bin) as $c) {
            $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::ALPHABET[bindec(str_pad($chunk, 5, '0', STR_PAD_RIGHT))];
        }

        return $out;
    }

    private function base32Decode(string $s): string
    {
        $bits = '';
        foreach (str_split(strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $s))) as $c) {
            $bits .= str_pad(decbin((int) strpos(self::ALPHABET, $c)), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr(bindec($byte));
            }
        }

        return $out;
    }

    /** De code voor een bepaalde stap (tijd / 30 s). */
    public function codeAt(string $secret, int $step): string
    {
        $hash = hash_hmac('sha1', pack('J', $step), $this->base32Decode($secret), true);
        $o = ord($hash[19]) & 0xF;
        $n = ((ord($hash[$o]) & 0x7F) << 24) | (ord($hash[$o + 1]) << 16) | (ord($hash[$o + 2]) << 8) | ord($hash[$o + 3]);

        return str_pad((string) ($n % 1000000), 6, '0', STR_PAD_LEFT);
    }

    /** Controleert een code (±1 stap tolerantie voor klokverschil). Geeft de gebruikte stap terug, of null. Een stap die al gebruikt is werkt niet opnieuw. */
    public function verifyTotp(string $secret, string $code, ?int $lastStep = null, ?int $now = null): ?int
    {
        $code = preg_replace('/\s+/', '', $code);
        if (! preg_match('/^\d{6}$/', $code)) {
            return null;
        }
        $current = intdiv($now ?? time(), self::PERIOD);
        for ($step = $current - 1; $step <= $current + 1; $step++) {
            if (hash_equals($this->codeAt($secret, $step), $code) && ($lastStep === null || $step > $lastStep)) {
                return $step;
            }
        }

        return null;
    }

    /** Controleert een code van de gebruiker en onthoudt de stap (tegen herhaald gebruik). */
    public function verifyUser(User $user, string $code): bool
    {
        if (! $user->hasTwoFactor()) {
            return false;
        }
        $step = $this->verifyTotp((string) $user->two_factor_secret, $code, $user->two_factor_last_step);
        if ($step === null) {
            return false;
        }
        $user->forceFill(['two_factor_last_step' => $step])->save();

        return true;
    }

    /* ---------------- Herstelcodes ---------------- */

    private function hashRecovery(string $code): string
    {
        return hash_hmac('sha256', strtolower(preg_replace('/[^a-z0-9]/i', '', $code)), (string) config('app.key'));
    }

    /** Maakt 8 nieuwe herstelcodes (eerdere vervallen). De codes worden alleen nu getoond; bewaard worden alleen hashes. @return string[] */
    public function regenerateRecovery(User $user): array
    {
        $codes = [];
        for ($i = 0; $i < 8; $i++) {
            $codes[] = substr(bin2hex(random_bytes(5)), 0, 5).'-'.substr(bin2hex(random_bytes(5)), 0, 5);
        }
        $user->forceFill(['two_factor_recovery' => json_encode(array_map(fn ($c) => $this->hashRecovery($c), $codes))])->save();

        return $codes;
    }

    public function recoveryLeft(User $user): int
    {
        return count((array) json_decode((string) $user->two_factor_recovery, true));
    }

    /** Gebruikt een herstelcode (eenmalig). */
    public function consumeRecovery(User $user, string $code): bool
    {
        $hash = $this->hashRecovery($code);
        $list = (array) json_decode((string) $user->two_factor_recovery, true);
        foreach ($list as $i => $h) {
            if (hash_equals((string) $h, $hash)) {
                unset($list[$i]);
                $user->forceFill(['two_factor_recovery' => json_encode(array_values($list))])->save();

                return true;
            }
        }

        return false;
    }

    /** TOTP-code óf herstelcode. */
    public function check(User $user, string $code): bool
    {
        $code = trim($code);

        return preg_match('/^\s*\d[\d\s]{5,7}$/', $code) ? $this->verifyUser($user, $code) : $this->consumeRecovery($user, $code);
    }

    public function disable(User $user): void
    {
        $user->forceFill(['two_factor_secret' => null, 'two_factor_pending' => null, 'two_factor_enabled_at' => null, 'two_factor_recovery' => null, 'two_factor_last_step' => null])->save();
    }
}
