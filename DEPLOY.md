# Rebazz: livegang-checklist (Laravel + React)

Productiepad: **Laravel** (`backend/`) serveert de API en de gebouwde React-app (`backend/public/app`).
De Netlify-functies en de statische HTML-site zijn legacy en horen niet live te staan.

## 1. Server
- PHP **8.4+** (extensies: pdo_pgsql, intl, mbstring, curl), PostgreSQL, webserver met document root `backend/public`.
- Publiceer **nooit** de projectroot: alleen `backend/public`.

## 2. Eerste keer
```bash
cd backend
composer install --no-dev --optimize-autoloader
cp .env.production.example .env      # vul alle lege waarden in
php artisan key:generate
php artisan migrate --force
# Webserver-gebruiker moet kunnen schrijven in storage/ en bootstrap/cache/
php artisan config:cache && php artisan route:cache && php artisan event:cache && php artisan view:cache
cd ../frontend && npm ci && npm run build   # bouwt naar backend/public/app (Node nodig op de buildmachine; public/app staat niet in git)
```

**Startproducten:** `php artisan db:seed --force` zet de 10 voorbeeldproducten uit de vorige versie in de database
(zonder beoordelingen, zonder CJ-variant-ID). Doe dit **alleen** als u die producten echt wilt verkopen; vul dan eerst per product
GPSR-gegevens en CJ-variant-ID in via het beheer (`/beheer`) voordat u ze activeert. Anders begint u met een lege winkel en voegt u eigen producten toe.

## 3. Taken op de server
- Cron (verplicht, ook voor de CJ-synchronisatie en de melding bij vastgelopen bestellingen):
  `* * * * * cd /pad/backend && php artisan schedule:run >> /dev/null 2>&1`
- Een queue-worker is **niet** nodig (`QUEUE_CONNECTION=sync`; er worden geen jobs in een wachtrij gezet).
- Achter een proxy/Cloudflare: zet `TRUSTED_PROXIES` in `.env`, anders ziet de app alleen het proxy-IP (gedeelde limieten, geen HSTS).
- Na het wijzigen van `ADMIN_PASSWORD` blijven bestaande beheer-tokens tot 7 dagen geldig. Trek ze direct in met:
  `php artisan tinker --execute="Laravel\Sanctum\PersonalAccessToken::query()->delete();"`
- Nieuwsbriefaanmeldingen gebruiken dubbele opt-in: de bevestigingslink verwijst naar `APP_URL` (`/api/newsletter/confirm`), dus `APP_URL` moet het echte https-domein zijn en mail moet werken.
- Houd `OWNER_ALERT_EMAIL` actueel: daar komen meldingen binnen over mislukte of vastgelopen CJ-bestellingen, terugbetalingen en betwistingen.

## 4. Stripe
- Webhook-endpoint: `https://<domein>/api/stripe/webhook` met de events:
  `checkout.session.completed`, `checkout.session.async_payment_succeeded`, `charge.refunded`, `charge.dispute.created`.
- Zet `STRIPE_TAX=on` en activeer Stripe Tax (live-modus weigert checkout zonder).
- Beperk kortingscodes in Stripe (max. gebruik, minimumbedrag, alleen eerste bestelling), of zet `SHOP_ALLOW_PROMO=off`.
- Doe eerst een testbetaling in testmodus en een kleine echte betaling met terugbetaling.

## 5. CJdropshipping
- Begin met `CJ_AUTO_PAY=off`. Zet pas `on` na testbestellingen. Stel `SHOP_MIN_MARGIN_PERCENT` in (bijv. 15).
- Controleer dat elk product een CJ-variant-ID (`cjVid`) en magazijn heeft.

## 6. E-mail en DNS
- Echte SMTP/Resend, `MAIL_FROM_ADDRESS` op eigen domein, plus SPF, DKIM en DMARC.
- `OWNER_ALERT_EMAIL` ingesteld: hier komen meldingen bij mislukte CJ-bestellingen, terugbetalingen en betwistingen.

## 7. Juridisch (niet in code op te lossen)
- Bedrijfsgegevens (KvK, btw, adres) controleren; juridische teksten laten nalezen en het voorbeeldbanner verwijderen.
- Verwerkersovereenkomsten/SCC's met Stripe, CJ, mailprovider en hosting.
- Werkend herroepingsformulier en vermelding van het ODR-platform.
- Productcompliance (GPSR, CPNP/CE/WEEE) per product.

## 8. Na livegang
- Controleer `GET /up`, een testbestelling, de webhook-logs in Stripe en `storage/logs`.
- Maak back-ups van de database (dagelijks) en test een restore.
- Voeg uptime- en foutmonitoring toe.
