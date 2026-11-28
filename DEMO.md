# Rebazz lokaal uitproberen (testgegevens)

Alles hieronder is verzonnen: e-mailadressen `@example.com`, Stripe-ID's `demo`, CJ-ID's `DEMO-…`. De seeder draait niet in productie.

## Starten
```bash
cd backend
cp .env.example .env            # vul DB_* in (PostgreSQL) en zet een eigen ADMIN_PASSWORD
php artisan key:generate
php artisan migrate
php artisan db:seed --class=DemoSeeder      # opnieuw uitvoeren mag: demo-bestellingen worden bijgewerkt
php artisan serve                           # http://127.0.0.1:8000
```
Vereist PHP 8.4+ en een lege PostgreSQL-database. De gebouwde winkel staat in `backend/public/app` (`cd frontend && npm ci && npm run build`).
Frontend ontwikkelen met live reload: `cd frontend && npm run dev` (http://localhost:5173, stuurt `/api` door naar poort 8000).

## Wat staat erin
- **9 actieve producten** met GPSR-gegevens (fabrikant, EU-verantwoordelijke, veiligheidsinfo) en voorraad. De eerste zes hebben een fictief CJ-variant-ID, de rest test het pad "niet automatisch besteld".
- **9 bestellingen** in verschillende toestanden (zie tabel), **3 nieuwsbriefabonnees**.

| Bestelling | Land | Toestand | Wat u hiermee test |
|---|---|---|---|
| RB-DEMO01 | NL | bezorgd (stap 4) | afgeronde bestelling, btw NL |
| RB-DEMO02 | DE | verzonden (stap 2) met tracking | **bestelling volgen**: `RB-DEMO02` + `demo2@example.com` |
| RB-DEMO03 | FR | besteld bij CJ (stap 1) | wachten op tracking |
| RB-DEMO04 | BE | `manual` (geen CJ-variant) | filter "Actie nodig" in het beheer |
| RB-DEMO05 | ES | `error` (CJ-saldo onvoldoende) | knop "opnieuw bestellen" |
| RB-DEMO06 | IT | `placing`, 45 min vast | melding vastgelopen bestelling (`php artisan shop:check-stuck`) |
| RB-DEMO07 | PL | terugbetaald | uitgesloten van btw-overzicht |
| RB-DEMO08 | AT | verzonden, vertraagd | vertraagd-markering |
| RB-DEMO09 | US | besteld bij CJ | export, geen btw |

## Proberen
1. **Winkel:** http://127.0.0.1:8000 — producten bekijken (bij een product staat "Productveiligheid en fabrikant"), winkelwagen, taalwissel (ook Arabisch/RTL), cookiebanner, **Bestelling volgen** met de gegevens hierboven, herroepingsformulier onder `/juridisch` (mail gaat naar `storage/logs/laravel.log` zolang `MAIL_MAILER=log`).
2. **Beheer:** http://127.0.0.1:8000/beheer — inloggen met `ADMIN_PASSWORD` uit uw `backend/.env`. Bekijk Bestellingen (filters), Producten (GPSR-velden, negatieve voorraad wordt geweigerd), Btw-overzicht, Instellingen.
2d. **Team en 2FA:** in het beheer onder *Team & rollen* een medewerker toevoegen (bijv. rol Klantenservice) en daarmee inloggen op /beheer met e-mail + wachtwoord: het menu toont alleen wat de rol mag. Onder *Mijn account* kunt u 2FA instellen (sleutel in een authenticator-app typen; voor een test kunt u de code ook uitrekenen via `php artisan tinker`). Draai na een update eerst `php artisan migrate`.
2c. **Statistieken:** het beheer heeft een scherm *Statistieken* met verzonnen demo-metingen (funnel, bronnen, producten). Zelf meten: open de winkel, kies in de banner *Alles accepteren* en blader/leg iets in de wagen; met `?utm_source=…&utm_medium=…&utm_campaign=…` in de link ziet u de campagne terug. Zonder toestemming, of bij Do Not Track/GPC, wordt niets opgeslagen; *Alleen noodzakelijk* kiezen wist de metingen van uw browser. Draai na een update eerst `php artisan migrate`.
2b. **Nieuwsbrief:** in het beheer onder *Nieuwsbrief* een concept schrijven, *Testmail* sturen en *Versturen* kiezen; draai daarna `php artisan shop:send-newsletters` (of laat de scheduler lopen). Alle mails verschijnen in `storage/logs/laravel.log` (`MAIL_MAILER=log`). De demo heeft 3 bevestigde abonnees. Draai na een update eerst `php artisan migrate`.
2a. **Klantaccount:** ga naar http://127.0.0.1:8000/account en log in met bijv. `demo1@example.com` (heeft bestelling RB-DEMO01 met retouren). De inlogcode staat in `backend/storage/logs/laravel.log` (zoek op "Uw inlogcode", want `MAIL_MAILER=log`). Op een productkaart zet het hartje het product op de verlanglijst; zonder login blijft die lokaal en na inloggen wordt hij samengevoegd. Draai na een update eerst `php artisan migrate`.
3. **Vastgelopen bestelling:** zet in `.env` een willekeurige `CJ_API_KEY=test` en draai `php artisan shop:check-stuck`; de melding verschijnt in `storage/logs/laravel.log`.
4. **Tests:** `php artisan test` (zet `DB_*` naar een aparte lege database `rebazz_test`).

## Echt betalen testen (Stripe testmodus)
De demo-bestellingen staan direct in de database; een echte betaling vereist een eigen Stripe-**testaccount**:
1. Zet in `.env`: `STRIPE_SECRET=sk_test_…` (uit het Stripe-dashboard, testmodus).
2. Stripe CLI voor de webhook: `stripe listen --forward-to http://127.0.0.1:8000/api/stripe/webhook`, zet het getoonde `whsec_…` in `STRIPE_WEBHOOK_SECRET`.
3. Betaal met testkaart `4242 4242 4242 4242`, willekeurige toekomstige datum en cvc.
4. Controleer: bestelling verschijnt in het beheer; terugbetalen vanuit Stripe zet de bestelling op "terugbetaald" (`charge.refunded`).

Laat `CJ_API_KEY` leeg tijdens het testen: dan wordt niets bij CJ besteld en gaan bestellingen naar `manual`.
