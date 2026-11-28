<?php

namespace App\Http\Controllers;

use App\Mail\NewsletterMail;
use App\Models\NewsletterCampaign;
use App\Models\NewsletterSubscriber;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Throwable;

/** Beheer van nieuwsbrief-campagnes: concept opstellen, testmail, versturen aan bevestigde abonnees (in porties via de scheduler). */
class NewsletterController extends Controller
{
    private const LANGS = ['nl', 'en', 'de', 'fr', 'es', 'it', 'pl', 'ar'];

    private function rules(): array
    {
        return [
            'subject' => ['required', 'string', 'max:150'],
            'body' => ['required', 'string', 'max:10000'],
            'cta_label' => ['nullable', 'string', 'max:60'],
            'cta_url' => ['nullable', 'url:https', 'max:500', 'required_with:cta_label'],
            'lang' => ['nullable', 'in:'.implode(',', self::LANGS)],
        ];
    }

    private function shape(NewsletterCampaign $c): array
    {
        return [
            'id' => $c->id, 'subject' => $c->subject, 'body' => $c->body, 'cta_label' => $c->cta_label, 'cta_url' => $c->cta_url, 'lang' => $c->lang,
            'status' => $c->status, 'sent' => $c->sent_count, 'failed' => $c->failed_count,
            'audience' => $c->audience()->count(),
            'started_at' => $c->started_at?->toIso8601String(), 'finished_at' => $c->finished_at?->toIso8601String(), 'created_at' => $c->created_at?->toIso8601String(),
        ];
    }

    public function index(): JsonResponse
    {
        return response()->json([
            'campaigns' => NewsletterCampaign::orderByDesc('id')->limit(100)->get()->map(fn ($c) => $this->shape($c))->values(),
            'subscribers' => NewsletterSubscriber::confirmed()->count(),
            'byLang' => NewsletterSubscriber::confirmed()->selectRaw('lang, count(*) as n')->groupBy('lang')->pluck('n', 'lang'),
        ]);
    }

    private function clean(array $d): array
    {
        return [
            'subject' => trim(strip_tags($d['subject'])), 'body' => trim(strip_tags($d['body'])),
            'cta_label' => ($d['cta_label'] ?? null) ? trim(strip_tags($d['cta_label'])) : null, 'cta_url' => ($d['cta_url'] ?? null) ?: null,
            'lang' => ($d['lang'] ?? null) ?: null,
        ];
    }

    public function store(Request $request): JsonResponse
    {
        $c = NewsletterCampaign::create($this->clean($request->validate($this->rules())) + ['status' => 'draft']);

        return response()->json($this->shape($c), 201);
    }

    public function update(Request $request, NewsletterCampaign $campaign): JsonResponse
    {
        if ($campaign->status !== 'draft') {
            return response()->json(['error' => 'locked', 'message' => 'Een verstuurde of lopende nieuwsbrief kan niet meer worden aangepast.'], 422);
        }
        $campaign->update($this->clean($request->validate($this->rules())));

        return response()->json($this->shape($campaign));
    }

    public function destroy(NewsletterCampaign $campaign): JsonResponse
    {
        if ($campaign->status === 'sending') {
            return response()->json(['error' => 'locked', 'message' => 'Deze nieuwsbrief wordt nu verstuurd.'], 422);
        }
        $campaign->delete();

        return response()->json(['ok' => true]);
    }

    /** Testmail naar één adres (standaard het bedrijfsadres). Telt niet mee als verzending. */
    public function test(Request $request, NewsletterCampaign $campaign): JsonResponse
    {
        $to = strtolower((string) ($request->validate(['email' => ['nullable', 'email:rfc', 'max:200']])['email'] ?? Setting::brand()['email']));
        $preview = $campaign->replicate();
        $preview->subject = '[TEST] '.$campaign->subject;
        try {
            Mail::to($to)->send(new NewsletterMail($preview, $to, $campaign->lang ?: 'nl'));
        } catch (Throwable $e) {
            return response()->json(['error' => 'mail_failed', 'message' => 'Testmail verzenden mislukt: '.$e->getMessage()], 502);
        }

        return response()->json(['ok' => true, 'to' => $to]);
    }

    /** Start de verzending: de scheduler (elke minuut) verstuurt daarna porties tot alle bevestigde abonnees aan de beurt zijn geweest. */
    public function send(NewsletterCampaign $campaign): JsonResponse
    {
        if ($campaign->status !== 'draft') {
            return response()->json(['error' => 'locked', 'message' => 'Deze nieuwsbrief is al gestart.'], 422);
        }
        if ($campaign->audience()->count() === 0) {
            return response()->json(['error' => 'no_audience', 'message' => 'Er zijn geen bevestigde abonnees voor deze selectie.'], 422);
        }
        $campaign->update(['status' => 'sending', 'started_at' => now()]);

        return response()->json($this->shape($campaign));
    }
}
