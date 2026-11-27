# Rebazz: persoonsgegevens (concept-verwerkingsregister, AVG)

Dit is een **technisch overzicht van wat de software doet**, bedoeld als startpunt voor uw verwerkingsregister (art. 30 AVG), privacyverklaring en gesprekken met uw jurist. Het is geen juridisch advies; controleer en vul aan met uw eigen situatie (verwerkers, rechtsgronden, doelen).

## Welke gegevens, waarom, hoe lang

| Gegevens | Waar | Doel | Grondslag (voorstel) | Bewaartermijn |
|---|---|---|---|---|
| Naam, adres, land, e-mail, telefoon, bestelde artikelen, bedragen, betaalmethode (soort, geen kaartnummers) | tabel `orders`, `order_items` | Bestelling uitvoeren, factuur, aangifte | Uitvoering overeenkomst; wettelijke verplichting (fiscaal) | **7 jaar** (`SHOP_RETENTION_YEARS`), daarna automatisch geanonimiseerd. Telefoon en vrije tekst worden op verzoek eerder gewist. |
| Retouraanvragen: reden, toelichting klant, bericht winkel | `order_returns` | Retour afhandelen, creditnota | Uitvoering overeenkomst; wettelijke verplichting | Zoals de bestelling (toelichting wordt eerder gewist op verzoek) |
| Nieuwsbriefaanmelding: e-mail, taal, tijdstip bevestiging | `newsletter_subscribers` | Nieuwsbrief | Toestemming (dubbele opt-in; `confirmed_at` is het bewijs) | Tot afmelding; niet-bevestigde aanmeldingen na **30 dagen** gewist |
| Privacyverzoeken: alleen hash van het e-mailadres, soort, datum, samenvatting | `privacy_requests` | Verantwoording (art. 5 lid 2) | Wettelijke verplichting | **3 jaar** |
| Cookiekeuze (analytics/marketing weigeren of toestaan) | alleen browser (`localStorage`) | Toestemming onthouden | Toestemmingsregistratie | In de browser van de bezoeker. Er worden nu geen trackers geladen. |
| Logbestanden (`storage/logs`) | server | Foutopsporing | Gerechtvaardigd belang | Houd ze kort (bijv. `LOG_STACK=daily` met 14 dagen) en let op: bij `MAIL_MAILER=log` komt mailinhoud (e-mailadressen) in het log. |

Niet opgeslagen: kaartgegevens (Stripe-pagina), wachtwoorden van klanten (er zijn geen klantaccounts), IP-adressen in de database.

## Ontvangers / verwerkers (voor uw register en verwerkersovereenkomsten)
- **Stripe**: betalingen (bevat naam, e-mail, adres, betaalgegevens). Eigen verantwoordelijke voor fraude/witwassen.
- **CJdropshipping** (China): voert bestellingen uit; ontvangt naam, adres, telefoon, e-mail. Doorgifte buiten de EER: vraag om SCC's/afspraken en vermeld dit in uw privacyverklaring.
- **Mailprovider / SMTP**: verstuurt orderbevestigingen en andere e-mails.
- **Hosting/database**: waar de applicatie en de PostgreSQL-database draaien.
- Optioneel later: analytics- of advertentiediensten (pas laden na toestemming via `src/lib/consent.ts`).

## Rechten van betrokkenen: wat de software doet
- **Inzage/export (art. 15, 20)**: klant gaat naar **Mijn gegevens** (footer), vult het e-mailadres in en ontvangt een e-mail met een link (1 uur geldig, ondertekend). Via die link downloadt hij een JSON met alles dat bij dat adres hoort (bestellingen, retouren, nieuwsbrief), zonder interne gegevens zoals inkoopprijzen. Het antwoord op het formulier is altijd hetzelfde, zodat niet te achterhalen is of een adres bij u bekend is.
- **Wissen (art. 17)**: zelfde route met bevestiging. Uitkomst: nieuwsbriefaanmelding **verwijderd**; telefoonnummer en vrije tekst bij bestellingen **gewist**; bestellingen ouder dan de bewaartermijn **volledig geanonimiseerd**. Bestel- en factuurgegevens binnen de bewaartermijn blijven bewaard (wettelijke verplichting, art. 17 lid 3 sub b), met vermelding tot wanneer. Dit wordt de klant ook zo getoond.
- **Beheerder**: onder **Privacy (AVG)** in het beheer: zoeken op e-mailadres, exporteren, wissen/anonimiseren, en een logboek van verzoeken (zonder e-mailadres).
- **Afmelden nieuwsbrief**: ondertekende afmeldlink (`newsletter.unsubscribe`); **neem die link op in elke nieuwsbrief** die u verstuurt (de winkel verstuurt zelf nog geen nieuwsbrieven).
- **Rectificatie (art. 16)**, **beperking** en **bezwaar**: niet geautomatiseerd; afhandelen via e-mail aan het adres in de privacyverklaring. Het adres op een bestelling aanpassen kan alleen in het beheer vóór verzending.
- **Termijn**: u moet binnen **1 maand** reageren; de verificatielink telt niet als afhandeling zolang de klant hem niet gebruikt.

## Wat u zelf nog moet doen
1. Privacyverklaring laten schrijven/controleren (zie ook `juridisch.html`-teksten) en deze procedure daarin noemen.
2. **Verwerkersovereenkomsten** met mailprovider en hosting; afspraken met CJ over doorgifte.
3. Bij een **verwijderverzoek** ook de gegevens bij **Stripe** en **CJ** laten verwijderen waar dat mag (eigen omgeving; niet geautomatiseerd).
4. Logrotatie instellen en back-ups zo bewaren dat gewiste gegevens niet eeuwig in oude back-ups blijven (bewaartermijn van back-ups vastleggen).
5. Een datalek (art. 33): meldplicht binnen 72 uur bij de Autoriteit Persoonsgegevens; leg uw procedure vast.
