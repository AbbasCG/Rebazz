<?php

use App\Models\Order;
use App\Services\CjService;
use App\Services\OrderRecorder;
use App\Services\StripeGateway;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

/* Trackingcodes ophalen bij CJdropshipping (elke 2 uur, via de Laravel-scheduler). */
Artisan::command('shop:cj-sync', function (CjService $cj) {
    if (! $cj->enabled()) {
        $this->warn('CJ_API_KEY ontbreekt; niets te doen.');

        return;
    }
    $r = $cj->syncAll();
    $this->info("Gecontroleerd: {$r['checked']}, bijgewerkt: {$r['changed']}");
})->purpose('Haal status en tracking op bij CJdropshipping');

/* Bestaande betaalde bestellingen uit Stripe overnemen (bijv. uit de vorige versie van de site). */
Artisan::command('shop:import-stripe', function (StripeGateway $stripe, OrderRecorder $recorder) {
    $before = Order::count();
    foreach ($stripe->listPaidSessions() as $s) {
        $recorder->record($s);
    }
    $this->info('Bestellingen geïmporteerd: '.(Order::count() - $before));
})->purpose('Importeer betaalde Stripe-bestellingen in de database');

Schedule::command('shop:cj-sync')->everyTwoHours()->withoutOverlapping();
Schedule::command('sanctum:prune-expired --hours=24')->daily();

/* Betaalde bestellingen die blijven hangen (proces gestopt midden in het bestellen, of nooit gestart): eigenaar waarschuwen. */
Artisan::command('shop:check-stuck', function (CjService $cj) {
    if (! $cj->enabled()) {
        return;
    }
    $cutoff = now()->subMinutes(15);
    $stuck = Order::where('status', 'paid')
        ->where(fn ($q) => $q->whereNull('cj_order_ids')->orWhere('cj_order_ids', ''))
        ->where(fn ($q) => $q->where(fn ($p) => $p->where('cj_status', 'placing')->where('updated_at', '<', $cutoff))
            ->orWhere(fn ($p) => $p->where('cj_status', '')->where('paid_at', '<', $cutoff)))
        ->get();
    foreach ($stuck as $o) {
        if (Illuminate\Support\Facades\Cache::add('stuck-alert.'.$o->id, 1, now()->addDay())) { // één melding per dag per bestelling
            $cj->alertOwner($o, 'Bestelling hangt vast (status "'.($o->cj_status ?: 'niet gestart').'"). Controleer het CJ-dashboard voordat u opnieuw bestelt.');
        }
    }
    $this->info('Vastgelopen bestellingen: '.$stuck->count());
})->purpose('Waarschuw bij betaalde bestellingen die niet bij CJ zijn geplaatst');

Schedule::command('shop:check-stuck')->everyTenMinutes()->withoutOverlapping();

/* Orderbevestigingen die niet verstuurd zijn (bijv. mailserver even uit): opnieuw proberen voor betalingen van de laatste 48 uur. */
Artisan::command('shop:send-confirmations', function () {
    $sent = 0;
    Order::where('status', 'paid')->whereNull('confirmation_sent_at')
        ->where('paid_at', '>', now()->subHours(48))->where('paid_at', '<', now()->subMinutes(2))
        ->each(function ($o) use (&$sent) {
            if (\App\Mail\OrderConfirmationMail::sendFor($o)) {
                $sent++;
            }
        });
    $this->info('Bevestigingen verstuurd: '.$sent);
})->purpose('Verstuur ontbrekende orderbevestigingen (laatste 48 uur)');

Schedule::command('shop:send-confirmations')->everyTenMinutes()->withoutOverlapping();

/* AVG: bewaartermijnen afdwingen (bestellingen na 7 jaar anonimiseren, niet-bevestigde nieuwsbriefaanmeldingen na 30 dagen wissen). */
Artisan::command('shop:gdpr-retention', function (\App\Services\PrivacyService $privacy) {
    $r = $privacy->runRetention();
    $this->info("Geanonimiseerde bestellingen: {$r['anonymized']}, niet-bevestigde aanmeldingen gewist: {$r['newsletter_pending_deleted']}, oude verzoeken opgeruimd: {$r['requests_deleted']}, ongebruikte accounts verwijderd: {$r['accounts_deleted']}");
})->purpose('Pas de AVG-bewaartermijnen toe');

Schedule::command('shop:gdpr-retention')->dailyAt('03:30')->withoutOverlapping();
