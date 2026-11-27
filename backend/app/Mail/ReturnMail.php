<?php

namespace App\Mail;

use App\Models\OrderReturn;
use App\Models\Setting;
use App\Services\InvoiceService;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Mail aan de klant over een retour (aangevraagd, goedgekeurd, afgewezen, terugbetaald), in de taal van de klant. */
class ReturnMail extends Mailable
{
    use Queueable;

    /** Per taal en soort: [onderwerp, tekst]. Plaatsaanduidingen: {rma} {order} {address} {note} {amount} {link} */
    private const T = [
        'nl' => [
            'requested' => ['Uw retouraanvraag {rma}', 'Wij hebben uw retouraanvraag {rma} voor bestelling {order} ontvangen. U hoort binnen 2 werkdagen van ons. Stuur nog niets op voordat u onze goedkeuring heeft.'],
            'approved' => ['Uw retour {rma} is goedgekeurd', 'Uw retour {rma} is goedgekeurd. Stuur de artikelen terug naar: {address}. Vermeld {rma} op het pakket. {note}'],
            'rejected' => ['Uw retouraanvraag {rma}', 'Helaas kunnen wij retour {rma} niet goedkeuren. Reden: {note}. Heeft u vragen? Antwoord dan op deze e-mail.'],
            'refunded' => ['Terugbetaling voor retour {rma}', 'Wij hebben € {amount} teruggestort via uw oorspronkelijke betaalmethode (zichtbaar binnen enkele werkdagen). Uw creditnota: {link}'],
        ],
        'en' => [
            'requested' => ['Your return request {rma}', 'We have received your return request {rma} for order {order}. You will hear from us within 2 business days. Please do not send anything back before we approve it.'],
            'approved' => ['Your return {rma} is approved', 'Your return {rma} is approved. Please send the items back to: {address}. Write {rma} on the parcel. {note}'],
            'rejected' => ['Your return request {rma}', 'Unfortunately we cannot approve return {rma}. Reason: {note}. Questions? Just reply to this email.'],
            'refunded' => ['Refund for return {rma}', 'We have refunded € {amount} to your original payment method (it may take a few business days to show). Your credit note: {link}'],
        ],
        'de' => [
            'requested' => ['Ihre Rücksendeanfrage {rma}', 'Wir haben Ihre Rücksendeanfrage {rma} zur Bestellung {order} erhalten. Sie hören innerhalb von 2 Werktagen von uns. Bitte senden Sie noch nichts zurück, bevor wir zugestimmt haben.'],
            'approved' => ['Ihre Rücksendung {rma} ist genehmigt', 'Ihre Rücksendung {rma} ist genehmigt. Bitte senden Sie die Artikel an: {address}. Notieren Sie {rma} auf dem Paket. {note}'],
            'rejected' => ['Ihre Rücksendeanfrage {rma}', 'Leider können wir die Rücksendung {rma} nicht genehmigen. Grund: {note}. Fragen? Antworten Sie einfach auf diese E-Mail.'],
            'refunded' => ['Erstattung für Rücksendung {rma}', 'Wir haben € {amount} auf Ihr ursprüngliches Zahlungsmittel erstattet (die Gutschrift kann einige Werktage dauern). Ihre Gutschrift: {link}'],
        ],
        'fr' => [
            'requested' => ['Votre demande de retour {rma}', 'Nous avons bien reçu votre demande de retour {rma} pour la commande {order}. Vous aurez de nos nouvelles sous 2 jours ouvrés. N’envoyez rien avant notre accord.'],
            'approved' => ['Votre retour {rma} est accepté', 'Votre retour {rma} est accepté. Renvoyez les articles à : {address}. Indiquez {rma} sur le colis. {note}'],
            'rejected' => ['Votre demande de retour {rma}', 'Nous ne pouvons malheureusement pas accepter le retour {rma}. Motif : {note}. Une question ? Répondez à cet e-mail.'],
            'refunded' => ['Remboursement du retour {rma}', 'Nous avons remboursé {amount} € sur votre moyen de paiement d’origine (quelques jours ouvrés). Votre avoir : {link}'],
        ],
        'es' => [
            'requested' => ['Su solicitud de devolución {rma}', 'Hemos recibido su solicitud de devolución {rma} del pedido {order}. Le responderemos en 2 días hábiles. No envíe nada antes de que lo aprobemos.'],
            'approved' => ['Su devolución {rma} está aprobada', 'Su devolución {rma} está aprobada. Envíe los artículos a: {address}. Indique {rma} en el paquete. {note}'],
            'rejected' => ['Su solicitud de devolución {rma}', 'Lamentablemente no podemos aprobar la devolución {rma}. Motivo: {note}. ¿Dudas? Responda a este correo.'],
            'refunded' => ['Reembolso de la devolución {rma}', 'Hemos reembolsado {amount} € a su método de pago original (puede tardar unos días hábiles). Su nota de crédito: {link}'],
        ],
        'it' => [
            'requested' => ['La tua richiesta di reso {rma}', 'Abbiamo ricevuto la tua richiesta di reso {rma} per l’ordine {order}. Ti risponderemo entro 2 giorni lavorativi. Non spedire nulla prima della nostra approvazione.'],
            'approved' => ['Il tuo reso {rma} è approvato', 'Il tuo reso {rma} è approvato. Spedisci gli articoli a: {address}. Indica {rma} sul pacco. {note}'],
            'rejected' => ['La tua richiesta di reso {rma}', 'Purtroppo non possiamo approvare il reso {rma}. Motivo: {note}. Domande? Rispondi a questa e-mail.'],
            'refunded' => ['Rimborso per il reso {rma}', 'Abbiamo rimborsato {amount} € sul metodo di pagamento originale (possono servire alcuni giorni lavorativi). La tua nota di credito: {link}'],
        ],
        'pl' => [
            'requested' => ['Twoje zgłoszenie zwrotu {rma}', 'Otrzymaliśmy zgłoszenie zwrotu {rma} do zamówienia {order}. Odpowiemy w ciągu 2 dni roboczych. Prosimy nic nie odsyłać przed naszą akceptacją.'],
            'approved' => ['Zwrot {rma} został zaakceptowany', 'Zwrot {rma} został zaakceptowany. Odeślij produkty na adres: {address}. Na paczce wpisz {rma}. {note}'],
            'rejected' => ['Twoje zgłoszenie zwrotu {rma}', 'Niestety nie możemy zaakceptować zwrotu {rma}. Powód: {note}. Pytania? Odpowiedz na ten e-mail.'],
            'refunded' => ['Zwrot środków za zwrot {rma}', 'Zwróciliśmy {amount} € na pierwotną metodę płatności (może to potrwać kilka dni roboczych). Twoja faktura korygująca: {link}'],
        ],
        'ar' => [
            'requested' => ['طلب الإرجاع {rma}', 'استلمنا طلب الإرجاع {rma} للطلب {order}. سنرد عليك خلال يومي عمل. يرجى عدم إرسال أي شيء قبل موافقتنا.'],
            'approved' => ['تمت الموافقة على الإرجاع {rma}', 'تمت الموافقة على الإرجاع {rma}. أرسل المنتجات إلى: {address}. اكتب {rma} على الطرد. {note}'],
            'rejected' => ['طلب الإرجاع {rma}', 'للأسف لا يمكننا الموافقة على الإرجاع {rma}. السبب: {note}. لأي استفسار ردّ على هذه الرسالة.'],
            'refunded' => ['استرداد مبلغ الإرجاع {rma}', 'أعدنا {amount} € إلى وسيلة الدفع الأصلية (قد يستغرق ظهوره بضعة أيام عمل). إشعارك الدائن: {link}'],
        ],
    ];

    public function __construct(public OrderReturn $return, public string $kind) {}

    private function lang(): string
    {
        return array_key_exists($this->return->order->lang, self::T) ? $this->return->order->lang : 'en';
    }

    private function fill(string $s, bool $html = false): string
    {
        $brand = Setting::brand();
        $r = $this->return;
        $link = $this->kind === 'refunded' && $r->credit_note_number ? app(InvoiceService::class)->returnSignedUrl($r) : '';
        $vars = [
            '{rma}' => $r->rma_number, '{order}' => $r->order->order_no, '{address}' => $brand['returnAddress'] ?? $brand['address'] ?? '',
            '{note}' => (string) $r->admin_note, '{amount}' => number_format((float) $r->refund_amount, 2, ',', '.'),
        ];
        $s = strtr($s, $vars);
        if (! $html) {
            return str_replace('{link}', $link, $s);
        }
        $out = e($s);

        return str_replace('{link}', $link ? '<a href="'.e($link).'">'.e($r->credit_note_number).'</a>' : '', $out);
    }

    public function envelope(): Envelope
    {
        $brand = Setting::brand();

        return new Envelope(subject: $this->fill(self::T[$this->lang()][$this->kind][0]), replyTo: [$brand['email']]);
    }

    public function content(): Content
    {
        $brand = Setting::brand();
        $dir = $this->lang() === 'ar' ? 'rtl' : 'ltr';
        $items = $this->return->items->map(fn ($i) => '<li>'.e($i->orderItem->name.($i->orderItem->variant_label ? ' — '.$i->orderItem->variant_label : '')).' × '.(int) $i->qty.'</li>')->implode('');
        $html = '<div dir="'.$dir.'" style="font-family:Arial,sans-serif;font-size:15px;color:#14161b;max-width:560px">'
            .'<p>'.$this->fill(self::T[$this->lang()][$this->kind][1], true).'</p>'
            .($items ? '<ul style="padding-inline-start:18px">'.$items.'</ul>' : '')
            .'<p>'.e($brand['name']).' · '.e($brand['email']).'</p></div>';

        return new Content(htmlString: $html);
    }
}
