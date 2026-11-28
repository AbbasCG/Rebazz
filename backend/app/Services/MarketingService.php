<?php

namespace App\Services;

use App\Mail\AbandonedCartMail;
use App\Mail\NewsletterMail;
use App\Models\AbandonedCart;
use App\Models\NewsletterCampaign;
use App\Models\NewsletterDelivery;
use App\Models\Order;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Marketing met toestemming.
 * - Verlaten winkelwagen: Stripe vraagt in de kassa zelf om toestemming (consent.promotions) en maakt na het verlopen van de sessie een
 *   herstel-link. Alleen met "opt_in" bewaren we e-mail + link en sturen we maximaal één herinnering.
 * - Nieuwsbrief: alleen naar bevestigde abonnees (dubbele opt-in), in kleine porties per minuut, nooit dubbel naar hetzelfde adres.
 */
class MarketingService
{
    public const CART_WINDOW_HOURS = 48;   // herinneringen alleen kort na het verlaten van de kassa
    public const BATCH = 50;               // nieuwsbrieven per scheduler-ronde (elke minuut)

    public function cartRemindersEnabled(): bool
    {
        return (bool) config('shop.abandoned_cart');
    }

    /** Verwerkt een checkout.session.expired-gebeurtenis. Geeft de vastgelegde herinnering terug, of null als er geen toestemming/gegevens zijn. */
    public function recordExpiredSession(array $s): ?AbandonedCart
    {
        $email = strtolower(trim((string) ($s['customer_details']['email'] ?? $s['customer_email'] ?? '')));
        $url = (string) ($s['after_expiration']['recovery']['url'] ?? '');
        if (! $this->cartRemindersEnabled() || ($s['consent']['promotions'] ?? null) !== 'opt_in'
            || ! filter_var($email, FILTER_VALIDATE_EMAIL) || ! str_starts_with($url, 'https://') || empty($s['id'])) {
            return null;
        }
        $lang = (string) ($s['metadata']['lang'] ?? 'nl');

        return AbandonedCart::firstOrCreate(['session_id' => (string) $s['id']], [
            'email' => $email, 'lang' => substr($lang, 0, 5), 'recovery_url' => $url,
            'checkout_started_at' => isset($s['created']) ? \Illuminate\Support\Carbon::createFromTimestamp((int) $s['created']) : now(),
        ]);
    }

    /** Verstuurt openstaande herinneringen (één per verlaten kassa). Geeft het aantal verstuurde mails terug. */
    public function sendCartReminders(): int
    {
        if (! $this->cartRemindersEnabled()) {
            return 0;
        }
        $sent = 0;
        AbandonedCart::whereNull('sent_at')->whereNotNull('recovery_url')->where('created_at', '>', now()->subHours(self::CART_WINDOW_HOURS))->orderBy('id')->limit(100)->get()
            ->each(function (AbandonedCart $c) use (&$sent) {
                // Heeft de klant intussen toch besteld, dan geen herinnering meer.
                $ordered = Order::whereRaw('lower(email) = ?', [$c->email])->where('created_at', '>=', $c->checkout_started_at ?? $c->created_at)->exists();
                if ($ordered) {
                    $c->update(['sent_at' => now(), 'recovery_url' => null]);

                    return;
                }
                try {
                    Mail::to($c->email)->send(new AbandonedCartMail((string) $c->recovery_url, $c->lang));
                    $c->update(['sent_at' => now(), 'recovery_url' => null]); // de link is daarna niet meer nodig
                    $sent++;
                } catch (Throwable $e) {
                    Log::warning('Herinnering verlaten winkelwagen mislukt', ['session' => $c->session_id, 'error' => $e->getMessage()]);
                }
            });

        return $sent;
    }

    /** Eén ronde nieuwsbrieven versturen voor campagnes met status "sending". @return int aantal verzonden mails */
    public function sendNewsletterBatch(int $limit = self::BATCH): int
    {
        $sent = 0;
        foreach (NewsletterCampaign::where('status', 'sending')->orderBy('id')->get() as $campaign) {
            $room = $limit - $sent;
            if ($room <= 0) {
                break;
            }
            $done = NewsletterDelivery::where('campaign_id', $campaign->id)->select('email');
            $todo = $campaign->audience()->whereNotIn(\Illuminate\Support\Facades\DB::raw('lower(email)'), $done)->orderBy('id')->limit($room)->get(['email', 'lang']);
            foreach ($todo as $sub) {
                $email = strtolower($sub->email);
                // Eerst "claimen" (unieke sleutel), dan versturen: ook bij twee gelijktijdige processen gaat er nooit dubbel een mail uit.
                if (! NewsletterDelivery::insertOrIgnore(['campaign_id' => $campaign->id, 'email' => $email, 'ok' => true, 'created_at' => now()])) {
                    continue;
                }
                try {
                    Mail::to($email)->send(new NewsletterMail($campaign, $email, $sub->lang ?: 'nl'));
                    $campaign->increment('sent_count');
                    $sent++;
                } catch (Throwable $e) {
                    NewsletterDelivery::where('campaign_id', $campaign->id)->where('email', $email)->update(['ok' => false]);
                    $campaign->increment('failed_count');
                    Log::warning('Nieuwsbrief mislukt', ['campaign' => $campaign->id, 'error' => $e->getMessage()]);
                }
            }
            if ($todo->count() < $room && $campaign->audience()->whereNotIn(\Illuminate\Support\Facades\DB::raw('lower(email)'), $done)->doesntExist()) {
                $campaign->update(['status' => 'sent', 'finished_at' => now()]);
            }
        }

        return $sent;
    }

    /** Opruimen: herinneringen na 30 dagen, afleverlogs van verstuurde campagnes na 90 dagen. */
    public function prune(): array
    {
        $carts = AbandonedCart::where('created_at', '<', now()->subDays(30))->delete();
        $deliveries = NewsletterDelivery::whereIn('campaign_id', NewsletterCampaign::where('status', 'sent')->where('finished_at', '<', now()->subDays(90))->select('id'))->delete();

        return ['carts' => $carts, 'deliveries' => $deliveries];
    }
}
