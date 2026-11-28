<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Product;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Eigen, privacyvriendelijke statistieken (zonder derden). De winkel stuurt alleen gebeurtenissen na toestemming voor "analytisch";
 * wij bewaren geen IP-adres, user-agent of e-mailadres. Rapport: bezoekers, funnel, producten, bronnen en apparaten.
 */
class AnalyticsService
{
    public const TYPES = ['page', 'product', 'cart', 'checkout', 'purchase'];

    /** Slaat één gebeurtenis op. Beheerpagina's en onbruikbare gegevens worden stilzwijgend overgeslagen. */
    public function record(array $d): bool
    {
        $path = '/'.ltrim((string) strtok((string) strtok($d['path'], '?'), '#'), '/');
        if (str_starts_with($path, '/beheer') || $path === '') {
            return false;
        }
        $own = parse_url((string) config('shop.frontend_url'), PHP_URL_HOST);
        $ref = null;
        if (! empty($d['ref'])) {
            $host = strtolower((string) (parse_url(str_contains($d['ref'], '://') ? $d['ref'] : 'https://'.$d['ref'], PHP_URL_HOST) ?: ''));
            $ref = ($host && $host !== $own && preg_match('/^[a-z0-9.-]+$/', $host)) ? mb_substr(preg_replace('/^www\./', '', $host), 0, 100) : null;
        }
        $clean = fn ($v, int $n) => ($v === null || $v === '') ? null : mb_substr(strtolower(trim(strip_tags((string) $v))), 0, $n);
        DB::table('analytics_events')->insert([
            'vid' => $d['vid'], 'sid' => $d['sid'], 'type' => $d['type'], 'path' => mb_substr($path, 0, 150),
            'product_id' => $d['product_id'] ?? null, 'ref' => $ref,
            'src' => $clean($d['src'] ?? null, 60), 'med' => $clean($d['med'] ?? null, 60), 'camp' => $clean($d['camp'] ?? null, 80),
            'device' => $d['device'] ?? null, 'value' => $d['type'] === 'purchase' ? ($d['value'] ?? null) : null, 'created_at' => now(),
        ]);

        return true;
    }

    /** Wist alles van één (anonieme) bezoeker-id: gebeurt als de bezoeker toestemming intrekt. */
    public function forget(string $vid): int
    {
        return DB::table('analytics_events')->where('vid', $vid)->delete();
    }

    /** Bewaartermijn: standaard 13 maanden (richtlijn voor analytische gegevens). */
    public function prune(): int
    {
        return DB::table('analytics_events')->where('created_at', '<', now()->subMonths(max(1, (int) config('shop.analytics_months'))))->delete();
    }

    public function report(int $days): array
    {
        $from = now()->subDays($days - 1)->startOfDay();
        $ev = fn () => DB::table('analytics_events')->where('created_at', '>=', $from);

        // Per dag
        $byDay = $ev()->selectRaw("date(created_at) as d, count(distinct vid) as visitors, sum(case when type = 'page' then 1 else 0 end) as pageviews")->groupByRaw('date(created_at)')->get()->keyBy('d');
        $series = [];
        for ($i = 0; $i < $days; $i++) {
            $day = $from->copy()->addDays($i)->toDateString();
            $series[] = ['date' => $day, 'visitors' => (int) ($byDay[$day]->visitors ?? 0), 'pageviews' => (int) ($byDay[$day]->pageviews ?? 0)];
        }

        // Funnel: unieke bezoekers per stap
        $step = fn (string $type) => (int) $ev()->where('type', $type)->distinct()->count('vid');
        $funnel = [
            'visitors' => (int) $ev()->distinct()->count('vid'), 'product' => $step('product'), 'cart' => $step('cart'),
            'checkout' => $step('checkout'), 'purchase' => $step('purchase'),
        ];
        $purchases = $ev()->where('type', 'purchase');
        $tracked = ['purchases' => (int) (clone $purchases)->count(), 'revenue' => round((float) (clone $purchases)->sum('value'), 2)];

        // Producten
        $prod = $ev()->whereNotNull('product_id')->whereIn('type', ['product', 'cart'])
            ->selectRaw("product_id, sum(case when type = 'product' then 1 else 0 end) as views, sum(case when type = 'cart' then 1 else 0 end) as carts")
            ->groupBy('product_id')->orderByDesc('views')->limit(10)->get();
        $names = Product::whereIn('id', $prod->pluck('product_id'))->pluck('name', 'id');
        $products = $prod->map(fn ($p) => ['id' => $p->product_id, 'name' => $names[$p->product_id] ?? $p->product_id, 'views' => (int) $p->views, 'carts' => (int) $p->carts])->values();

        // Pagina's
        $pages = $ev()->where('type', 'page')->selectRaw('path, count(*) as views, count(distinct vid) as visitors')->groupBy('path')->orderByDesc('views')->limit(10)->get()
            ->map(fn ($p) => ['path' => $p->path, 'views' => (int) $p->views, 'visitors' => (int) $p->visitors])->values();

        // Per sessie: bron, campagne en apparaat (alle gebeurtenissen van een sessie dragen dezelfde herkomst)
        $sessions = $ev()->selectRaw("sid, max(src) as src, max(ref) as ref, max(med) as med, max(camp) as camp, max(device) as device, sum(case when type = 'purchase' then 1 else 0 end) as purchases, sum(case when type = 'purchase' then value else 0 end) as revenue")
            ->groupBy('sid')->limit(50000)->get();
        $sources = [];
        $campaigns = [];
        $devices = [];
        foreach ($sessions as $s) {
            $label = $s->src ?: ($s->ref ?: '(direct)');
            $sources[$label] ??= ['source' => $label, 'sessions' => 0, 'purchases' => 0, 'revenue' => 0.0];
            $sources[$label]['sessions']++;
            $sources[$label]['purchases'] += (int) $s->purchases;
            $sources[$label]['revenue'] += (float) $s->revenue;
            if ($s->camp) {
                $key = $s->camp.'|'.$s->src.'|'.$s->med;
                $campaigns[$key] ??= ['campaign' => $s->camp, 'source' => $s->src, 'medium' => $s->med, 'sessions' => 0, 'purchases' => 0, 'revenue' => 0.0];
                $campaigns[$key]['sessions']++;
                $campaigns[$key]['purchases'] += (int) $s->purchases;
                $campaigns[$key]['revenue'] += (float) $s->revenue;
            }
            $dev = $s->device ?: 'unknown';
            $devices[$dev] = ($devices[$dev] ?? 0) + 1;
        }
        $top = fn (array $rows) => collect($rows)->map(fn ($r) => isset($r['revenue']) ? ['revenue' => round($r['revenue'], 2)] + $r : $r)->sortByDesc('sessions')->take(10)->values();

        $orders = Order::whereNull('anonymized_at')->whereIn('status', ['paid', 'refunded'])->where('paid_at', '>=', $from);

        return [
            'days' => $days, 'from' => $from->toDateString(),
            'totals' => ['visitors' => $funnel['visitors'], 'sessions' => $sessions->count(), 'pageviews' => (int) $ev()->where('type', 'page')->count()],
            'series' => $series, 'funnel' => $funnel, 'tracked' => $tracked,
            'orders' => ['count' => (int) (clone $orders)->count(), 'revenue' => round((float) (clone $orders)->sum('total'), 2)], // alle bestellingen, ook van bezoekers zonder toestemming
            'products' => $products, 'pages' => $pages, 'sources' => $top($sources), 'campaigns' => $top($campaigns),
            'devices' => collect($devices)->map(fn ($n, $d) => ['device' => $d, 'sessions' => $n])->sortByDesc('sessions')->values(),
            'retentionMonths' => (int) config('shop.analytics_months'),
        ];
    }
}
