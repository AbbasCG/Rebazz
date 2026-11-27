<?php

namespace App\Mail;

use App\Models\Setting;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Verificatiemail bij een privacyverzoek: wie de link kan openen, beheert dit mailadres. De link is 1 uur geldig. */
class PrivacyRequestMail extends Mailable
{
    use Queueable;

    private const T = [
        'nl' => ['export' => ['Uw gegevens downloaden', 'U heeft gevraagd om een kopie van uw persoonsgegevens. Download ze via deze link (1 uur geldig):'], 'erase' => ['Uw gegevens laten wissen', 'U heeft gevraagd om uw persoonsgegevens te wissen. Bevestig dit via deze link (1 uur geldig). Let op: gegevens die wij wettelijk moeten bewaren (bestel- en factuurgegevens, 7 jaar) blijven bewaard en worden daarna geanonimiseerd.'], 'ignore' => 'Heeft u dit niet aangevraagd? Dan kunt u deze e-mail negeren; er gebeurt niets.'],
        'en' => ['export' => ['Download your data', 'You asked for a copy of your personal data. Download it with this link (valid for 1 hour):'], 'erase' => ['Erase your data', 'You asked us to erase your personal data. Confirm with this link (valid for 1 hour). Note: data we are legally required to keep (order and invoice records, 7 years) is retained and anonymised afterwards.'], 'ignore' => 'Did not request this? You can ignore this email; nothing will happen.'],
        'de' => ['export' => ['Ihre Daten herunterladen', 'Sie haben eine Kopie Ihrer personenbezogenen Daten angefordert. Laden Sie sie über diesen Link herunter (1 Stunde gültig):'], 'erase' => ['Ihre Daten löschen', 'Sie haben die Löschung Ihrer personenbezogenen Daten verlangt. Bestätigen Sie dies über diesen Link (1 Stunde gültig). Hinweis: gesetzlich aufbewahrungspflichtige Daten (Bestell- und Rechnungsdaten, 7 Jahre) bleiben erhalten und werden danach anonymisiert.'], 'ignore' => 'Nicht angefordert? Dann ignorieren Sie diese E-Mail; es passiert nichts.'],
        'fr' => ['export' => ['Télécharger vos données', 'Vous avez demandé une copie de vos données personnelles. Téléchargez-les via ce lien (valable 1 heure) :'], 'erase' => ['Effacer vos données', 'Vous avez demandé l’effacement de vos données personnelles. Confirmez via ce lien (valable 1 heure). Les données que nous devons légalement conserver (commandes et factures, 7 ans) sont conservées puis anonymisées.'], 'ignore' => 'Vous n’êtes pas à l’origine de cette demande ? Ignorez cet e-mail ; rien ne se passera.'],
        'es' => ['export' => ['Descargar sus datos', 'Ha solicitado una copia de sus datos personales. Descárguela con este enlace (válido 1 hora):'], 'erase' => ['Borrar sus datos', 'Ha solicitado el borrado de sus datos personales. Confírmelo con este enlace (válido 1 hora). Los datos que debemos conservar por ley (pedidos y facturas, 7 años) se mantienen y luego se anonimizan.'], 'ignore' => '¿No lo solicitó? Ignore este correo; no ocurrirá nada.'],
        'it' => ['export' => ['Scarica i tuoi dati', 'Hai richiesto una copia dei tuoi dati personali. Scaricala con questo link (valido 1 ora):'], 'erase' => ['Cancella i tuoi dati', 'Hai chiesto la cancellazione dei tuoi dati personali. Conferma con questo link (valido 1 ora). I dati che dobbiamo conservare per legge (ordini e fatture, 7 anni) restano e vengono poi anonimizzati.'], 'ignore' => 'Non l’hai richiesto? Ignora questa e-mail; non succederà nulla.'],
        'pl' => ['export' => ['Pobierz swoje dane', 'Poprosiłeś o kopię swoich danych osobowych. Pobierz ją pod tym linkiem (ważny 1 godzinę):'], 'erase' => ['Usuń swoje dane', 'Poprosiłeś o usunięcie danych osobowych. Potwierdź pod tym linkiem (ważny 1 godzinę). Dane, które musimy przechowywać z mocy prawa (zamówienia i faktury, 7 lat), zostają zachowane, a potem zanonimizowane.'], 'ignore' => 'Nie prosiłeś o to? Zignoruj tę wiadomość; nic się nie stanie.'],
        'ar' => ['export' => ['تنزيل بياناتك', 'طلبتَ نسخة من بياناتك الشخصية. نزّلها عبر هذا الرابط (صالح لمدة ساعة):'], 'erase' => ['حذف بياناتك', 'طلبتَ حذف بياناتك الشخصية. أكّد ذلك عبر هذا الرابط (صالح لمدة ساعة). ملاحظة: البيانات التي يُلزمنا القانون بحفظها (الطلبات والفواتير، 7 سنوات) تبقى ثم تُجهَّل هويتها.'], 'ignore' => 'لم تطلب ذلك؟ تجاهل هذه الرسالة ولن يحدث شيء.'],
    ];

    public function __construct(public string $type, public string $url, public string $lang = 'nl') {}

    private function t(): array
    {
        return self::T[array_key_exists($this->lang, self::T) ? $this->lang : 'en'];
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->t()[$this->type][0], replyTo: [Setting::brand()['email']]);
    }

    public function content(): Content
    {
        $t = $this->t();
        $dir = $this->lang === 'ar' ? 'rtl' : 'ltr';
        $html = '<div dir="'.$dir.'" style="font-family:Arial,sans-serif;font-size:15px;color:#14161b;max-width:560px">'
            .'<p>'.e($t[$this->type][1]).'</p>'
            .'<p><a href="'.e($this->url).'" style="display:inline-block;background:#14161b;color:#fff;padding:12px 18px;border-radius:8px;text-decoration:none">'.e($t[$this->type][0]).'</a></p>'
            .'<p style="font-size:13px;color:#666">'.e($t['ignore']).'</p></div>';

        return new Content(htmlString: $html);
    }
}
