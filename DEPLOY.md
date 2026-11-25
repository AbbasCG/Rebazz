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
php artisan config:cache && php artisan route:cache
cd ../frontend && npm ci && npm run build   # bouwt naar backend/public/app
```

## 3. Taken op de server
- Cron: `* * * * * cd /pad/backend && php artisan schedule:run >> /dev/null 2>&1`
- Queue-worker onder supervisor/systemd: `php artisan queue:work --tries=3`

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
