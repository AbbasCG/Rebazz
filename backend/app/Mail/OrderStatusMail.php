<?php

namespace App\Mail;

use App\Models\Order;
use App\Models\Setting;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/** Statusmail aan de klant, in de taal van de klant. */
class OrderStatusMail extends Mailable
{
    use Queueable;

    private const STATUS = [
        'nl' => ['Betaald', 'In behandeling', 'Verzonden', 'Onderweg', 'Afgeleverd'],
        'en' => ['Paid', 'Processing', 'Shipped', 'In transit', 'Delivered'],
        'de' => ['Bezahlt', 'In Bearbeitung', 'Versendet', 'Unterwegs', 'Zugestellt'],
        'fr' => ['Payée', 'En préparation', 'Expédiée', 'En transit', 'Livrée'],
        'es' => ['Pagado', 'En preparación', 'Enviado', 'En tránsito', 'Entregado'],
        'it' => ['Pagato', 'In lavorazione', 'Spedito', 'In transito', 'Consegnato'],
        'pl' => ['Opłacone', 'W realizacji', 'Wysłane', 'W drodze', 'Dostarczone'],
        'ar' => ['تم الدفع', 'قيد التجهيز', 'تم الشحن', 'في الطريق', 'تم التسليم'],
    ];
    private const TEXT = [
        'nl' => ['Update over uw bestelling {id}', 'Geachte klant,', 'De status van uw bestelling <b>{id}</b> is bijgewerkt naar: <b>{status}</b>.', 'Volg uw pakket', 'Met vriendelijke groet,'],
        'en' => ['Update on your order {id}', 'Dear customer,', 'The status of your order <b>{id}</b> has been updated to: <b>{status}</b>.', 'Track your parcel', 'Kind regards,'],
        'de' => ['Neuigkeiten zu Ihrer Bestellung {id}', 'Sehr geehrte Kundin, sehr geehrter Kunde,', 'Der Status Ihrer Bestellung <b>{id}</b> lautet jetzt: <b>{status}</b>.', 'Sendung verfolgen', 'Mit freundlichen Grüßen'],
        'fr' => ['Mise à jour de votre commande {id}', 'Chère cliente, cher client,', 'Le statut de votre commande <b>{id}</b> est désormais : <b>{status}</b>.', 'Suivre mon colis', 'Cordialement,'],
        'es' => ['Actualización de su pedido {id}', 'Estimado/a cliente:', 'El estado de su pedido <b>{id}</b> se ha actualizado a: <b>{status}</b>.', 'Seguir mi paquete', 'Atentamente,'],
        'it' => ['Aggiornamento sul tuo ordine {id}', 'Gentile cliente,', 'Lo stato del tuo ordine <b>{id}</b> è stato aggiornato a: <b>{status}</b>.', 'Traccia il pacco', 'Cordiali saluti,'],
        'pl' => ['Aktualizacja zamówienia {id}', 'Szanowni Państwo,', 'Status zamówienia <b>{id}</b> został zmieniony na: <b>{status}</b>.', 'Śledź przesyłkę', 'Z wyrazami szacunku,'],
        'ar' => ['تحديث بخصوص طلبك {id}', 'عزيزنا العميل،', 'تم تحديث حالة طلبك <b>{id}</b> إلى: <b>{status}</b>.', 'تتبّع شحنتك', 'مع أطيب التحيات،'],
    ];

    public function __construct(public Order $order) {}

    public static function enabled(): bool
    {
        return ! in_array(config('mail.default'), ['log', 'array', null, ''], true);
    }

    /** Verstuurt de mail; geeft true terug als dat lukte. */
    public static function sendFor(Order $order): bool
    {
        if (! $order->email || ! static::enabled()) {
            return false;
        }
        try {
            Mail::to($order->email)->send(new static($order));

            return true;
        } catch (Throwable $e) {
            Log::warning('Statusmail mislukt', ['order' => $order->order_no, 'error' => $e->getMessage()]);

            return false;
        }
    }

    private function lang(): string
    {
        return array_key_exists($this->order->lang, self::TEXT) ? $this->order->lang : 'en';
    }

    private function fill(string $s): string
    {
        $status = self::STATUS[$this->lang()][$this->order->step] ?? '';

        return str_replace(['{id}', '{status}'], [e($this->order->order_no), e($status)], $s);
    }

    public function envelope(): Envelope
    {
        $brand = Setting::brand();

        return new Envelope(subject: strip_tags($this->fill(self::TEXT[$this->lang()][0])), replyTo: [$brand['email']]);
    }

    public function content(): Content
    {
        [$_, $hello, $body, $track, $bye] = self::TEXT[$this->lang()];
        $brand = Setting::brand();
        $o = $this->order;
        $url = $o->tracking ? 'https://t.17track.net/'.($this->lang() === 'nl' ? 'nl' : 'en').'#nums='.rawurlencode(explode(',', $o->tracking)[0]) : '';
        $dir = $this->lang() === 'ar' ? 'rtl' : 'ltr';
        $html = '<div dir="'.$dir.'" style="font-family:Arial,sans-serif;font-size:15px;color:#14161b;max-width:560px">'
            .'<p>'.e($hello).'</p><p>'.$this->fill($body).'</p>'
            .($o->tracking ? '<p>'.e(($o->carrier ? $o->carrier.' · ' : '').$o->tracking).'</p><p><a href="'.e($url).'" style="display:inline-block;background:#14161b;color:#fff;padding:12px 18px;border-radius:8px;text-decoration:none">'.e($track).'</a></p>' : '')
            .'<p>'.e($bye).'<br>'.e($brand['name']).' · '.e($brand['email']).'</p></div>';

        return new Content(htmlString: $html);
    }
}
