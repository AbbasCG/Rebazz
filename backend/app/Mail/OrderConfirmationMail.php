<?php

namespace App\Mail;

use App\Models\Order;
use App\Models\Setting;
use App\Services\InvoiceService;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/** Orderbevestiging aan de klant (in de taal van de klant) met de factuur als PDF-bijlage en een downloadlink. */
class OrderConfirmationMail extends Mailable
{
    use Queueable;

    /** [onderwerp, aanhef, intro, "Uw bestelling", verzending, totaal, bezorgadres, factuur-tekst, factuur-link, volgen, groet] */
    private const TEXT = [
        'nl' => ['Bevestiging van uw bestelling {id}', 'Geachte klant,', 'Bedankt voor uw bestelling bij {shop}. Wij hebben uw betaling ontvangen en gaan uw bestelling verwerken.', 'Uw bestelling', 'Verzending', 'Totaal (incl. btw)', 'Bezorgadres', 'Uw factuur vindt u als PDF in de bijlage.', 'Factuur downloaden', 'U kunt uw bestelling volgen op {url} met uw bestelnummer en dit e-mailadres.', 'Met vriendelijke groet,'],
        'en' => ['Confirmation of your order {id}', 'Dear customer,', 'Thank you for your order at {shop}. We have received your payment and will start processing your order.', 'Your order', 'Shipping', 'Total (incl. VAT)', 'Delivery address', 'Your invoice is attached as a PDF.', 'Download invoice', 'You can track your order at {url} with your order number and this email address.', 'Kind regards,'],
        'de' => ['Bestätigung Ihrer Bestellung {id}', 'Sehr geehrte Kundin, sehr geehrter Kunde,', 'Vielen Dank für Ihre Bestellung bei {shop}. Wir haben Ihre Zahlung erhalten und bearbeiten Ihre Bestellung.', 'Ihre Bestellung', 'Versand', 'Gesamt (inkl. MwSt.)', 'Lieferadresse', 'Ihre Rechnung finden Sie als PDF im Anhang.', 'Rechnung herunterladen', 'Sie können Ihre Bestellung unter {url} mit Ihrer Bestellnummer und dieser E-Mail-Adresse verfolgen.', 'Mit freundlichen Grüßen'],
        'fr' => ['Confirmation de votre commande {id}', 'Chère cliente, cher client,', 'Merci pour votre commande chez {shop}. Nous avons reçu votre paiement et allons traiter votre commande.', 'Votre commande', 'Livraison', 'Total (TVA incluse)', 'Adresse de livraison', 'Votre facture est jointe en PDF.', 'Télécharger la facture', 'Suivez votre commande sur {url} avec votre numéro de commande et cette adresse e-mail.', 'Cordialement,'],
        'es' => ['Confirmación de su pedido {id}', 'Estimado/a cliente:', 'Gracias por su pedido en {shop}. Hemos recibido su pago y procesaremos su pedido.', 'Su pedido', 'Envío', 'Total (IVA incl.)', 'Dirección de entrega', 'Su factura va adjunta en PDF.', 'Descargar factura', 'Puede seguir su pedido en {url} con su número de pedido y este correo electrónico.', 'Atentamente,'],
        'it' => ['Conferma del tuo ordine {id}', 'Gentile cliente,', 'Grazie per il tuo ordine su {shop}. Abbiamo ricevuto il pagamento e elaboreremo il tuo ordine.', 'Il tuo ordine', 'Spedizione', 'Totale (IVA incl.)', 'Indirizzo di consegna', 'La fattura è allegata in PDF.', 'Scarica la fattura', 'Puoi seguire il tuo ordine su {url} con il numero d’ordine e questo indirizzo e-mail.', 'Cordiali saluti,'],
        'pl' => ['Potwierdzenie zamówienia {id}', 'Szanowni Państwo,', 'Dziękujemy za zamówienie w {shop}. Otrzymaliśmy płatność i przystępujemy do realizacji zamówienia.', 'Twoje zamówienie', 'Wysyłka', 'Razem (z VAT)', 'Adres dostawy', 'Faktura znajduje się w załączniku (PDF).', 'Pobierz fakturę', 'Zamówienie możesz śledzić na {url}, podając numer zamówienia i ten adres e-mail.', 'Z wyrazami szacunku,'],
        'ar' => ['تأكيد طلبك {id}', 'عزيزنا العميل،', 'شكرًا لطلبك من {shop}. استلمنا دفعتك وسنبدأ بتجهيز طلبك.', 'طلبك', 'الشحن', 'الإجمالي (شامل الضريبة)', 'عنوان التسليم', 'فاتورتك مرفقة بصيغة PDF.', 'تنزيل الفاتورة', 'يمكنك تتبّع طلبك عبر {url} باستخدام رقم الطلب وهذا البريد الإلكتروني.', 'مع أطيب التحيات،'],
    ];

    public function __construct(public Order $order) {}

    private function lang(): string
    {
        return array_key_exists($this->order->lang, self::TEXT) ? $this->order->lang : 'en';
    }

    private function money(float $v): string
    {
        return '€ '.number_format($v, 2, ',', '.');
    }

    public function envelope(): Envelope
    {
        $brand = Setting::brand();

        return new Envelope(subject: str_replace('{id}', $this->order->order_no, self::TEXT[$this->lang()][0]), replyTo: [$brand['email']]);
    }

    public function content(): Content
    {
        [$_, $hello, $intro, $yourOrder, $shipLbl, $totalLbl, $addrLbl, $invText, $invLink, $trackText, $bye] = self::TEXT[$this->lang()];
        $brand = Setting::brand();
        $o = $this->order->loadMissing('items');
        $shopUrl = rtrim((string) config('shop.frontend_url'), '/');
        $dir = $this->lang() === 'ar' ? 'rtl' : 'ltr';
        $a = $o->address ?? [];
        $addr = implode(', ', array_filter([$o->customer_name, $a['line1'] ?? null, $a['line2'] ?? null, trim(($a['postal_code'] ?? '').' '.($a['city'] ?? '')), $a['country'] ?? $o->country]));

        $rows = '';
        foreach ($o->items as $i) {
            $rows .= '<tr><td style="padding:4px 0">'.e($i->name.($i->variant_label ? ' — '.$i->variant_label : '')).' × '.(int) $i->qty.'</td><td style="padding:4px 0;text-align:end;white-space:nowrap">'.e($this->money($i->price * $i->qty)).'</td></tr>';
        }
        $rows .= '<tr><td style="padding:4px 0">'.e($shipLbl).'</td><td style="padding:4px 0;text-align:end">'.e($this->money($o->shipping)).'</td></tr>';
        if ($o->discount > 0) {
            $rows .= '<tr><td style="padding:4px 0">−</td><td style="padding:4px 0;text-align:end">− '.e($this->money($o->discount)).'</td></tr>';
        }
        $rows .= '<tr><td style="padding:8px 0;border-top:1px solid #ccc"><b>'.e($totalLbl).'</b></td><td style="padding:8px 0;border-top:1px solid #ccc;text-align:end"><b>'.e($this->money($o->total)).'</b></td></tr>';

        $link = app(InvoiceService::class)->signedUrl($o);
        $html = '<div dir="'.$dir.'" style="font-family:Arial,sans-serif;font-size:15px;color:#14161b;max-width:560px">'
            .'<p>'.e($hello).'</p><p>'.e(str_replace('{shop}', $brand['name'], $intro)).'</p>'
            .'<p><b>'.e($yourOrder).' '.e($o->order_no).'</b></p>'
            .'<table style="width:100%;border-collapse:collapse;font-size:14px">'.$rows.'</table>'
            .'<p style="font-size:14px"><b>'.e($addrLbl).':</b><br>'.e($addr).'</p>'
            .'<p>'.e($invText).' <a href="'.e($link).'">'.e($invLink).'</a></p>'
            .'<p style="font-size:14px">'.str_replace('{url}', '<a href="'.e($shopUrl).'/#volgen">'.e(preg_replace('#^https?://#', '', $shopUrl)).'</a>', e($trackText)).'</p>'
            .'<p>'.e($bye).'<br>'.e($brand['name']).' · '.e($brand['email']).'</p></div>';

        return new Content(htmlString: $html);
    }

    /** @return array<int, Attachment> */
    public function attachments(): array
    {
        try {
            $svc = app(InvoiceService::class);
            $order = $this->order;
            $pdf = $svc->pdf($order);

            return [Attachment::fromData(fn () => $pdf, $svc->filename($order))->withMime('application/pdf')];
        } catch (Throwable $e) {
            Log::warning('Factuur-PDF maken mislukt; bevestiging zonder bijlage', ['order' => $this->order->order_no, 'error' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * Verstuurt de bevestiging precies één keer per bestelling (atomisch via confirmation_sent_at). Bij een fout wordt de
     * markering teruggezet, zodat `shop:send-confirmations` het later opnieuw probeert.
     */
    public static function sendFor(Order $order): bool
    {
        if (! $order->email || $order->status !== 'paid') {
            return false;
        }
        app(InvoiceService::class)->ensureInvoice($order);
        $claimed = Order::whereKey($order->getKey())->whereNull('confirmation_sent_at')->update(['confirmation_sent_at' => now()]);
        if ($claimed === 0) {
            return false;
        }
        try {
            Mail::to($order->email)->send(new static($order->fresh('items')));

            return true;
        } catch (Throwable $e) {
            Order::whereKey($order->getKey())->update(['confirmation_sent_at' => null]);
            Log::error('Orderbevestiging mailen mislukt', ['order' => $order->order_no, 'error' => $e->getMessage()]);

            return false;
        }
    }
}
