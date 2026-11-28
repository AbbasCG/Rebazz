<?php

namespace App\Services;

use App\Http\Controllers\AdminController;
use App\Models\Product;
use InvalidArgumentException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Producten in bulk via CSV (Excel, Google Sheets).
 *
 * - Eén rij per product; varianten staan op extra rijen onder het product (kolommen variant_*), net als bij Shopify.
 * - Import werkt op id (of sku): bestaat het product, dan wordt het bijgewerkt; anders aangemaakt (als concept).
 * - Kolommen die ontbreken blijven ongemoeid, en een lege cel betekent "niet wijzigen": zo kan een sheet met alleen id, price en stock volstaan.
 *   Wilt u een veld leegmaken, zet dan [leeg] in de cel.
 * - Alles of niets: één foute rij en er wordt niets opgeslagen. Dezelfde validatieregels als het productformulier (ook GPSR).
 */
class ProductCsv
{
    public const PRODUCT_COLUMNS = [
        'id', 'sku', 'name', 'category', 'status', 'price', 'compare_price', 'cost', 'stock', 'track_stock', 'description', 'features', 'image', 'images',
        'options', 'manufacturer', 'eu_responsible', 'safety_info', 'warehouse', 'days_min', 'days_max', 'supplier', 'supplier_url', 'cj_vid', 'cj_from', 'cj_logistic', 'icon', 'hue',
    ];
    public const VARIANT_COLUMNS = ['variant_label', 'variant_values', 'variant_sku', 'variant_price', 'variant_cost', 'variant_stock', 'variant_cj_vid', 'variant_image'];
    public const MAX_PRODUCTS = 1000;
    public const CLEAR = '[leeg]';

    /** Kolomnaam in CSV → sleutel in de API-vorm van het productformulier. */
    private const MAP = [
        'sku' => 'sku', 'name' => 'name', 'category' => 'category', 'status' => 'status', 'price' => 'price', 'compare_price' => 'compare', 'cost' => 'cost',
        'stock' => 'stock', 'track_stock' => 'trackStock', 'description' => 'description', 'image' => 'image', 'manufacturer' => 'manufacturer',
        'eu_responsible' => 'euResponsible', 'safety_info' => 'safetyInfo', 'warehouse' => 'warehouse', 'supplier' => 'supplier', 'supplier_url' => 'supplierUrl',
        'cj_vid' => 'cjVid', 'cj_from' => 'cjFrom', 'cj_logistic' => 'cjLogistic', 'icon' => 'icon', 'hue' => 'hue',
    ];

    /* ======================= Export ======================= */

    /** Beschermt tegen formule-injectie in Excel: een cel die met = + - @ begint krijgt een apostrof (de import haalt die weer weg). */
    private function safe(string $v): string
    {
        return preg_match('/^[=@]|^[+-](?!\d)/', $v) ? "'".$v : $v;
    }

    private function unsafe(string $v): string
    {
        return preg_match("/^'[=+\\-@]/", $v) ? substr($v, 1) : $v;
    }

    private function num(?float $v): string
    {
        return $v === null ? '' : rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
    }

    private function pairs(array $map): string
    {
        $out = [];
        foreach ($map as $k => $v) {
            $out[] = $k.':'.$v;
        }

        return implode('|', $out);
    }

    public function export(string $sep = ';'): string
    {
        $sep = in_array($sep, [',', ';', "\t"], true) ? $sep : ';';
        $h = fopen('php://temp', 'r+');
        fputcsv($h, array_merge(self::PRODUCT_COLUMNS, self::VARIANT_COLUMNS), $sep);
        foreach (Product::with('variants')->orderBy('created_at')->orderBy('id')->get() as $p) {
            $options = collect((array) $p->options)->map(fn ($o) => $o['name'].':'.implode('|', $o['values']))->implode(';');
            $row = [
                $p->id, $p->sku, $p->name, $p->category, $p->status, $this->num($p->price), $this->num($p->compare_price), $this->num($p->cost), $p->stock, $p->track_stock ? 'ja' : 'nee',
                $p->description, implode('|', (array) $p->features), $p->image, implode('|', array_filter((array) $p->images)),
                $options, $p->manufacturer, $p->eu_responsible, $p->safety_info, $p->warehouse, $p->days_min, $p->days_max, $p->supplier, $p->supplier_url, $p->cj_vid, $p->cj_from, $p->cj_logistic, $p->icon, $p->hue,
            ];
            fputcsv($h, array_merge(array_map(fn ($v) => $this->safe((string) $v), $row), array_fill(0, count(self::VARIANT_COLUMNS), '')), $sep);
            foreach ($p->variants as $v) {
                $vrow = [$v->label, $this->pairs((array) $v->values), $v->sku, $this->num($v->price), $this->num($v->cost), $v->stock, $v->cj_vid, $v->image];
                fputcsv($h, array_merge([$p->id], array_fill(0, count(self::PRODUCT_COLUMNS) - 1, ''), array_map(fn ($x) => $this->safe((string) $x), $vrow)), $sep);
            }
        }
        rewind($h);

        return "\xEF\xBB\xBF".stream_get_contents($h); // BOM zodat Excel de UTF-8-tekens goed toont
    }

    /* ======================= Inlezen ======================= */

    /** @return array{headers: string[], rows: array<int, array{line: int, data: array<string, string>}>} */
    public function parse(string $content): array
    {
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
        if (! mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1252'); // oudere Excel-exports
        }
        $first = strtok($content, "\n") ?: '';
        $sep = collect([';' => substr_count($first, ';'), ',' => substr_count($first, ','), "\t" => substr_count($first, "\t")])->sortDesc()->keys()->first();
        $h = fopen('php://temp', 'r+');
        fwrite($h, $content);
        rewind($h);
        $headers = fgetcsv($h, 0, $sep);
        if (! $headers) {
            throw new InvalidArgumentException('Het bestand is leeg.');
        }
        $headers = array_map(fn ($c) => Str::of((string) $c)->trim()->lower()->replace([' ', '-'], '_')->toString(), $headers);
        $known = array_merge(self::PRODUCT_COLUMNS, self::VARIANT_COLUMNS);
        $unknown = array_values(array_filter($headers, fn ($c) => $c !== '' && ! in_array($c, $known, true)));
        if (! array_intersect($headers, ['id', 'sku'])) {
            throw new InvalidArgumentException('De kolom "id" of "sku" ontbreekt in de eerste regel. Exporteer eerst een bestand om het juiste formaat te zien.');
        }
        if ($unknown) {
            throw new InvalidArgumentException('Onbekende kolom(men): '.implode(', ', array_slice($unknown, 0, 5)).'. Gebruik de kolomnamen uit een export.');
        }
        $rows = [];
        $line = 1;
        while (($r = fgetcsv($h, 0, $sep)) !== false) {
            $line++;
            if ($r === [null] || ! array_filter($r, fn ($c) => trim((string) $c) !== '')) {
                continue; // lege regel
            }
            $data = [];
            foreach ($headers as $i => $col) {
                if ($col !== '') {
                    $data[$col] = $this->unsafe(trim((string) ($r[$i] ?? '')));
                }
            }
            $rows[] = ['line' => $line, 'data' => $data];
            if (count($rows) > 5000) {
                throw new InvalidArgumentException('Het bestand heeft te veel regels (maximaal 5000).');
            }
        }

        return ['headers' => $headers, 'rows' => $rows];
    }

    /* ======================= Omzetten ======================= */

    private function number(string $v): ?float
    {
        $v = str_replace([' ', "\u{a0}", '€'], '', $v);
        if (preg_match('/^\d{1,3}(\.\d{3})+$/', $v)) {
            $v = str_replace('.', '', $v);         // 1.000 of 1.234.567 (punt als duizendtal)
        }
        if (str_contains($v, ',') && str_contains($v, '.')) {
            $v = str_replace('.', '', $v);         // 1.234,56
        }
        $v = str_replace(',', '.', $v);

        return is_numeric($v) ? (float) $v : null;
    }

    private function bool(string $v): ?bool
    {
        return match (strtolower($v)) {
            '1', 'ja', 'yes', 'true', 'waar', 'aan' => true,
            '0', 'nee', 'no', 'false', 'onwaar', 'uit' => false,
            default => null,
        };
    }

    /** "Kleur:Zwart|Wit;Maat:S|M" → [['name' => 'Kleur', 'values' => ['Zwart','Wit']], ...] */
    private function options(string $v): array
    {
        $out = [];
        foreach (array_filter(array_map('trim', explode(';', $v))) as $part) {
            [$name, $vals] = array_pad(explode(':', $part, 2), 2, '');
            $out[] = ['name' => trim($name), 'values' => array_values(array_filter(array_map('trim', explode('|', $vals))))];
        }

        return $out;
    }

    /** "Kleur:Zwart|Maat:M" → ['Kleur' => 'Zwart', 'Maat' => 'M'] */
    private function pairValues(string $v): array
    {
        $out = [];
        foreach (array_filter(array_map('trim', explode('|', $v))) as $p) {
            [$k, $val] = array_pad(explode(':', $p, 2), 2, '');
            if (trim($k) !== '' && trim($val) !== '') {
                $out[trim($k)] = trim($val);
            }
        }

        return $out;
    }

    /** Zet de ingevulde product-cellen om naar de vorm van het productformulier. @return array{set: array, errors: string[]} */
    private function convert(array $d): array
    {
        $set = [];
        $err = [];
        $clear = fn (string $v) => $v === self::CLEAR;
        foreach (self::MAP as $col => $key) {
            $v = $d[$col] ?? '';
            if ($v === '') {
                continue;
            }
            if ($clear($v)) {
                $set[$key] = in_array($col, ['compare_price'], true) ? null : (in_array($col, ['price', 'name', 'sku', 'category', 'status'], true) ? '' : (in_array($col, ['cost', 'stock', 'hue'], true) ? 0 : ''));
                continue;
            }
            if (in_array($col, ['price', 'compare_price', 'cost'], true)) {
                $n = $this->number($v);
                if ($n === null) { $err[] = $col.': "'.$v.'" is geen getal.'; continue; }
                $set[$key] = $n;
            } elseif (in_array($col, ['stock', 'hue'], true)) {
                $n = $this->number($v);
                if ($n === null || $n != (int) $n) { $err[] = $col.': "'.$v.'" is geen geheel getal.'; continue; }
                $set[$key] = (int) $n;
            } elseif ($col === 'track_stock') {
                $b = $this->bool($v);
                if ($b === null) { $err[] = 'track_stock: gebruik ja of nee.'; continue; }
                $set[$key] = $b;
            } elseif ($col === 'status') {
                $s = strtolower($v);
                $set[$key] = in_array($s, ['active', 'actief', 'ja'], true) ? 'active' : (in_array($s, ['draft', 'concept', 'nee'], true) ? 'draft' : $s);
            } else {
                $set[$key] = $v;
            }
        }
        foreach (['features' => 'features', 'images' => 'images'] as $col => $key) {
            $v = $d[$col] ?? '';
            if ($v !== '') {
                $set[$key] = $clear($v) ? [] : array_values(array_filter(array_map('trim', explode('|', $v))));
            }
        }
        if (($d['options'] ?? '') !== '') {
            $set['options'] = $clear($d['options']) ? [] : $this->options($d['options']);
        }
        foreach (['days_min', 'days_max'] as $col) {
            if (($d[$col] ?? '') !== '') {
                $n = $this->number($d[$col]);
                if ($n === null) { $err[] = $col.': "'.$d[$col].'" is geen getal.'; continue; }
                $set[$col] = (int) $n;
            }
        }
        if (isset($set['cjFrom'])) {
            $set['cjFrom'] = strtoupper($set['cjFrom']);
        }

        return ['set' => $set, 'errors' => $err];
    }

    /** Variantrij → variantvorm van het productformulier. @return array{row: array, errors: string[]} */
    private function convertVariant(array $d): array
    {
        $err = [];
        $values = $this->pairValues($d['variant_values'] ?? '');
        $label = $d['variant_label'] ?? '';
        if ($label === '' && $values) {
            $label = implode(' / ', $values);
        }
        $num = function (string $col) use ($d, &$err) {
            $v = $d[$col] ?? '';
            if ($v === '' || $v === self::CLEAR) {
                return null;
            }
            $n = $this->number($v);
            if ($n === null) { $err[] = $col.': "'.$v.'" is geen getal.'; }

            return $n;
        };
        $row = ['label' => $label, 'values' => $values, 'sku' => $d['variant_sku'] ?? '', 'ownPrice' => $num('variant_price'), 'cost' => $num('variant_cost'),
            'stock' => (int) ($num('variant_stock') ?? 0), 'cjVid' => $d['variant_cj_vid'] ?? '', 'image' => ($d['variant_image'] ?? '') === self::CLEAR ? '' : ($d['variant_image'] ?? '')];

        return ['row' => $row, 'errors' => $err];
    }

    private function hasVariantData(array $d): bool
    {
        foreach (self::VARIANT_COLUMNS as $c) {
            if (($d[$c] ?? '') !== '') {
                return true;
            }
        }

        return false;
    }

    private function hasProductData(array $d): bool
    {
        foreach (self::PRODUCT_COLUMNS as $c) {
            if (($d[$c] ?? '') !== '') {
                return true;
            }
        }

        return false;
    }

    /** Groepeert regels tot producten met hun variantregels. */
    private function group(array $rows): array
    {
        $products = [];
        $cur = null;
        foreach ($rows as $r) {
            $d = $r['data'];
            $id = $d['id'] ?? '';
            $sku = $d['sku'] ?? '';
            $name = $d['name'] ?? '';
            $sameProduct = $cur && (
                ($id !== '' && $id === ($cur['d']['id'] ?? '')) ||
                ($id === '' && $sku !== '' && $sku === ($cur['d']['sku'] ?? '') && $name === '' && $this->hasVariantData($d)) ||
                ($id === '' && $sku === '' && $name === '' && $this->hasVariantData($d))
            );
            // Variantregel: hoort bij het vorige product (zelfde id, of geen identiteit) en heeft alleen variantgegevens.
            $variantOnly = $this->hasVariantData($d) && ! array_filter(array_diff_key(array_intersect_key($d, array_flip(self::PRODUCT_COLUMNS)), ['id' => 1, 'sku' => 1]), fn ($v) => $v !== '');
            if ($sameProduct && $variantOnly) {
                $cur['variants'][] = ['line' => $r['line'], 'd' => $d];
                $products[count($products) - 1] = $cur;
                continue;
            }
            $cur = ['line' => $r['line'], 'd' => $d, 'variants' => $this->hasVariantData($d) && $this->hasProductData($d) ? [['line' => $r['line'], 'd' => $d]] : []];
            $products[] = $cur;
        }

        return $products;
    }

    /* ======================= Plan (voorbeeld) ======================= */

    private function norm(mixed $v): string
    {
        return is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : (is_float($v) || is_int($v) ? rtrim(rtrim(number_format((float) $v, 4, '.', ''), '0'), '.') : (string) ($v ?? ''));
    }

    /**
     * Berekent wat er zou gebeuren, zonder iets op te slaan.
     *
     * @return array{items: array<int, array<string, mixed>>, errors: array<int, array{line: int, id: string, messages: string[]}>, summary: array<string, int>}
     */
    public function plan(array $parsed): array
    {
        $groups = $this->group($parsed['rows']);
        if (count($groups) > self::MAX_PRODUCTS) {
            throw new InvalidArgumentException('Te veel producten in één bestand (maximaal '.self::MAX_PRODUCTS.').');
        }
        $items = [];
        $errors = [];
        $seenId = [];
        $seenSku = [];
        $summary = ['create' => 0, 'update' => 0, 'unchanged' => 0, 'variantsCreate' => 0, 'variantsUpdate' => 0, 'variantsDelete' => 0, 'warnings' => 0];

        foreach ($groups as $g) {
            $d = $g['d'];
            $msgs = [];
            $warn = [];
            $id = trim((string) ($d['id'] ?? ''));
            $sku = trim((string) ($d['sku'] ?? ''));
            $existing = null;
            if ($id !== '') {
                $existing = Product::with('variants')->find($id);
            } elseif ($sku !== '') {
                $existing = Product::with('variants')->whereRaw('lower(sku) = ?', [strtolower($sku)])->first();
            }
            ['set' => $set, 'errors' => $e1] = $this->convert($d);
            $msgs = array_merge($msgs, $e1);

            if ($existing) {
                $base = $existing->toApi(true);
                unset($base['variants']);
                $api = $base;
            } else {
                if ($id === '' && $sku === '') {
                    $errors[] = ['line' => $g['line'], 'id' => '', 'messages' => ['Geen id of sku op deze regel.']];
                    continue;
                }
                if ($id === '') {
                    $id = Str::of($sku)->lower()->replaceMatches('/[^a-z0-9_-]+/', '-')->trim('-')->limit(40, '')->toString();
                }
                $api = ['id' => $id, 'icon' => 'package', 'hue' => 220, 'days' => [3, 7], 'status' => 'draft', 'cost' => 0, 'stock' => 0, 'trackStock' => false, 'images' => [], 'features' => [], 'options' => [], 'description' => ''];
            }
            $days = $api['days'] ?? [3, 7];
            if (isset($set['days_min'])) { $days[0] = $set['days_min']; }
            if (isset($set['days_max'])) { $days[1] = $set['days_max']; }
            unset($set['days_min'], $set['days_max']);
            $api = array_replace($api, $set) + ['days' => $days];
            $api['days'] = $days;
            $pid = $existing?->id ?? $id;

            // Dubbele id's/sku's binnen het bestand
            if (isset($seenId[$pid])) { $msgs[] = 'Dit product staat al op regel '.$seenId[$pid].' van het bestand.'; }
            $seenId[$pid] = $g['line'];
            $skuKey = strtolower((string) ($api['sku'] ?? ''));
            if ($skuKey !== '' && isset($seenSku[$skuKey])) { $msgs[] = 'sku "'.$api['sku'].'" staat al op regel '.$seenSku[$skuKey].'.'; }
            $seenSku[$skuKey] = $g['line'];

            // Varianten
            $variantRows = [];
            $vplan = null;
            if ($g['variants']) {
                foreach ($g['variants'] as $vr) {
                    ['row' => $row, 'errors' => $ve] = $this->convertVariant($vr['d']);
                    foreach ($ve as $m) { $msgs[] = 'regel '.$vr['line'].': '.$m; }
                    if ($row['label'] === '') { $msgs[] = 'regel '.$vr['line'].': variant_label (of variant_values) ontbreekt.'; }
                    $variantRows[] = $row + ['_line' => $vr['line']];
                }
                // koppelen aan bestaande varianten (sku, anders label)
                $old = $existing ? $existing->variants : collect();
                $used = [];
                $create = $update = 0;
                foreach ($variantRows as &$row) {
                    $match = $old->first(fn ($o) => ! in_array($o->id, $used, true) && (($row['sku'] !== '' && strcasecmp((string) $o->sku, $row['sku']) === 0) || ($row['sku'] === '' && strcasecmp($o->label, $row['label']) === 0)));
                    if ($match) { $row['id'] = $match->id; $used[] = $match->id; $update++; } else { $create++; }
                }
                unset($row);
                $delete = $old->count() - count($used);
                $vplan = ['create' => $create, 'update' => $update, 'delete' => $delete];
                // geen opties opgegeven → afleiden uit de varianten
                if (! isset($set['options']) && ! $existing?->options) {
                    $names = [];
                    foreach ($variantRows as $row) { foreach ($row['values'] as $k => $val) { $names[$k][] = $val; } }
                    $api['options'] = array_map(fn ($k, $vals) => ['name' => $k, 'values' => array_values(array_unique($vals))], array_keys($names), $names);
                }
                $api['variants'] = array_map(fn ($r) => array_diff_key($r, ['_line' => 1]), $variantRows);
            }

            $rules = AdminController::productRules($existing);
            if ($existing) {
                $rules['id'] = ['nullable', 'string'];
            }
            $v = Validator::make($api, $rules);
            if ($v->fails()) {
                foreach ($v->errors()->messages() as $field => $list) {
                    $msgs[] = $field.': '.$list[0];
                }
            }
            if ($msgs) {
                $errors[] = ['line' => $g['line'], 'id' => $pid, 'messages' => array_values(array_unique($msgs))];
                continue;
            }
            $valid = $v->validated();
            // (cost wordt niet gevalideerd als vereist: attributesFromApi vult 0 in)

            // Wat verandert er?
            $changes = [];
            if ($existing) {
                $base = $existing->toApi(true);
                foreach (array_keys($set) as $k) {
                    if ($this->norm($base[$k] ?? null) !== $this->norm($api[$k] ?? null)) {
                        $changes[] = $k;
                    }
                }
                if (isset($days) && ($existing->days_min !== $days[0] || $existing->days_max !== $days[1])) { $changes[] = 'days'; }
            }
            if ($vplan && ($vplan['create'] || $vplan['delete'] || $vplan['update'])) {
                // varianten tellen mee als wijziging
                $oldRows = $existing ? $existing->variants->map(fn ($o) => [$o->label, $o->sku, $o->price, $o->cost, $o->stock, $o->cj_vid, $o->image])->all() : [];
                $newRows = array_map(fn ($r) => [$r['label'], $r['sku'], $r['ownPrice'], $r['cost'], $r['stock'], $r['cjVid'], $r['image']], $variantRows);
                if (! $existing || $this->norm($oldRows) !== $this->norm($newRows)) { $changes[] = 'variants'; }
            }

            // Waarschuwingen (geen fout)
            if (($api['cost'] ?? 0) > 0 && $api['price'] < $api['cost']) { $warn[] = 'Verkoopprijs ('.$api['price'].') is lager dan de inkoopprijs ('.$api['cost'].').'; }
            if ($existing && isset($set['price']) && $existing->price > 0 && $set['price'] < $existing->price * 0.5) { $warn[] = 'Prijs daalt met meer dan 50% (van '.$existing->price.' naar '.$set['price'].').'; }
            if (($api['status'] ?? '') === 'active' && empty($api['image']) && empty($api['images'])) { $warn[] = 'Actief product zonder foto (alleen een icoon wordt getoond; ook niet bruikbaar voor de productfeed).'; }

            $action = $existing ? ($changes ? 'update' : 'unchanged') : 'create';
            $summary[$action]++;
            $summary['warnings'] += count($warn);
            if ($vplan) {
                $summary['variantsCreate'] += $vplan['create'];
                $summary['variantsUpdate'] += $vplan['update'];
                $summary['variantsDelete'] += $vplan['delete'];
            }
            $items[] = ['line' => $g['line'], 'action' => $action, 'id' => $pid, 'name' => (string) ($api['name'] ?? ''), 'changes' => $changes, 'variants' => $vplan, 'warnings' => $warn, '_api' => $valid, '_existing' => $existing?->id, '_variants' => $g['variants'] ? array_map(fn ($r) => array_diff_key($r, ['_line' => 1]), $variantRows) : null, '_full' => $api];
        }

        return ['items' => $items, 'errors' => $errors, 'summary' => $summary];
    }

    /** Slaat een (foutloos) plan op, alles of niets. @return array<string, int> */
    public function apply(array $plan): array
    {
        if ($plan['errors']) {
            throw new InvalidArgumentException('Het bestand bevat fouten; er is niets opgeslagen.');
        }
        DB::transaction(function () use ($plan) {
            foreach ($plan['items'] as $it) {
                if ($it['action'] === 'unchanged') {
                    continue;
                }
                $attrs = Product::attributesFromApi($it['_api'] + $it['_full']);
                if ($it['_existing']) {
                    $p = Product::findOrFail($it['_existing']);
                    $p->update($attrs);
                } else {
                    $p = Product::create(['id' => $it['id']] + $attrs);
                }
                if ($it['_variants'] !== null) {
                    $p->syncVariants($it['_variants']);
                }
            }
        });

        return $plan['summary'];
    }
}
