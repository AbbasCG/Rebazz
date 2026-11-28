<?php

namespace App\Mail;

use App\Models\Setting;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Eenmalige inlogcode voor het klantaccount (10 minuten geldig). */
class LoginCodeMail extends Mailable
{
    use Queueable;

    private const T = [
        'nl' => ['Uw inlogcode', 'Uw code om in te loggen bij {shop}:', 'De code is 10 minuten geldig en werkt één keer. Heeft u dit niet aangevraagd? Dan kunt u deze e-mail negeren.'],
        'en' => ['Your login code', 'Your code to sign in to {shop}:', 'The code is valid for 10 minutes and works once. Did not request it? You can ignore this email.'],
        'de' => ['Ihr Anmeldecode', 'Ihr Code für die Anmeldung bei {shop}:', 'Der Code ist 10 Minuten gültig und nur einmal verwendbar. Nicht angefordert? Dann ignorieren Sie diese E-Mail.'],
        'fr' => ['Votre code de connexion', 'Votre code pour vous connecter à {shop} :', 'Ce code est valable 10 minutes et ne sert qu’une fois. Vous ne l’avez pas demandé ? Ignorez cet e-mail.'],
        'es' => ['Su código de acceso', 'Su código para iniciar sesión en {shop}:', 'El código es válido 10 minutos y sirve una sola vez. ¿No lo solicitó? Ignore este correo.'],
        'it' => ['Il tuo codice di accesso', 'Il tuo codice per accedere a {shop}:', 'Il codice vale 10 minuti e si usa una sola volta. Non l’hai richiesto? Ignora questa e-mail.'],
        'pl' => ['Twój kod logowania', 'Twój kod do logowania w {shop}:', 'Kod jest ważny 10 minut i działa jednorazowo. Nie prosiłeś o niego? Zignoruj tę wiadomość.'],
        'ar' => ['رمز الدخول الخاص بك', 'رمزك لتسجيل الدخول إلى {shop}:', 'الرمز صالح لمدة 10 دقائق ويُستخدم مرة واحدة. لم تطلبه؟ تجاهل هذه الرسالة.'],
    ];

    public function __construct(public string $code, public string $lang = 'nl') {}

    private function t(): array
    {
        return self::T[array_key_exists($this->lang, self::T) ? $this->lang : 'en'];
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->t()[0].': '.$this->code, replyTo: [Setting::brand()['email']]);
    }

    public function content(): Content
    {
        $brand = Setting::brand();
        $t = $this->t();
        $dir = $this->lang === 'ar' ? 'rtl' : 'ltr';
        $html = '<div dir="'.$dir.'" style="font-family:Arial,sans-serif;font-size:15px;color:#14161b;max-width:480px">'
            .'<p>'.e(str_replace('{shop}', $brand['name'], $t[1])).'</p>'
            .'<p style="font-size:32px;letter-spacing:8px;font-weight:700;margin:16px 0" dir="ltr">'.e($this->code).'</p>'
            .'<p style="font-size:13px;color:#666">'.e($t[2]).'</p></div>';

        return new Content(htmlString: $html);
    }
}
