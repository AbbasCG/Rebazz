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
