<?php

namespace App\Http\Controllers;

use App\Services\ProductCsv;
use App\Services\ProductFeed;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/** Producten in bulk (CSV export/import met voorbeeld) en de productfeed voor advertentieplatforms. */
class ProductExchangeController extends Controller
{
    public function export(Request $request, ProductCsv $csv)
    {
        $sep = ['comma' => ',', 'tab' => "\t"][(string) $request->query('sep')] ?? ';';

        return response($csv->export($sep), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="rebazz-producten-'.now()->format('Y-m-d').'.csv"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /**
     * Import in twee stappen met hetzelfde bestand: eerst `dry_run=1` (voorbeeld, schrijft niets), daarna zonder dry_run met de `checksum` uit het voorbeeld.
     * Alles of niets: bij een fout wordt niets opgeslagen.
     */
    public function import(Request $request, ProductCsv $csv): JsonResponse
    {
        $request->validate(['file' => ['required', 'file', 'max:2048', 'mimes:csv,txt'], 'dry_run' => ['sometimes', 'boolean'], 'checksum' => ['nullable', 'string', 'size:64']]);
        $content = (string) file_get_contents($request->file('file')->getRealPath());
        $checksum = hash('sha256', $content);
        try {
            $plan = $csv->plan($csv->parse($content));
        } catch (InvalidArgumentException $e) {
            return response()->json(['error' => 'invalid_file', 'message' => $e->getMessage()], 422);
        }
        $trim = fn (array $items) => array_map(fn ($i) => array_filter($i, fn ($k) => $k[0] !== '_', ARRAY_FILTER_USE_KEY), $items);
        $out = ['summary' => $plan['summary'], 'checksum' => $checksum, 'errors' => array_slice($plan['errors'], 0, 100), 'errorCount' => count($plan['errors']), 'items' => array_slice($trim($plan['items']), 0, 300)];

        if ($plan['errors']) {
            return response()->json(['error' => 'has_errors', 'message' => 'Het bestand bevat fouten; er is niets opgeslagen.'] + $out, 422);
        }
        if ($request->boolean('dry_run')) {
            return response()->json(['dryRun' => true] + $out);
        }
        if (! hash_equals($checksum, (string) $request->input('checksum'))) {
            return response()->json(['error' => 'not_previewed', 'message' => 'Bekijk eerst het voorbeeld van exact dit bestand voordat u importeert.'], 409);
        }
        $csv->apply($plan);

        return response()->json(['applied' => true] + $out);
    }

    /** Controle voor het beheer: feed-adres, aantal producten in de feed en wat er is overgeslagen. */
    public function feedCheck(ProductFeed $feed): JsonResponse
    {
        $b = $feed->build();

        return response()->json(['url' => $feed->url(), 'items' => count($b['items']), 'excluded' => $b['excluded'], 'https' => str_starts_with($feed->url(), 'https://')]);
    }
}
