# Rebazz — nieuwe versie (React + Laravel + PostgreSQL)

Dit document beschrijft de **nieuwe versie** van de webwinkel. De oude versie (de losse HTML-bestanden en de map `netlify`) staat nog in de map als referentie, maar wordt niet meer gebruikt.

---

## Hoe het in elkaar zit

```
claude Ramin/
├── frontend/   React 19 + TypeScript (Vite) — webwinkel én beheerdashboard
│               Motion: knoppen, lades, paginaovergangen
│               GSAP:   hero-animatie, 3D-kaarten, scroll-effecten, KPI-tellers
└── backend/    Laravel 13 (PHP 8.4) + PostgreSQL 17
                API, Stripe-betalingen, CJdropshipping, btw-overzicht, e-mail
```

**Waarom PostgreSQL?** Je kiest voor een eigen server (VPS). PostgreSQL is daar standaard beschikbaar (ook via Laravel Forge en Ploi). Het is erg betrouwbaar voor geld- en bestelgegevens (strikte controles, transacties) en kan de gegevens van Stripe en CJ (JSON) netjes opslaan.

**Wat is er veranderd ten opzichte van de vorige versie?**
| Onderdeel | Vroeger | Nu |
|---|---|---|
| Producten | bestand `catalog.js`, met de hand vervangen | database; je bewerkt ze in het dashboard en de wijziging is direct live |
| Bestellingen | alleen in Stripe | in de eigen database (plus Stripe) |
| Btw-overzicht | berekend in de browser | berekend door de server |
| Checklist, campagnes, video's | in de browser | in de database (op elk apparaat hetzelfde) |
| Nieuwsbrief | Netlify Forms | eigen database (dashboard → Instellingen) |
| Dashboard-login | wachtwoord per verzoek | veilige token (7 dagen geldig) en een beperking op het aantal pogingen |

Alle functies van de vorige versie zijn behouden: 8 talen, licht/donker, winkelwagen, verzendzones, Stripe, CJ-automatisering, tracking, btw, juridische pagina's en de AI-voorbeeldmodules.

---

## Lokaal starten (op deze computer)

De programma's zijn al geïnstalleerd: PHP 8.4, Composer, Node.js, Git en PostgreSQL 17.

**1. Backend (API)** — in de map `backend`:
```bash
php artisan serve
```

**2. Frontend** — in een tweede venster, in de map `frontend`:
```bash
npm run dev
```

Open daarna **http://127.0.0.1:5173**. Het dashboard staat op **/beheer**. Het wachtwoord staat in `backend/.env` onder `ADMIN_PASSWORD`.

**Tests draaien** (in de map `backend`):
```bash
php artisan test
```

---

## Instellingen (`backend/.env`)

| Sleutel | Waarvoor |
|---|---|
| `ADMIN_PASSWORD` | Wachtwoord van het dashboard (minimaal 10 tekens) |
| `FRONTEND_URL` | Adres van de site, bijv. `https://rebazz.nl` (voor de Stripe-terugkeerpagina) |
| `STRIPE_SECRET` | Geheime sleutel van Stripe (`sk_test_…` om te testen, daarna `sk_live_…`) |
| `STRIPE_WEBHOOK_SECRET` | Ondertekeningssleutel van de Stripe-webhook (`whsec_…`) |
| `STRIPE_TAX` | `on` als je Stripe Tax gebruikt |
| `CJ_API_KEY` | API-sleutel van CJdropshipping |
| `CJ_AUTO_PAY` | `on` = CJ-bestellingen direct betalen met je CJ-saldo |
| `MAIL_MAILER` en de andere `MAIL_…`-sleutels | E-mail naar klanten (bijv. via Resend, Postmark of de SMTP van je mailprovider) |

**Stripe-webhook:** stel in Stripe als adres `https://jouwdomein.nl/api/stripe/webhook` in, met de gebeurtenissen `checkout.session.completed` en `checkout.session.async_payment_succeeded`.

---

## Online zetten op je VPS

**Aanbevolen:** een VPS met Ubuntu 24.04 (bijv. bij Hetzner) en **Laravel Forge** of **Ploi** voor het beheer. Die regelen PHP, PostgreSQL, Nginx, HTTPS (Let's Encrypt), de scheduler en het uitrollen voor je.

1. Zet het project op GitHub (privé).
2. Maak in Forge/Ploi een server met **PHP 8.4**, **PostgreSQL** en **Node.js**.
3. Maak een site aan voor `rebazz.nl` met als map **`backend/public`**.
4. Vul de `.env` in (zie de tabel hierboven). Zet daarbij ook `APP_ENV=production`, `APP_DEBUG=false` en `APP_URL=https://rebazz.nl`.
5. Gebruik dit **deploy-script**:
   ```bash
   cd backend
   composer install --no-dev --optimize-autoloader
   php artisan migrate --force
   php artisan db:seed --force      # alleen de eerste keer: zet de 10 producten in de database
   php artisan config:cache && php artisan route:cache
   cd ../frontend
   npm ci && npm run build          # bouwt de webwinkel naar backend/public/app
   ```
6. Zet de **scheduler** aan. Forge en Ploi doen dit met één klik; anders voeg je deze cronregel toe:
   ```bash
   * * * * * cd /pad/naar/backend && php artisan schedule:run >> /dev/null 2>&1
   ```
   Daarmee worden elke 2 uur de trackingcodes bij CJ opgehaald.
7. Koppel je domein en zet HTTPS aan (in Forge/Ploi met één klik).
8. Had je al bestellingen in Stripe uit de vorige versie? Haal ze dan éénmalig op met:
   ```bash
   php artisan shop:import-stripe
   ```

---

## Wat is getest

- **15 automatische tests** (86 controles) van de API met de PostgreSQL-database:
  - prijzen komen altijd uit de database;
  - verborgen producten en onbekende landen worden geweigerd;
  - de webhook slaat elke bestelling precies één keer op, en een valse handtekening wordt geweigerd;
  - bestelling volgen werkt alleen met het juiste e-mailadres;
  - CJ: per magazijn aparte bestellingen, met IOSS;
  - dashboard: inloggen, producten, terugbetalen (niet dubbel) en de btw-berekening;
  - instellingen: alleen toegestane sleutels.
- **In de browser met alle onderdelen samen** (frontend → API → database):
  - catalogus, winkelwagen en nieuwsbrief;
  - afrekenen (geeft netjes aan dat Stripe nog niet is ingesteld);
  - in het dashboard: inloggen, een bestelling verwerken met trackingcode, de klant die de bestelling volgt, het btw-overzicht, en een prijswijziging die direct zichtbaar is in de winkel;
  - de productieversie via Laravel, op telefoonformaat en in het Duits.

**Nog niet getest met echte accounts:** Stripe, CJdropshipping en e-mail. Dat kan pas als jij je eigen sleutels hebt; test dan eerst met de testsleutels van Stripe.
