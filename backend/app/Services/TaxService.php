<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Setting;
use Illuminate\Support\Carbon;

/** Btw-overzicht per kwartaal: Nederland, OSS (EU) en export. Indicatief, geen belastingadvies. */
class TaxService
{
    public function summary(int $year, int $quarter): array
    {
        $rates = config('shop.vat_rates');
        $useOss = Setting::get('tax.oss', true) !== false;
        $periodKey = $year.'-'.($quarter ?: 'jaar');
        $inputVat = (float) Setting::get('tax.input.'.$periodKey, 0);

        [$from, $to] = $quarter
            ? [Carbon::create($year, ($quarter - 1) * 3 + 1, 1)->startOfDay(), Carbon::create($year, ($quarter - 1) * 3 + 1, 1)->addMonths(3)->startOfDay()]
            : [Carbon::create($year, 1, 1)->startOfDay(), Carbon::create($year + 1, 1, 1)->startOfDay()];

        $lineFor = function (Order $o, bool $oss) use ($rates) {
            $c = $o->country;
            if ($c === 'NL') {
                [$regime, $rate] = ['nl', 21.0];
            } elseif (isset($rates[$c]) && $oss) {
                [$regime, $rate] = ['oss', (float) $rates[$c]];
            } elseif (isset($rates[$c])) {
                [$regime, $rate] = ['nl', 21.0];
            } else {
                [$regime, $rate] = ['export', 0.0];
            }
            if ($o->tax_amount !== null) {
                // Werkelijk door Stripe Tax berekende btw gebruiken in plaats van een schatting met vaste tarieven.
                $excl = $o->total - $o->tax_amount;
                $rate = $excl > 0 ? round($o->tax_amount / $excl * 100, 2) : 0.0;
            } else {
                $excl = $o->total / (1 + $rate / 100);
            }

            return ['o' => $o, 'country' => $c, 'regime' => $regime, 'rate' => $rate, 'incl' => $o->total, 'excl' => $excl, 'vat' => $o->total - $excl];
        };

        $lines = Order::where('status', 'paid')->where('paid_at', '>=', $from)->where('paid_at', '<', $to)->orderBy('paid_at')->get()
            ->map(fn ($o) => $lineFor($o, $useOss));
        $yearOrders = Order::where('status', 'paid')->whereYear('paid_at', $year)->get();
        $crossBorder = $yearOrders->map(fn ($o) => $lineFor($o, true))->where('regime', 'oss')->sum('excl');

        $rows = $lines->groupBy(fn ($l) => $l['country'].'|'.$l['regime'])->map(fn ($g) => [
            'country' => $g[0]['country'], 'regime' => $g[0]['regime'], 'rate' => $g[0]['rate'], 'orders' => $g->count(),
            'incl' => round($g->sum('incl'), 2), 'excl' => round($g->sum('excl'), 2), 'vat' => round($g->sum('vat'), 2),
        ])->sortByDesc('incl')->values();

        $nl = $lines->where('regime', 'nl');
        $oss = $lines->where('regime', 'oss');
        $years = Order::selectRaw('DISTINCT EXTRACT(YEAR FROM paid_at)::int AS y')->whereNotNull('paid_at')->pluck('y')->push((int) date('Y'))->unique()->sortDesc()->values();

        return [
            'year' => $year,
            'quarter' => $quarter,
            'years' => $years,
            'useOss' => $useOss,
            'inputVat' => $inputVat,
            'rows' => $rows,
            'totals' => ['incl' => round($lines->sum('incl'), 2), 'excl' => round($lines->sum('excl'), 2), 'vat' => round($lines->sum('vat'), 2), 'orders' => $lines->count()],
            'nl' => ['excl' => round($nl->sum('excl'), 2), 'vat' => round($nl->sum('vat'), 2), 'pay' => round($nl->sum('vat') - $inputVat, 2)],
            'oss' => ['vat' => round($oss->sum('vat'), 2), 'orders' => $oss->count()],
            'export' => ['incl' => round($lines->where('regime', 'export')->sum('incl'), 2)],
            'crossBorderYear' => round($crossBorder, 2),
            'threshold' => config('shop.oss_threshold'),
            'deadlines' => collect($quarter ? [$quarter] : [1, 2, 3, 4])->map(fn ($q) => [
                'quarter' => $q, 'date' => Carbon::create($year, 1, 1)->addMonths($q * 3)->endOfMonth()->toDateString(), // laatste dag van de maand na het kwartaal
            ])->values(),
            'lines' => $lines->map(fn ($l) => [
                'order' => $l['o']->order_no, 'date' => $l['o']->paid_at?->toIso8601String(), 'customer' => $l['o']->customer_name, 'country' => $l['country'],
                'regime' => $l['regime'], 'rate' => $l['rate'], 'incl' => round($l['incl'], 2), 'excl' => round($l['excl'], 2), 'vat' => round($l['vat'], 2), 'cost' => $l['o']->cost,
            ])->values(),
        ];
    }
}
