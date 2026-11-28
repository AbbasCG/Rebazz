<?php

namespace App\Mail;

use App\Models\NewsletterCampaign;
use App\Models\Setting;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Support\Facades\URL;

/** Nieuwsbrief: tekst uit het beheer (alinea's gescheiden door een lege regel), optionele knop, afzender en een afmeldlink in elke mail. */
class NewsletterMail extends Mailable
{
    use Queueable;

    private const UNSUB = ['nl' => 'Afmelden voor deze nieuwsbrief', 'en' => 'Unsubscribe from this newsletter', 'de' => 'Newsletter abbestellen', 'fr' => 'Se désabonner de cette newsletter', 'es' => 'Darse de baja de este boletín', 'it' => 'Annulla l’iscrizione', 'pl' => 'Wypisz się z newslettera', 'ar' => 'إلغاء الاشتراك في النشرة'];
    private const WHY = ['nl' => 'U ontvangt deze e-mail omdat u zich heeft aangemeld voor de nieuwsbrief van', 'en' => 'You receive this email because you subscribed to the newsletter of', 'de' => 'Sie erhalten diese E-Mail, weil Sie den Newsletter von folgendem Shop abonniert haben:', 'fr' => 'Vous recevez cet e-mail car vous êtes inscrit(e) à la newsletter de', 'es' => 'Recibe este correo porque se suscribió al boletín de', 'it' => 'Ricevi questa e-mail perché ti sei iscritto alla newsletter di', 'pl' => 'Otrzymujesz tę wiadomość, ponieważ zapisałeś się do newslettera sklepu', 'ar' => 'تصلك هذه الرسالة لأنك اشتركت في نشرة'];

    public function __construct(public NewsletterCampaign $campaign, public string $email, public string $lang = 'nl') {}

    public function unsubscribeUrl(): string
    {
        return URL::signedRoute('newsletter.unsubscribe', ['email' => strtolower($this->email)]);
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->campaign->subject, replyTo: [Setting::brand()['email']]);
    }

    public function headers(): Headers
    {
        // Eén-klik afmelden in de mailclient (RFC 8058).
        return new Headers(text: ['List-Unsubscribe' => '<'.$this->unsubscribeUrl().'>', 'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click']);
    }

    /** Platte tekst naar veilige HTML: alinea's en regeleinden, geen eigen opmaak. */
    public static function paragraphs(string $text): string
    {
        $out = '';
        foreach (preg_split('/\R{2,}/', trim($text)) as $p) {
            $out .= '<p style="margin:0 0 14px;line-height:1.55">'.nl2br(e(trim($p))).'</p>';
        }

        return $out;
    }

    public function content(): Content
    {
        $l = array_key_exists($this->lang, self::UNSUB) ? $this->lang : 'en';
        $brand = Setting::brand();
        $dir = $l === 'ar' ? 'rtl' : 'ltr';
        $cta = ($this->campaign->cta_label && $this->campaign->cta_url && str_starts_with($this->campaign->cta_url, 'https://'))
            ? '<p style="margin:20px 0"><a href="'.e($this->campaign->cta_url).'" style="display:inline-block;background:#14161b;color:#fff;padding:12px 20px;border-radius:8px;text-decoration:none">'.e($this->campaign->cta_label).'</a></p>' : '';
        $html = '<div dir="'.$dir.'" style="font-family:Arial,sans-serif;font-size:15px;color:#14161b;max-width:560px">'
            .self::paragraphs($this->campaign->body).$cta
            .'<hr style="border:0;border-top:1px solid #ddd;margin:24px 0 12px">'
            .'<p style="font-size:12.5px;color:#666;line-height:1.5">'.e(self::WHY[$l]).' '.e($brand['name']).'.<br>'
            .e(trim(($brand['company'] ?? '').' · '.($brand['address'] ?? ''), ' ·')).'<br>'
            .'<a href="'.e($this->unsubscribeUrl()).'" style="color:#666">'.e(self::UNSUB[$l]).'</a></p></div>';

        return new Content(htmlString: $html);
    }
}
