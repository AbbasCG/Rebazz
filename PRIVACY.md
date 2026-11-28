# Rebazz: persoonsgegevens (concept-verwerkingsregister, AVG)

Dit is een **technisch overzicht van wat de software doet**, bedoeld als startpunt voor uw verwerkingsregister (art. 30 AVG), privacyverklaring en gesprekken met uw jurist. Het is geen juridisch advies; controleer en vul aan met uw eigen situatie (verwerkers, rechtsgronden, doelen).

## Welke gegevens, waarom, hoe lang

| Gegevens | Waar | Doel | Grondslag (voorstel) | Bewaartermijn |
|---|---|---|---|---|
| Naam, adres, land, e-mail, telefoon, bestelde artikelen, bedragen, betaalmethode (soort, geen kaartnummers) | tabel `orders`, `order_items` | Bestelling uitvoeren, factuur, aangifte | Uitvoering overeenkomst; wettelijke verplichting (fiscaal) | **7 jaar** (`SHOP_RETENTION_YEARS`), daarna automatisch geanonimiseerd. Telefoon en vrije tekst worden op verzoek eerder gewist. |
| Herinnering verlaten winkelwagen (optioneel): e-mail, taal, Stripe-herstel-link, tijdstip | tabel `abandoned_carts` | Eén herinnering sturen | Toestemming (Stripe vraagt die in de kassa; alleen bij "opt_in") | Link wordt na verzending gewist; rij na **30 dagen** (`shop:gdpr-retention`) |
| Nieuwsbrief-aflevering: e-mail per verstuurde campagne | tabel `newsletter_deliveries` | Dubbel versturen voorkomen | Zelfde als nieuwsbrief | **90 dagen** na afronden van de campagne; wordt ook bij wissen verwijderd |
| Retouraanvragen: reden, toelichting klant, bericht winkel | `order_returns` | Retour afhandelen, creditnota | Uitvoering overeenkomst; wettelijke verplichting | Zoals de bestelling (toelichting wordt eerder gewist op verzoek) |
| Klantaccount (optioneel): e-mail, naam, tijd van laatste login, verlanglijst; inlogcodes (alleen een hash, 10 minuten geldig) | tabellen `customers`, `wishlist_items`, `login_codes` | Inzicht in eigen bestellingen, retour, verlanglijst | Uitvoering overeenkomst / toestemming (klant maakt het account zelf aan) | Account na **3 jaar** zonder login automatisch gewist (`shop:gdpr-retention`); klant kan het zelf wissen onder Profiel; verlopen inlogcodes worden dagelijks opgeruimd. Bestelgegevens volgen de 7 jaar. |
| Nieuwsbriefaanmelding: e-mail, taal, tijdstip bevestiging | `newsletter_subscribers` | Nieuwsbrief | Toestemming (dubbele opt-in; `confirmed_at` is het bewijs) | Tot afmelding; niet-bevestigde aanmeldingen na **30 dagen** gewist |
| Privacyverzoeken: alleen hash van het e-mailadres, soort, datum, samenvatting | `privacy_requests` | Verantwoording (art. 5 lid 2) | Wettelijke verplichting | **3 jaar** |
| Statistieken (optioneel): willekeurig bezoeker-id, sessie-id, soort gebeurtenis, pagina zonder query, bekeken product, verwijzende host, utm-campagne, apparaatklasse, bedrag bij aankoop | tabel `analytics_events` | Begrijpen hoe de winkel wordt gebruikt | Toestemming (cookiebanner "analytisch"; ook niet bij Do Not Track / Global Privacy Control) | **13 maanden** (`SHOP_ANALYTICS_MONTHS`); bij intrekken van toestemming wordt alles van dat bezoeker-id direct gewist. Geen IP-adres, user-agent of e-mailadres; het id staat alleen in de browser (`localStorage`), niet gekoppeld aan bestellingen of accounts. |
| Cookiekeuze (analytics/marketing weigeren of toestaan) | alleen browser (`localStorage`) | Toestemming onthouden | Toestemmingsregistratie | In de browser van de bezoeker. Eigen statistieken (hierboven) meten alleen na toestemming; er worden geen trackers van derden geladen. |
| Beheer-activiteitenlogboek: wie (naam, e-mail), welke actie (route, uitkomst), IP-adres, tijd; nooit wachtwoorden of inhoud | tabel `admin_audit_logs` | Beveiliging en verantwoording van medewerkers | Gerechtvaardigd belang (art. 6 lid 1 f) | **12 maanden** (`shop:gdpr-retention`) |
| Medewerkersaccounts: naam, e-mail, rol, wachtwoord-hash, versleuteld 2FA-geheim en hashes van herstelcodes | tabel `users` | Toegang tot het beheer | Gerechtvaardigd belang / arbeidsovereenkomst | Tot het account wordt verwijderd |
| AI-gebruik: medewerker, functie, model, aantal tokens, tijd; nooit de tekst zelf | tabel `ai_usage` | Limieten en kostenbewaking | Gerechtvaardigd belang | **12 maanden** (`shop:gdpr-retention`) |
| Logbestanden (`storage/logs`) | server | Foutopsporing | Gerechtvaardigd belang | Houd ze kort (bijv. `LOG_STACK=daily` met 14 dagen) en let op: bij `MAIL_MAILER=log` komt mailinhoud (e-mailadressen) in het log. |

Niet opgeslagen: kaartgegevens (Stripe-pagina), wachtwoorden van klanten (er zijn geen klantaccounts), IP-adressen in de database.

## Ontvangers / verwerkers (voor uw register en verwerkersovereenkomsten)
- **Stripe**: betalingen (bevat naam, e-mail, adres, betaalgegevens). Eigen verantwoordelijke voor fraude/witwassen.
- **Anthropic (Claude, VS)**: alleen als u de AI-functies in het beheer gebruikt (`ANTHROPIC_API_KEY`). Verzonden wordt: productgegevens (naam, kenmerken, prijs; nooit inkoopprijs of leverancier), en bij klantenservice-hulp de tekst van een klantbericht met e-mailadressen, telefoonnummers en IBAN's eruit gehaald, plus verzend-/retourgegevens van een bestelling zonder naam, adres, e-mail of telefoon. Bij Anthropic geldt een verwerkersovereenkomst via hun voorwaarden; neem Anthropic op in uw register, vermeld de doorgifte naar de VS (SCC's/DPF) in uw privacyverklaring, en vul in het klantenservice-proces aan dat berichten voor een conceptantwoord door een AI-dienst worden verwerkt. Klanten krijgen nooit automatisch een AI-antwoord: een medewerker leest en verstuurt zelf.
- **CJdropshipping** (China): voert bestellingen uit; ontvangt naam, adres, telefoon, e-mail. Doorgifte buiten de EER: vraag om SCC's/afspraken en vermeld dit in uw privacyverklaring.
- **Mailprovider / SMTP**: verstuurt orderbevestigingen en andere e-mails.
- **Hosting/database**: waar de applicatie en de PostgreSQL-database draaien.
- Optioneel later: analytics- of advertentiediensten (pas laden na toestemming via `src/lib/consent.ts`).

## Rechten van betrokkenen: wat de software doet
- **Inzage/export (art. 15, 20)**: klant gaat naar **Mijn gegevens** (footer), vult het e-mailadres in en ontvangt een e-mail met een link (1 uur geldig, ondertekend). Via die link downloadt hij een JSON met alles dat bij dat adres hoort (bestellingen, retouren, nieuwsbrief), zonder interne gegevens zoals inkoopprijzen. Het antwoord op het formulier is altijd hetzelfde, zodat niet te achterhalen is of een adres bij u bekend is.
- **Wissen (art. 17)**: zelfde route met bevestiging. Uitkomst: nieuwsbriefaanmelding **verwijderd**; telefoonnummer en vrije tekst bij bestellingen **gewist**; bestellingen ouder dan de bewaartermijn **volledig geanonimiseerd**. Bestel- en factuurgegevens binnen de bewaartermijn blijven bewaard (wettelijke verplichting, art. 17 lid 3 sub b), met vermelding tot wanneer. Dit wordt de klant ook zo getoond.
- **Klantaccount**: ingelogde klanten downloaden hun gegevens (JSON) of wissen account + gegevens zelf onder **Mijn account → Profiel**, zonder e-mailverificatie (het account is al geverifieerd). Bestelgegevens blijven zoals bij Wissen hierboven wettelijk bewaard.
- **Beheerder**: onder **Privacy (AVG)** in het beheer: zoeken op e-mailadres, exporteren, wissen/anonimiseren, en een logboek van verzoeken (zonder e-mailadres).
- **Afmelden nieuwsbrief**: ondertekende afmeldlink (`newsletter.unsubscribe`); de ingebouwde nieuwsbrief (beheer → Nieuwsbrief) zet die link en de `List-Unsubscribe`-kop (eén-klik) automatisch in iedere mail, samen met uw bedrijfsgegevens. Alleen bevestigde abonnees ontvangen iets.
- **Rectificatie (art. 16)**, **beperking** en **bezwaar**: niet geautomatiseerd; afhandelen via e-mail aan het adres in de privacyverklaring. Het adres op een bestelling aanpassen kan alleen in het beheer vóór verzending.
- **Termijn**: u moet binnen **1 maand** reageren; de verificatielink telt niet als afhandeling zolang de klant hem niet gebruikt.

## Wat u zelf nog moet doen
1. Privacyverklaring laten schrijven/controleren (zie ook `juridisch.html`-teksten) en deze procedure daarin noemen.
2. **Verwerkersovereenkomsten** met mailprovider en hosting; afspraken met CJ over doorgifte.
3. Bij een **verwijderverzoek** ook de gegevens bij **Stripe** en **CJ** laten verwijderen waar dat mag (eigen omgeving; niet geautomatiseerd).
4. Logrotatie instellen en back-ups zo bewaren dat gewiste gegevens niet eeuwig in oude back-ups blijven (bewaartermijn van back-ups vastleggen).
5. Een datalek (art. 33): meldplicht binnen 72 uur bij de Autoriteit Persoonsgegevens; leg uw procedure vast.
