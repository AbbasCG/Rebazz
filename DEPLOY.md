# Rebazz: livegang-checklist (Laravel + React)

Productiepad: **Laravel** (`backend/`) serveert de API en de gebouwde React-app (`backend/public/app`).
De Netlify-functies en de statische HTML-site zijn legacy en horen niet live te staan.

## 1. Server
- PHP **8.4+** (extensies: pdo_pgsql, intl, mbstring, curl), PostgreSQL, webserver met document root `backend/public`.
- Publiceer **nooit** de projectroot: alleen `backend/public`.
- Serveer `/app/index.html` niet rechtstreeks (anders ontbreken CSP en beveiligingsheaders). Apache: staat al in `public/.htaccess`.
  Nginx: `location = /app/index.html { rewrite ^ /index.php last; }` (en `try_files $uri /index.php?$query_string;` voor de rest).

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
- **Beheeraccounts en 2FA:** de eigenaar logt in met `ADMIN_PASSWORD` (laat het e-mailveld leeg); medewerkers krijgen onder *Team & rollen* een eigen account met rol (Manager, Klantenservice, Marketing, Eigenaar), zodat niemand het eigenaarswachtwoord hoeft te kennen. Iedereen kan onder *Mijn account* tweestapsverificatie (authenticator-app + 8 herstelcodes) aanzetten; met `SHOP_REQUIRE_2FA=on` is het verplicht voor het hele beheer (aanbevolen: zet het aan en stel eerst uw eigen 2FA in). Terugbetalen mogen alleen Eigenaar en Manager; de eigenaar ziet het activiteitenlogboek (12 maanden). Bent u uw telefoon én herstelcodes kwijt: `php artisan staff:reset-2fa <e-mail>` op de server (ook voor het eigenaarsaccount `admin@rebazz.local`).
- Na het wijzigen van `ADMIN_PASSWORD` blijven bestaande beheer-tokens tot 7 dagen geldig. Trek ze direct in met:
  `php artisan tinker --execute="Laravel\Sanctum\PersonalAccessToken::query()->delete();"`
- Nieuwsbriefaanmeldingen gebruiken dubbele opt-in: de bevestigingslink verwijst naar `APP_URL` (`/api/newsletter/confirm`), dus `APP_URL` moet het echte https-domein zijn en mail moet werken.
- **AI (Claude):** zet `ANTHROPIC_API_KEY` (uit console.anthropic.com, met een eigen bestedingslimiet daar) in `.env` en alleen daar; de sleutel gaat nooit naar de browser. Beschikbaar voor medewerkers met de juiste rol: productteksten (Producten), advertentieteksten (Advertenties), videoscripts (AI-videostudio) en klantberichten vertalen en beantwoorden (Klantenservice). Alles is een voorstel dat u controleert; er wordt niets automatisch gepubliceerd of verstuurd. Standaardmodel `AI_MODEL=claude-opus-5-5`; voor lagere kosten kunt u `claude-sonnet-5-5` of `claude-haiku-5-5` kiezen (`AI_EFFORT=low|medium|high` regelt de denkdiepte). Limieten per dag: `AI_DAILY_LIMIT` (winkel, standaard 300) en `AI_USER_DAILY_LIMIT` (per medewerker, 100); gebruik staat in de tabel `ai_usage` (tokens, nooit de tekst). Zonder sleutel tonen de AI-knoppen een melding. Zie ook PRIVACY.md (Anthropic als verwerker). De productzoeker onder *Productonderzoek* gebruikt nog voorbeeldgegevens en geen AI.
- **Producten in bulk en productfeed:** *Producten → Importeren / exporteren*. Import is alles-of-niets met voorbeeld vooraf, werkt op `id` of `sku`, laat ontbrekende kolommen en lege cellen ongemoeid en maakt nieuwe producten altijd als concept aan (dezelfde regels als het productformulier, ook GPSR). Maak vóór een grote import eerst een export als back-up. De feed `https://<domein>/feeds/google.xml` (Google Merchant-formaat; ook voor Meta, Pinterest en TikTok) bevat alleen zichtbare producten met een foto via https (upload ze via het beheer of gebruik een https-link), zonder inkoopprijzen. Stel in Google Merchant Center zelf verzendkosten, btw en land in; de feed bevat die niet. Productnamen en beschrijvingen staan in het Nederlands. Verversing: de feed wordt maximaal 10 minuten gecachet.
- Productpagina's (`/product/<id>/<naam>`) en de sitemap (`/sitemap.xml`, dynamisch) gebruiken `FRONTEND_URL` voor canonical- en sitemap-adressen: zet die op het echte https-domein. Controleer na livegang `https://<domein>/sitemap.xml`, en dien die in bij Google Search Console. Pas de `Sitemap:`-regel in `backend/public/robots.txt` aan naar uw domein.
- **Facturen en orderbevestiging:** elke betaalde bestelling krijgt een doorlopend factuurnummer (`RBF-<jaar>-00001`) en een orderbevestiging met de factuur als PDF-bijlage. Bij een volledige terugbetaling volgt een creditnota (`RBC-<jaar>-00001`). Vereist PHP-extensies `dom` en `mbstring`, een beschrijfbare `storage/app/dompdf`, werkende e-mail en `APP_URL` op het echte https-domein (de downloadlink voor de klant is ondertekend). De cron-taak verstuurt mislukte bevestigingen opnieuw (laatste 48 uur). Laat uw boekhouder controleren of de factuurgegevens (o.a. btw-nummer, btw-regime bij OSS/export, zakelijke klanten met btw-id) aan uw situatie voldoen; zakelijke bestellingen met btw-verlegging worden niet apart behandeld.
- **Voorraadbeheer** is per product optioneel (vinkje "Voorraad bijhouden" in het beheer) en staat standaard uit: bij dropshipping heeft de leverancier de voorraad. Bij een bijgehouden product weigert de kassa meer dan er is, wordt de voorraad bij betaling atomair afgeschreven, en wordt bij een volledige terugbetaling van een nog niet verzonden bestelling teruggeboekt. Kopen twee klanten tegelijk het laatste stuk, dan gaat de tweede bestelling naar "Niet automatisch besteld" met een melding aan `OWNER_ALERT_EMAIL`; u beslist dan: later leveren of terugbetalen. Voorraad wordt niet gereserveerd tijdens het betalen.
- **Productvarianten en foto's:** een product kan varianten hebben (bijv. kleur/maat) met eigen prijs, inkoop, SKU, CJ-variant-ID en voorraad (leeg = waarde van het product). Bestellen bij CJ gebruikt het VID van de gekozen variant. Foto's uit het beheer worden opgeslagen in `storage/app/public/products` en geserveerd via `/media/products/<naam>` (geen `storage:link` nodig): neem die map mee in uw back-ups en laat de webserver erin schrijven. Toegestaan zijn alleen JPG, PNG en WebP tot 4 MB (geen SVG).
- **Retouren (RMA):** klanten vragen een retour aan in "Bestelling volgen" (bestelnummer + e-mailadres; kan ook vóór aflevering, als herroeping). De termijn is `SHOP_RETURN_DAYS` (standaard 30) dagen vanaf aflevering (stap "Afgeleverd" in het beheer). Elke retour krijgt een nummer `RMA-<jaar>-00001`; u beoordeelt, bevestigt de ontvangst (optioneel de voorraad terugboeken) en betaalt (deels) terug via Stripe in het beheer onder **Retouren**. Daarna volgt een creditnota (`RBC-…`, zelfde doorlopende reeks als de creditnota's van volledige terugbetalingen) die de klant via een ondertekende link kan downloaden. Het retouradres komt uit de bedrijfsgegevens (`returnAddress`): controleer dat. De klant krijgt bij elke stap een e-mail in zijn taal, u een melding bij een nieuwe aanvraag (`OWNER_ALERT_EMAIL`). Stripe geeft de verwerkingskosten van een terugbetaling doorgaans niet terug. Een retour met foto's van schade wordt (nog) niet ondersteund: de klant licht toe in tekst. Pas ook de juridische tekst "Retourneren" aan als u deze procedure daar wilt noemen.
- **Privacy (AVG):** klanten kunnen via "Mijn gegevens" (footer) inzage en wissen aanvragen met verificatie per e-mail; u beheert verzoeken onder **Privacy (AVG)** in het beheer. De bewaartermijn voor bestel- en factuurgegevens is `SHOP_RETENTION_YEARS` (standaard 7); de dagelijkse cron-taak `shop:gdpr-retention` anonimiseert verlopen bestellingen en ruimt niet-bevestigde nieuwsbriefaanmeldingen (30 dagen) en oude verzoeklogs (3 jaar) op, dus de cron moet draaien. Ondertekende links werken alleen als `APP_URL`/`FRONTEND_URL` het echte domein zijn en (achter een proxy) `TRUSTED_PROXIES` klopt. Zie **PRIVACY.md** voor het concept-verwerkingsregister.
- Houd `OWNER_ALERT_EMAIL` actueel: daar komen meldingen binnen over mislukte of vastgelopen CJ-bestellingen, terugbetalingen en betwistingen.

## 4. Stripe
- Webhook-endpoint: `https://<domein>/api/stripe/webhook` met de events:
  `checkout.session.completed`, `checkout.session.async_payment_succeeded`, `checkout.session.expired`, `charge.refunded`, `charge.dispute.created`.
- **Herinnering verlaten winkelwagen** (`SHOP_ABANDONED_CART=on`, standaard): de kassa-sessie verloopt na 1 uur; Stripe toont een niet-voorgevinkt vakje voor toestemming en maakt na het verlopen een herstel-link. Alleen met toestemming sturen wij (`shop:cart-reminders`, elke 10 min) één mail. Dit hangt af van `checkout.session.expired` hierboven en is niet tegen echte Stripe getest: probeer het in testmodus (laat een kassa 1 uur open staan, of verloop hem handmatig) en pas `SHOP_ABANDONED_CART=off` toe als u het niet wilt. Vul uw verklaring bij 'Privacyverklaring' aan.
- **Statistieken** (beheer → Statistieken) zijn eigen, anonieme metingen na toestemming in de cookiebanner; er is geen Google Analytics nodig. De bewaartermijn is `SHOP_ANALYTICS_MONTHS` (13). Noem dit in uw cookie- en privacyverklaring (analytische cookies/opslag in de browser, geen IP-adres, geen derden). Gebruik `?utm_source=…&utm_medium=…&utm_campaign=…` in links van advertenties en nieuwsbrieven.
- **Nieuwsbrieven** verstuurt u onder *Nieuwsbrief* in het beheer; de scheduler (cron hierboven, elke minuut) verstuurt per minuut 50 mails. Bij een eigen mailserver met een lage limiet: kies een bezorgdienst (Resend, Postmark, SES) en controleer SPF/DKIM/DMARC voordat u naar veel abonnees mailt.
- Zet `STRIPE_TAX=on` en activeer Stripe Tax (live-modus weigert checkout zonder).
- Beperk kortingscodes in Stripe (max. gebruik, minimumbedrag, alleen eerste bestelling), of zet `SHOP_ALLOW_PROMO=off`.
- Doe eerst een testbetaling in testmodus en een kleine echte betaling met terugbetaling.

## 5. CJdropshipping
- Begin met `CJ_AUTO_PAY=off`. Zet pas `on` na testbestellingen. Stel `SHOP_MIN_MARGIN_PERCENT` in (bijv. 15).
- Controleer dat elk product een CJ-variant-ID (`cjVid`) en magazijn heeft.

## 6. E-mail en DNS
- Echte SMTP/Resend, `MAIL_FROM_ADDRESS` op eigen domein, plus SPF, DKIM en DMARC.
- Klantaccounts loggen in met een **code per e-mail**: zonder werkende mail kan niemand inloggen. Test dit na livegang met een eigen adres. Ondertekende links (factuur, retour) en tokens vragen een juiste `APP_URL`/`TRUSTED_PROXIES` achter een proxy.
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
