<?php

namespace App\Http\Controllers;

use App\Services\AnalyticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Statistieken met toestemming: ontvangen van gebeurtenissen uit de winkel, vergeten bij intrekken van toestemming, en het rapport voor het beheer. */
class AnalyticsController extends Controller
{
    /** "Do Not Track" en Global Privacy Control respecteren we ook als de bezoeker toestemming gaf: dan wordt niets bewaard. */
    private function optedOut(Request $r): bool
    {
        return $r->header('Sec-GPC') === '1' || $r->header('DNT') === '1';
    }

    public function collect(Request $request): Response|JsonResponse
    {
        if ($this->optedOut($request)) {
            return response()->noContent();
        }
        $d = $request->validate([
            'vid' => ['required', 'regex:/^[A-Za-z0-9-]{16,40}$/'],
            'sid' => ['required', 'regex:/^[A-Za-z0-9-]{8,40}$/'],
            'type' => ['required', 'in:'.implode(',', AnalyticsService::TYPES)],
            'path' => ['required', 'string', 'max:300'],
            'product_id' => ['nullable', 'regex:/^[A-Za-z0-9_-]{1,40}$/'],
            'ref' => ['nullable', 'string', 'max:200'],
            'src' => ['nullable', 'string', 'max:100'], 'med' => ['nullable', 'string', 'max:100'], 'camp' => ['nullable', 'string', 'max:120'],
            'device' => ['nullable', 'in:mobile,tablet,desktop'],
            'value' => ['nullable', 'numeric', 'min:0', 'max:100000'],
        ]);
        app(AnalyticsService::class)->record($d);

        return response()->noContent();
    }

    /** Bezoeker trekt toestemming in: alles wat bij zijn willekeurige id hoort wordt gewist. */
    public function forget(Request $request): Response
    {
        $vid = (string) $request->validate(['vid' => ['required', 'regex:/^[A-Za-z0-9-]{16,40}$/']])['vid'];
        app(AnalyticsService::class)->forget($vid);

        return response()->noContent();
    }

    public function report(Request $request): JsonResponse
    {
        $days = (int) $request->query('days', 30);

        return response()->json(app(AnalyticsService::class)->report(in_array($days, [7, 30, 90], true) ? $days : 30));
    }
}
