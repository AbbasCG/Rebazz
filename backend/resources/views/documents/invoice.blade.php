@php
    $sign = $credit ? -1 : 1;
    $eur = fn ($v) => ($v * $sign < 0 ? '- ' : '').'€ '.number_format(abs($v), 2, ',', '.');
    $a = $o->address ?? [];
    $title = $credit ? 'Creditnota' : 'Factuur';
    $regimeNote = match ($vat['regime']) {
        'export' => 'Levering buiten de Europese Unie: 0% btw.',
        'oss' => 'Btw volgens het tarief van het land van bestemming (OSS-regeling).',
        default => null,
    };
    $payment = ['ideal' => 'iDEAL', 'card' => 'Creditcard / betaalkaart', 'bancontact' => 'Bancontact', 'klarna' => 'Klarna', 'paypal' => 'PayPal'][$o->payment_method] ?? ($o->payment_method ?: 'Online betaling');
@endphp
<!DOCTYPE html>
<html lang="nl">
<head>
<meta charset="UTF-8">
<title>{{ $title }} {{ $number }}</title>
<style>
    @page { margin: 36px 40px; }
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 10.5px; color: #1b1d22; line-height: 1.45; }
    h1 { font-size: 22px; margin: 0 0 4px; letter-spacing: .02em; }
    .muted { color: #666b75; }
    table { width: 100%; border-collapse: collapse; }
    .head td { vertical-align: top; }
    .meta td { padding: 1px 0; }
    .items th { text-align: left; border-bottom: 1.5px solid #1b1d22; padding: 6px 4px; font-size: 9.5px; text-transform: uppercase; }
    .items td { padding: 6px 4px; border-bottom: 1px solid #e3e5ea; vertical-align: top; }
    .r { text-align: right; }
    .totals { width: 52%; margin-left: 48%; margin-top: 14px; }
    .totals td { padding: 3px 4px; }
    .totals .grand td { border-top: 1.5px solid #1b1d22; font-weight: bold; font-size: 12px; padding-top: 6px; }
    .box { margin-top: 18px; padding: 8px 10px; background: #f4f5f7; border-radius: 4px; }
    .foot { margin-top: 26px; font-size: 9px; color: #666b75; border-top: 1px solid #e3e5ea; padding-top: 8px; }
</style>
</head>
<body>
<table class="head">
    <tr>
        <td style="width:55%">
            <h1>{{ $title }}</h1>
            <div class="muted">{{ $number }}</div>
        </td>
        <td class="r">
            <strong>{{ $brand['company'] ?? $brand['name'] }}</strong><br>
            {{ $brand['address'] ?? '' }}<br>
            {{ $brand['email'] ?? '' }}<br>
            KvK: {{ $brand['kvk'] ?? '' }}<br>
            Btw-nr: {{ $brand['btw'] ?? '' }}
        </td>
    </tr>
</table>

<table style="margin-top:20px">
    <tr>
        <td style="width:50%; vertical-align:top">
            <div class="muted">{{ $credit ? 'Creditnota aan' : 'Factuur aan' }}</div>
            <strong>{{ $o->customer_name }}</strong><br>
            @if(!empty($a['line1'])){{ $a['line1'] }}<br>@endif
            @if(!empty($a['line2'])){{ $a['line2'] }}<br>@endif
            {{ trim(($a['postal_code'] ?? '').' '.($a['city'] ?? '')) }}<br>
            {{ $a['country'] ?? $o->country }}<br>
            <span class="muted">{{ $o->email }}</span>
        </td>
        <td style="vertical-align:top">
            <table class="meta">
                <tr><td class="muted">{{ $credit ? 'Creditnotadatum' : 'Factuurdatum' }}</td><td class="r">{{ $date->timezone('Europe/Amsterdam')->format('d-m-Y') }}</td></tr>
                <tr><td class="muted">Bestelnummer</td><td class="r">{{ $o->order_no }}</td></tr>
                @if($credit && !empty($rma))
                <tr><td class="muted">Retour</td><td class="r">{{ $rma }}</td></tr>
                @endif
                @if($credit)
                <tr><td class="muted">Betreft factuur</td><td class="r">{{ $o->invoice_number }}</td></tr>
                @endif
                <tr><td class="muted">Besteldatum</td><td class="r">{{ ($o->paid_at ?? $o->created_at)->timezone('Europe/Amsterdam')->format('d-m-Y') }}</td></tr>
                <tr><td class="muted">Betaalmethode</td><td class="r">{{ $payment }}</td></tr>
            </table>
        </td>
    </tr>
</table>

<table class="items" style="margin-top:22px">
    <thead>
        <tr><th>Omschrijving</th><th class="r" style="width:8%">Aantal</th><th class="r" style="width:20%">Prijs incl. btw</th><th class="r" style="width:20%">Totaal incl. btw</th></tr>
    </thead>
    <tbody>
    @if(!empty($lines))
    @foreach($lines as $l)
        <tr>
            <td>{{ $l['name'] }}@if(!empty($l['sku']))<br><span class="muted">SKU {{ $l['sku'] }}</span>@endif</td>
            <td class="r">{{ $l['qty'] }}</td>
            <td class="r">{{ $eur($l['price']) }}</td>
            <td class="r">{{ $eur($l['total']) }}</td>
        </tr>
    @endforeach
    @else
    @foreach($o->items as $i)
        <tr>
            <td>{{ $i->name }}@if($i->variant_label) — {{ $i->variant_label }}@endif @if($i->sku)<br><span class="muted">SKU {{ $i->sku }}</span>@endif</td>
            <td class="r">{{ $i->qty }}</td>
            <td class="r">{{ $eur($i->price) }}</td>
            <td class="r">{{ $eur($i->price * $i->qty) }}</td>
        </tr>
    @endforeach
        <tr>
            <td>Verzendkosten ({{ $o->ship_method === 'express' ? 'express' : 'standaard' }})</td>
            <td class="r">1</td>
            <td class="r">{{ $eur($o->shipping) }}</td>
            <td class="r">{{ $eur($o->shipping) }}</td>
        </tr>
        @if($o->discount > 0)
        <tr><td>Korting</td><td class="r"></td><td class="r"></td><td class="r">{{ $eur(-$o->discount) }}</td></tr>
        @endif
    @endif
    </tbody>
</table>

<table class="totals">
    <tr><td>Totaal excl. btw</td><td class="r">{{ $eur($vat['excl']) }}</td></tr>
    <tr><td>Btw {{ rtrim(rtrim(number_format($vat['rate'], 2, ',', ''), '0'), ',') }}%</td><td class="r">{{ $eur($vat['vat']) }}</td></tr>
    <tr class="grand"><td>{{ $credit ? 'Terugbetaald' : 'Totaal' }} incl. btw</td><td class="r">{{ $eur($total ?? $o->total) }}</td></tr>
</table>

@if($regimeNote)
<div class="box">{{ $regimeNote }}</div>
@endif
@if($o->tax_amount === null)
<div class="box muted">Het btw-bedrag is berekend met het vaste tarief voor het land van bestemming.</div>
@endif
@if($credit && !empty($rma))
<div class="box">Deze creditnota betreft de terugbetaling voor retour {{ $rma }} van factuur {{ $o->invoice_number }} via de oorspronkelijke betaalmethode.</div>
@elseif($credit && isset($total) && $total < $o->total)
<div class="box">Deze creditnota betreft de terugbetaling van het restant van factuur {{ $o->invoice_number }} (eerdere retouren zijn al apart gecrediteerd) via de oorspronkelijke betaalmethode.</div>
@elseif($credit)
<div class="box">Deze creditnota betreft de volledige terugbetaling van factuur {{ $o->invoice_number }} via de oorspronkelijke betaalmethode.</div>
@else
<div class="box">Betaald via {{ $payment }}. Deze factuur is reeds voldaan.</div>
@endif

<div class="foot">
    {{ $brand['company'] ?? $brand['name'] }} · {{ $brand['address'] ?? '' }} · KvK {{ $brand['kvk'] ?? '' }} · Btw {{ $brand['btw'] ?? '' }} · {{ $brand['email'] ?? '' }}<br>
    Retouradres: {{ $brand['returnAddress'] ?? $brand['address'] ?? '' }}
</div>
</body>
</html>
