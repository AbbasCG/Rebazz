<?php

/*
| Rebazz — winkelinstellingen.
| Gevoelige sleutels komen uit .env, nooit uit de code.
*/

return [
    'admin_password' => env('ADMIN_PASSWORD'),

    // Verplicht tweestapsverificatie voor iedereen die in het beheer werkt (aanbevolen in productie).
    'require_2fa' => env('SHOP_REQUIRE_2FA', 'off') === 'on',
    'frontend_url' => env('FRONTEND_URL', env('APP_URL')),

    'stripe' => [
        'secret' => env('STRIPE_SECRET'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
        'tax' => env('STRIPE_TAX', 'off') === 'on',
    ],

    'cj' => [
        'api_key' => env('CJ_API_KEY'),
        'auto_pay' => env('CJ_AUTO_PAY', 'off') === 'on',
        'base_url' => 'https://developers.cjdropshipping.com/api2.0/v1',
    ],

    // Kortingscodes (Stripe Promotion Codes). Zet op 'off' om het invoerveld in de kassa uit te schakelen.
    'allow_promotion_codes' => env('SHOP_ALLOW_PROMO', 'on') === 'on',

    // Margebewaking: een betaalde bestelling wordt NIET automatisch bij CJ geplaatst als het ontvangen bedrag
    // (na korting, incl. verzending en btw) lager is dan inkoopkosten × (1 + percentage/100). Ze gaat dan naar
    // 'manual' en de eigenaar krijgt een melding. 0 = alleen blokkeren als het bedrag de inkoop niet dekt.
    'min_margin_percent' => (float) env('SHOP_MIN_MARGIN_PERCENT', 0),

    // GPSR: als 'on' kan een product alleen actief worden met fabrikant en EU-verantwoordelijke.
    'require_gpsr' => env('SHOP_REQUIRE_GPSR', 'off') === 'on',

    // Waarschuwingen (mislukte CJ-bestelling, terugbetaling, betwisting) gaan hierheen; leeg = het e-mailadres uit de bedrijfsgegevens.
    'owner_alert_email' => env('OWNER_ALERT_EMAIL'),

    // Retourtermijn (dagen na aflevering). De wettelijke bedenktijd is 14 dagen; de winkel belooft 30 dagen.
    'return_days' => (int) env('SHOP_RETURN_DAYS', 30),

    // AVG: bestel- en factuurgegevens moeten fiscaal 7 jaar bewaard blijven (Nederland); daarna worden persoonsgegevens geanonimiseerd.
    'retention_years' => (int) env('SHOP_RETENTION_YEARS', 7),

    // Verlaten winkelwagen: één herinnering per verlaten kassa, alleen na toestemming die Stripe in de kassa vraagt. 'off' = niets vragen en niets versturen.
    'abandoned_cart' => env('SHOP_ABANDONED_CART', 'on') === 'on',

    // Statistieken (eigen, zonder derden, alleen na toestemming): bewaartermijn in maanden.
    'analytics_months' => (int) env('SHOP_ANALYTICS_MONTHS', 13),

    'currency' => 'eur',

    // Standaard bedrijfsgegevens; aanpasbaar in het dashboard (opgeslagen in de tabel settings).
    'brand' => [
        'name' => 'Rebazz',
        'domain' => 'rebazz.nl',
        'email' => 'info@rebazz.nl',
        'company' => 'Rebazz',
        'kvk' => '90147146',
        'btw' => 'NL004792143B90',
        'address' => 'Beemd 15, 4816 HB Breda, Nederland',
        'returnAddress' => 'Beemd 15, 4816 HB Breda, Nederland',
    ],

    'zones' => [
        ['id' => 'benelux', 'name' => 'Nederland, België & Luxemburg', 'countries' => ['NL', 'BE', 'LU'], 'rate' => 4.95, 'freeFrom' => 49, 'days' => [3, 6], 'vat' => true, 'express' => 12.95],
        ['id' => 'eu', 'name' => 'Europese Unie', 'countries' => ['DE', 'FR', 'ES', 'IT', 'AT', 'PT', 'IE', 'PL', 'SE', 'DK', 'FI', 'CZ', 'GR'], 'rate' => 6.95, 'freeFrom' => 69, 'days' => [5, 9], 'vat' => true, 'express' => 12.95],
        ['id' => 'europe', 'name' => 'Overig Europa', 'countries' => ['GB', 'CH', 'NO'], 'rate' => 9.95, 'freeFrom' => 89, 'days' => [6, 11], 'vat' => false, 'express' => 24.95],
        ['id' => 'na', 'name' => 'Noord-Amerika', 'countries' => ['US', 'CA'], 'rate' => 9.95, 'freeFrom' => 89, 'days' => [7, 12], 'vat' => false, 'express' => 24.95],
        ['id' => 'world', 'name' => 'Rest van de wereld', 'countries' => ['AU', 'NZ', 'JP', 'SG', 'AE', 'SA', 'TR', 'MA', 'ZA', 'BR', 'MX', 'IN', 'KR'], 'rate' => 14.95, 'freeFrom' => 129, 'days' => [9, 16], 'vat' => false, 'express' => 0],
    ],

    // Standaard btw-tarieven EU (2026). Productspecifieke verlaagde tarieven worden niet toegepast.
    'vat_rates' => [
        'AT' => 20, 'BE' => 21, 'BG' => 20, 'HR' => 25, 'CY' => 19, 'CZ' => 21, 'DK' => 25, 'EE' => 24, 'FI' => 25.5, 'FR' => 20, 'DE' => 19,
        'GR' => 24, 'HU' => 27, 'IE' => 23, 'IT' => 22, 'LV' => 21, 'LT' => 21, 'LU' => 17, 'MT' => 18, 'NL' => 21, 'PL' => 23, 'PT' => 23,
        'RO' => 21, 'SK' => 23, 'SI' => 22, 'ES' => 21, 'SE' => 25,
    ],
    'oss_threshold' => 10000,
];
