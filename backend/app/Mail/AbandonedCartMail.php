<?php

namespace App\Mail;

use App\Models\Setting;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Eénmalige herinnering aan een niet afgeronde bestelling; alleen verstuurd na toestemming in de Stripe-kassa. De link herstelt de kassasessie. */
class AbandonedCartMail extends Mailable
{
    use Queueable;

    /** [onderwerp, tekst, knop, afsluiter] */
    private const T = [
        'nl' => ['U heeft nog iets in uw winkelwagen', 'U was bezig met een bestelling bij {shop}, maar die is nog niet afgerond. Uw winkelwagen staat nog voor u klaar.', 'Bestelling afronden', 'U ontvangt hierover één e-mail, omdat u daar in de kassa toestemming voor gaf. Wilt u dit niet, negeer dan dit bericht.'],
        'en' => ['You left something in your cart', 'You were placing an order at {shop} but did not finish. Your cart is still waiting for you.', 'Complete your order', 'You get one email about this because you agreed to it at checkout. If you do not want it, just ignore this message.'],
        'de' => ['Sie haben noch etwas im Warenkorb', 'Sie waren dabei, bei {shop} zu bestellen, haben aber nicht abgeschlossen. Ihr Warenkorb wartet auf Sie.', 'Bestellung abschließen', 'Sie erhalten dazu eine einzige E-Mail, weil Sie an der Kasse zugestimmt haben. Wenn Sie das nicht möchten, ignorieren Sie diese Nachricht.'],
        'fr' => ['Il reste des articles dans votre panier', 'Vous étiez en train de commander chez {shop} sans avoir terminé. Votre panier vous attend.', 'Finaliser la commande', 'Vous recevez un seul e-mail à ce sujet, car vous l’avez accepté lors du paiement. Sinon, ignorez simplement ce message.'],
        'es' => ['Aún tiene artículos en su carrito', 'Estaba haciendo un pedido en {shop} pero no lo terminó. Su carrito le espera.', 'Completar el pedido', 'Recibe un solo correo sobre esto porque lo aceptó al pagar. Si no lo desea, ignore este mensaje.'],
        'it' => ['Hai ancora articoli nel carrello', 'Stavi ordinando su {shop} ma non hai finito. Il tuo carrello ti aspetta.', 'Completa l’ordine', 'Ricevi una sola e-mail perché hai dato il consenso alla cassa. Se non vuoi riceverla, ignora questo messaggio.'],
        'pl' => ['W koszyku czeka na Ciebie zamówienie', 'Składałeś zamówienie w {shop}, ale go nie dokończyłeś. Twój koszyk czeka.', 'Dokończ zamówienie', 'Dostajesz jedną wiadomość w tej sprawie, bo zgodziłeś się na to przy kasie. Jeśli nie chcesz, zignoruj tę wiadomość.'],
        'ar' => ['لا يزال لديك شيء في سلتك', 'كنت تُجري طلبًا في {shop} ولم تُكمله. سلتك بانتظارك.', 'إكمال الطلب', 'تصلك رسالة واحدة فقط لأنك وافقت على ذلك عند الدفع. إن لم ترغب بها فتجاهلها.'],
    ];

    public function __construct(public string $url, public string $lang = 'nl') {}

    private function t(): array
    {
        return self::T[array_key_exists($this->lang, self::T) ? $this->lang : 'en'];
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->t()[0], replyTo: [Setting::brand()['email']]);
    }

    public function content(): Content
    {
        $t = $this->t();
        $brand = Setting::brand();
        $dir = $this->lang === 'ar' ? 'rtl' : 'ltr';
        $html = '<div dir="'.$dir.'" style="font-family:Arial,sans-serif;font-size:15px;color:#14161b;max-width:560px">'
            .'<p>'.e(str_replace('{shop}', $brand['name'], $t[1])).'</p>'
            .'<p><a href="'.e($this->url).'" style="display:inline-block;background:#14161b;color:#fff;padding:12px 18px;border-radius:8px;text-decoration:none">'.e($t[2]).'</a></p>'
            .'<p style="font-size:13px;color:#666">'.e($t[3]).'</p></div>';

        return new Content(htmlString: $html);
    }
}
