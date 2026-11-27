<?php

namespace Database\Seeders;

use App\Models\NewsletterSubscriber;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Database\Seeder;

/**
 * Testgegevens om de winkel en het beheer lokaal uit te proberen.
 *
 *   php artisan db:seed --class=DemoSeeder
 *
 * Alles is verzonnen (e-mailadressen @example.com, Stripe-ID's "demo", CJ-ID's "DEMO-…").
 * Draait NIET in productie en kan veilig opnieuw worden uitgevoerd (bestaande demo-bestellingen worden bijgewerkt).
 */
class DemoSeeder extends Seeder
{
    /** Effen kleurvlak als PNG (zonder GD): eenvoudige demo-foto's die via /media/products/… worden getoond. */
    private function solidPng(int $r, int $g, int $b, int $size = 480): string
    {
        $row = "\x00".str_repeat(chr($r).chr($g).chr($b), $size);
        $chunk = fn (string $type, string $data) => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));

        return "\x89PNG\r\n\x1a\n"
            .$chunk('IHDR', pack('NNCCCCC', $size, $size, 8, 2, 0, 0, 0))
            .$chunk('IDAT', gzcompress(str_repeat($row, $size), 9))
            .$chunk('IEND', '');
    }

    private function demoImage(int $r, int $g, int $b): string
    {
        $name = md5("demo-$r-$g-$b").'.png'; // 32 hex tekens + .png: voldoet aan de naamregels van /media/products
        \Illuminate\Support\Facades\Storage::disk('public')->put('products/'.$name, $this->solidPng($r, $g, $b));

        return '/media/products/'.$name;
    }

    /** Aura krijgt kleurvarianten (eigen prijs, voorraad en foto per kleur), de tafellamp een fotogalerij. */
    private function demoVariantsAndGallery(): void
    {
        if ($p1 = Product::find('p1')) {
            $p1->update(['options' => [['name' => 'Kleur', 'values' => ['Zwart', 'Wit', 'Blauw']]], 'track_stock' => true]);
            $rows = [
                ['Zwart', null, 10, $this->demoImage(40, 44, 52)],
                ['Wit', 89.95, 3, $this->demoImage(225, 228, 232)],      // duurder en bijna op
                ['Blauw', null, 0, $this->demoImage(70, 100, 200)],       // uitverkocht
            ];
            $p1->syncVariants(collect($rows)->map(fn ($r, $i) => [
                'label' => $r[0], 'values' => ['Kleur' => $r[0]], 'sku' => 'RB-AUR-'.strtoupper(substr($r[0], 0, 3)), 'ownPrice' => $r[1],
                'cjVid' => 'DEMO-VID-P1-'.strtoupper(substr($r[0], 0, 3)), 'stock' => $r[2], 'image' => $r[3],
            ])->all());
        }
        if ($p2 = Product::find('p2')) {
            $p2->update(['images' => [$this->demoImage(214, 160, 70), $this->demoImage(120, 90, 50), $this->demoImage(60, 50, 40)]]);
        }
    }

    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->error('DemoSeeder draait niet in productie.');

            return;
        }

        $this->call(DatabaseSeeder::class);

        // Productveiligheid (GPSR) en fictieve CJ-gegevens, zodat producten actief kunnen zijn en bestellen getest kan worden.
        $gpsr = [
            'manufacturer' => 'Demo Manufacturing Co., Ltd., 88 Test Road, Shenzhen, CN',
            'eu_responsible' => 'Demo EU Compliance B.V., Teststraat 1, 4811 AA Breda, NL, compliance@example.com',
            'safety_info' => 'Voorbeeldtekst: niet geschikt voor kinderen onder 3 jaar. Niet in water onderdompelen.',
        ];
        foreach (Product::orderBy('created_at')->get() as $i => $p) {
            $p->fill($gpsr + ['stock' => 50]);
            if ($i < 6) { // de eerste zes hebben een (fictieve) CJ-variant; de rest test het pad "niet automatisch besteld"
                $p->fill(['cj_vid' => 'DEMO-VID-'.strtoupper($p->id), 'cj_from' => $i % 2 ? 'CN' : 'PL']);
            }
            $p->save();
        }
        // Voorraadbeheer uitproberen: één product ruim op voorraad, één bijna op, één uitverkocht (de rest houdt geen voorraad bij).
        foreach (['p1' => 50, 'p2' => 3, 'p3' => 0] as $id => $stock) {
            Product::find($id)?->update(['track_stock' => true, 'stock' => $stock]);
        }
        $this->demoVariantsAndGallery();
        $products = Product::active()->orderBy('created_at')->get()->values();
        if ($products->count() < 3) {
            $this->command?->warn('Te weinig actieve producten voor demo-bestellingen.');

            return;
        }

        // [nr, land, stad, lang, status, step, cj_status, tax, dagen geleden, extra]
        $scenarios = [
            ['RB-DEMO01', 'NL', 'Amsterdam', 'nl', 'paid', 4, 'paid', 21.0, 20, ['tracking' => 'DEMO-NL-0001', 'carrier' => 'PostNL', 'cj_ids' => 'DEMO-CJ-1', 'cj_wait' => false]],
            ['RB-DEMO02', 'DE', 'Berlin', 'de', 'paid', 2, 'paid', 19.0, 6, ['tracking' => 'DEMO-DE-0002', 'carrier' => 'DHL', 'cj_ids' => 'DEMO-CJ-2', 'cj_wait' => true]],
            ['RB-DEMO03', 'FR', 'Paris', 'fr', 'paid', 1, 'created', 20.0, 1, ['cj_ids' => 'DEMO-CJ-3', 'cj_wait' => true]],
            ['RB-DEMO04', 'BE', 'Gent', 'nl', 'paid', 0, 'manual', 21.0, 1, ['cj_error' => 'Geen CJ-variant-ID bij: Demo-product (handmatig bestellen)']],
            ['RB-DEMO05', 'ES', 'Madrid', 'es', 'paid', 0, 'error', 21.0, 2, ['cj_error' => 'CJ: Insufficient balance (1600200)']],
            ['RB-DEMO06', 'IT', 'Milano', 'it', 'paid', 0, 'placing', 22.0, 0, ['stuck' => true]],
            ['RB-DEMO07', 'PL', 'Warszawa', 'pl', 'refunded', 0, '', 23.0, 9, []],
            ['RB-DEMO08', 'AT', 'Wien', 'de', 'paid', 2, 'paid', 20.0, 15, ['tracking' => 'DEMO-AT-0008', 'carrier' => 'DPD', 'cj_ids' => 'DEMO-CJ-8', 'cj_wait' => true, 'delayed' => true]],
            ['RB-DEMO09', 'US', 'Austin', 'en', 'paid', 1, 'created', null, 3, ['cj_ids' => 'DEMO-CJ-9', 'cj_wait' => true]], // export: geen btw
        ];

        foreach ($scenarios as $n => [$no, $country, $city, $lang, $status, $step, $cj, $vat, $daysAgo, $x]) {
            $lines = [[$products[$n % $products->count()], 1 + ($n % 2)], [$products[($n + 2) % $products->count()], 1]];
            $subtotal = collect($lines)->sum(fn ($l) => $l[0]->price * $l[1]);
            $shipping = $subtotal >= 49 ? 0 : 4.95;
            $total = round($subtotal + $shipping, 2);
            $cost = collect($lines)->sum(fn ($l) => $l[0]->cost * $l[1]);
            $paidAt = now()->subDays($daysAgo)->subHours($n);

            $order = Order::updateOrCreate(['order_no' => $no], [
                'stripe_session_id' => 'cs_test_demo'.strtolower(substr($no, -2)).'aaaaaaaa',
                'payment_intent_id' => 'pi_demo'.strtolower(substr($no, -2)),
                'livemode' => false,
                'status' => $status,
                'customer_name' => 'Demo Klant '.($n + 1),
                'email' => 'demo'.($n + 1).'@example.com',
                'phone' => '+3160000000'.$n,
                'country' => $country,
                'address' => ['line1' => 'Teststraat '.($n + 1), 'postal_code' => '1000 AA', 'city' => $city, 'country' => $country],
                'lang' => $lang,
                'subtotal' => $subtotal,
                'discount' => 0,
                'shipping' => $shipping,
                'total' => $total,
                'tax_amount' => $vat === null ? null : round($total - $total / (1 + $vat / 100), 2),
                'cost' => $cost,
                'payment_method' => ['ideal', 'card', 'bancontact', 'klarna'][$n % 4],
                'ship_method' => $n % 5 === 0 ? 'express' : 'standard',
                'step' => $step,
                'carrier' => $x['carrier'] ?? '',
                'tracking' => $x['tracking'] ?? '',
                'delayed' => $x['delayed'] ?? false,
                'cj_status' => $cj,
                'cj_order_ids' => $x['cj_ids'] ?? '',
                'cj_error' => $x['cj_error'] ?? '',
                'cj_wait' => $x['cj_wait'] ?? false,
                'paid_at' => $paidAt,
                'confirmation_sent_at' => $paidAt, // demo-bestellingen krijgen nooit een echte orderbevestiging
            ]);
            $order->items()->delete();
            foreach ($lines as [$p, $qty]) {
                $order->items()->create([
                    'product_id' => $p->id, 'sku' => $p->sku, 'name' => $p->name, 'price' => $p->price, 'cost' => $p->cost,
                    'qty' => $qty, 'supplier' => $p->supplier, 'supplier_url' => $p->supplier_url,
                ]);
            }
            if (! empty($x['stuck'])) { // simuleert een vastgelopen bestelling (proces gestopt na de claim)
                $order->forceFill(['updated_at' => now()->subMinutes(45), 'paid_at' => now()->subMinutes(50)])->saveQuietly();
            }
        }

        foreach (['anna@example.com' => 'nl', 'max@example.com' => 'de', 'lea@example.com' => 'fr'] as $email => $lang) {
            NewsletterSubscriber::updateOrCreate(['email' => $email], ['lang' => $lang, 'confirmed_at' => now()]);
        }

        $this->command?->info('Demo klaar: '.$products->count().' actieve producten, '.count($scenarios).' bestellingen, 3 nieuwsbriefabonnees.');
    }
}
