<?php

namespace App\Http\Controllers;

use App\Mail\PrivacyRequestMail;
use App\Models\NewsletterSubscriber;
use App\Models\Order;
use App\Models\PrivacyRequest;
use App\Services\PrivacyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Throwable;

/**
 * Privacyverzoeken (AVG): de klant vraagt inzage of wissen aan met alleen een e-mailadres; wij sturen een tijdelijke, ondertekende link
 * naar dat adres. Alleen wie de mailbox beheert kan het verzoek afronden (identiteitscontrole zonder klantaccount).
 */
class PrivacyController extends Controller
{
    /** Altijd hetzelfde antwoord: zo is niet te achterhalen of een e-mailadres bij ons bekend is. */
    public function request(Request $request, PrivacyService $privacy): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email:rfc', 'max:200'], 'type' => ['required', 'in:export,erase']]);
        $email = strtolower(trim($data['email']));
        $answer = response()->json(['ok' => true]);

        // Per adres maximaal 3 verzoeken per uur (voorkomt dat iemand andermans mailbox volspamt).
        if (! RateLimiter::attempt('privacy:'.PrivacyRequest::hashEmail($email), 3, fn () => true, 3600)) {
            return $answer;
        }
        if (! $privacy->hasData($email)) {
            return $answer;
        }
        $signed = $data['type'] === 'export'
            ? URL::temporarySignedRoute('privacy.export', now()->addHour(), ['email' => $email])
            : $this->erasePageUrl(URL::temporarySignedRoute('privacy.erase', now()->addHour(), ['email' => $email]));
        $lang = Order::whereRaw('lower(email) = ?', [$email])->orderByDesc('id')->value('lang')
            ?? NewsletterSubscriber::whereRaw('lower(email) = ?', [$email])->value('lang') ?? 'nl';
        try {
            Mail::to($email)->send(new PrivacyRequestMail($data['type'], $signed, $lang));
            $privacy->log($email, $data['type'], 'customer', [], false);
        } catch (Throwable $e) {
            Log::error('Privacy-verificatiemail mislukt', ['error' => $e->getMessage()]);
        }

        return $answer;
    }

    /** De bevestigingspagina van de winkel krijgt dezelfde ondertekende query (email, expires, signature) en stuurt die mee naar de API. */
    private function erasePageUrl(string $apiUrl): string
    {
        return rtrim((string) config('shop.frontend_url'), '/').'/privacy-verzoek/bevestigen?'.parse_url($apiUrl, PHP_URL_QUERY);
    }

    public function export(Request $request, PrivacyService $privacy)
    {
        $email = (string) $request->query('email');
        $data = $privacy->export($email);
        $privacy->log($email, 'export', 'customer', ['orders' => count($data['orders'])]);

        return response()->json($data, 200, [
            'Content-Disposition' => 'attachment; filename="mijn-gegevens.json"',
            'Cache-Control' => 'private, no-store',
            'X-Robots-Tag' => 'noindex, nofollow',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function erase(Request $request, PrivacyService $privacy): JsonResponse
    {
        $email = (string) $request->query('email');
        $result = $privacy->erase($email);
        $privacy->log($email, 'erase', 'customer', ['newsletter_deleted' => $result['newsletter_deleted'], 'minimized' => $result['orders_minimized'], 'anonymized' => $result['orders_anonymized']]);

        return response()->json($result);
    }

    /** Afmelden voor de nieuwsbrief via een ondertekende link (geen e-mailverificatie nodig: de link komt uit een mail aan dat adres). */
    public function unsubscribe(Request $request)
    {
        NewsletterSubscriber::whereRaw('lower(email) = ?', [strtolower((string) $request->query('email'))])->delete();

        return redirect(rtrim((string) config('shop.frontend_url'), '/').'/?newsletter=unsubscribed');
    }

    /* ---------------- Beheer ---------------- */

    public function lookup(Request $request, PrivacyService $privacy): JsonResponse
    {
        $email = strtolower(trim((string) $request->validate(['email' => ['required', 'email:rfc', 'max:200']])['email']));
        $orders = Order::whereRaw('lower(email) = ?', [$email])->orderBy('id')->get();

        return response()->json([
            'email' => $email,
            'summary' => $privacy->summary($email),
            'orders' => $orders->map(fn (Order $o) => [
                'id' => $o->order_no, 'date' => $o->paid_at?->toIso8601String(), 'total' => $o->total, 'status' => $o->status,
                'anonymized' => (bool) $o->anonymized_at, 'retainedUntil' => $privacy->retainUntil($o)->toDateString(),
            ])->values(),
            'retentionYears' => $privacy->retentionYears(),
        ]);
    }

    public function adminExport(Request $request, PrivacyService $privacy)
    {
        $email = (string) $request->validate(['email' => ['required', 'email:rfc', 'max:200']])['email'];
        $data = $privacy->export($email);
        $privacy->log($email, 'export', 'admin', ['orders' => count($data['orders'])]);

        return response()->json($data, 200, ['Content-Disposition' => 'attachment; filename="gegevens.json"', 'Cache-Control' => 'private, no-store'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function adminErase(Request $request, PrivacyService $privacy): JsonResponse
    {
        $email = (string) $request->validate(['email' => ['required', 'email:rfc', 'max:200']])['email'];
        $result = $privacy->erase($email);
        $privacy->log($email, 'erase', 'admin', ['newsletter_deleted' => $result['newsletter_deleted'], 'minimized' => $result['orders_minimized'], 'anonymized' => $result['orders_anonymized']]);

        return response()->json($result);
    }

    public function log(): JsonResponse
    {
        return response()->json(['requests' => PrivacyRequest::orderByDesc('id')->limit(100)->get()->map(fn ($r) => [
            'id' => $r->id, 'who' => substr($r->email_hash, 0, 8), 'type' => $r->type, 'source' => $r->source, 'status' => $r->status,
            'requestedAt' => $r->requested_at?->toIso8601String(), 'completedAt' => $r->completed_at?->toIso8601String(), 'result' => $r->result,
        ])->values()]);
    }
}
