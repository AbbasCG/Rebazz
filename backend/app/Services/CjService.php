<?php

namespace App\Services;

use App\Mail\OrderStatusMail;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Throwable;

/** Koppeling met CJdropshipping (API 2.0): automatisch bestellen en tracking ophalen. */
class CjService
{
    private const EU = ['AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR', 'DE', 'GR', 'HU', 'IE', 'IT', 'LV', 'LT', 'LU', 'MT', 'NL', 'PL', 'PT', 'RO', 'SK', 'SI', 'ES', 'SE'];

    public function enabled(): bool
    {
        return (bool) config('shop.cj.api_key');
    }

    private function call(string $method, string $path, ?array $body = null, bool $retried = false): mixed
    {
        $req = Http::acceptJson()->timeout(20);
        if (! str_starts_with($path, '/authentication')) {
            $req = $req->withHeaders(['CJ-Access-Token' => $this->token()]);
        }
        $url = config('shop.cj.base_url').$path;
        $res = $method === 'GET' ? $req->get($url) : $req->post($url, $body ?? []);
        $data = $res->json() ?? [];
        if (! $res->successful() || ($data['result'] ?? true) === false || (isset($data['code']) && (int) $data['code'] !== 200)) {
            // Verlopen of ingetrokken toegangstoken: token weggooien en één keer opnieuw proberen (anders blijft alles 10 dagen falen).
            if (! $retried && ! str_starts_with($path, '/authentication') && ($res->status() === 401 || preg_match('/token|unauthori[sz]ed/i', (string) ($data['message'] ?? '')))) {
                Cache::forget('cj.token');

                return $this->call($method, $path, $body, true);
            }
            throw new RuntimeException('CJ: '.($data['message'] ?? 'HTTP '.$res->status()).(isset($data['code']) ? ' ('.$data['code'].')' : ''));
        }

        return $data['data'] ?? null;
    }

    private function token(): string
    {
        return Cache::remember('cj.token', now()->addDays(10), function () {
            if (! $this->enabled()) {
                throw new RuntimeException('CJ_API_KEY ontbreekt');
            }
            $d = $this->call('POST', '/authentication/getAccessToken', ['apiKey' => config('shop.cj.api_key')]);
            $token = $d['accessToken'] ?? null;
            if (! $token) {
                throw new RuntimeException('CJ: geen toegangstoken ontvangen');
            }

            return $token;
        });
    }

    private function logistic(string $from, string $to, array $lines): string
    {
        foreach ($lines as $l) {
            if ($l['product']->cj_logistic) {
                return $l['product']->cj_logistic;
            }
        }
        $options = collect($this->call('POST', '/logistic/freightCalculate', [
            'startCountryCode' => $from, 'endCountryCode' => $to,
            'products' => array_map(fn ($l) => ['quantity' => $l['qty'], 'vid' => $l['vid']], $lines),
        ]) ?? [])->filter(fn ($o) => ! empty($o['logisticName']))->sortBy(fn ($o) => (float) ($o['logisticPrice'] ?? 0));
        if ($options->isEmpty()) {
            throw new RuntimeException("CJ: geen verzendmethode beschikbaar van $from naar $to");
        }

        return $options->first()['logisticName'];
    }

    /** Plaatst een betaalde bestelling bij CJ. Dubbel bestellen wordt voorkomen. */
    public function placeOrder(Order $order): Order
    {
        if ($order->cj_order_ids || $order->status !== 'paid') {
            return $order; // alleen betaalde (niet terugbetaalde) bestellingen worden besteld
        }
        // Atomische claim: slechts één proces (webhook, bedankpagina of beheerder) mag bij CJ bestellen.
        $claimed = Order::whereKey($order->getKey())
            ->where('status', 'paid')
            ->where('cj_status', '')
            ->where(fn ($q) => $q->whereNull('cj_order_ids')->orWhere('cj_order_ids', ''))
            ->update(['cj_status' => 'placing']);
        if ($claimed === 0) {
            return $order->fresh('items') ?? $order;
        }

        try {
            return $this->placeClaimed($order);
        } catch (Throwable $e) {
            // Wat er ook misgaat na de claim: nooit in 'placing' blijven hangen.
            Log::error('CJ bestellen onverwacht mislukt', ['order' => $order->order_no, 'error' => $e->getMessage()]);
            $order->update(['cj_status' => 'error', 'cj_error' => mb_substr('Onverwachte fout: '.$e->getMessage(), 0, 450)]);
            $this->alertOwner($order, 'Onverwachte fout: '.$e->getMessage());

            return $order->fresh('items');
        }
    }

    private function placeClaimed(Order $order): Order
    {
        $order->refresh();
        $order->loadMissing('items');
        // Per regel de juiste CJ-variant: die van de gekozen productvariant, anders die van het product.
        $lines = $order->items->map(function ($i) {
            $product = Product::find($i->product_id);
            $variant = $i->variant_id ? ProductVariant::find($i->variant_id) : null;

            return ['product' => $product, 'variant' => $variant, 'qty' => $i->qty, 'vid' => $variant?->cj_vid ?: $product?->cj_vid, 'label' => $i->variant_label];
        })->all();
        $missing = array_filter($lines, fn ($l) => ! $l['product'] || ! $l['vid']);
        if (! $this->enabled() || $missing) {
            $reason = ! $this->enabled() ? 'CJ-koppeling niet ingesteld' : 'Geen CJ-variant-ID bij: '.implode(', ', array_map(fn ($l) => ($l['product']?->name ?? '?').($l['label'] ? ' — '.$l['label'] : ''), $missing));
            $order->update(['cj_status' => 'manual', 'cj_error' => mb_substr($reason, 0, 450)]);
            $this->alertOwner($order, $reason);

            return $order;
        }

        $floor = round((float) $order->cost * (1 + max(0, (float) config('shop.min_margin_percent')) / 100), 2);
        // Vergelijk exclusief btw als Stripe Tax de werkelijke btw kent (btw is geen opbrengst).
        $received = (float) $order->total - (float) ($order->tax_amount ?? 0);
        if ((float) $order->cost > 0 && $received < $floor) {
            $reason = sprintf('Margebewaking: ontvangen €%.2f (excl. btw) is lager dan minimaal €%.2f (inkoop €%.2f, korting €%.2f). Controleer de kortingscode en bestel zelf indien gewenst.', $received, $floor, $order->cost, $order->discount);
            $order->update(['cj_status' => 'manual', 'cj_error' => mb_substr($reason, 0, 450)]);
            $this->alertOwner($order, $reason);

            return $order;
        }

        $addr = $order->address ?? [];
        $autoPay = (bool) config('shop.cj.auto_pay');
        try {
            $groups = [];
            foreach ($lines as $l) {
                $groups[strtoupper($l['product']->cj_from ?: 'CN')][] = $l;
            }
            $ids = [];
            $n = 0;
            foreach ($groups as $from => $g) {
                $n++;
                $body = [
                    'orderNumber' => $order->order_no.(count($groups) > 1 ? '-'.$n : ''),
                    'shippingCountryCode' => $addr['country'] ?? $order->country,
                    'shippingCountry' => \Locale::getDisplayRegion('-'.($addr['country'] ?? $order->country), 'en') ?: $order->country,
                    'shippingProvince' => ($addr['state'] ?? '') ?: (($addr['city'] ?? '') ?: '-'),
                    'shippingCity' => ($addr['city'] ?? '') ?: '-',
                    'shippingZip' => $addr['postal_code'] ?? '',
                    'shippingAddress' => $addr['line1'] ?? '',
                    'shippingAddress2' => $addr['line2'] ?? '',
                    'shippingCustomerName' => $order->customer_name,
                    'shippingPhone' => $order->phone,
                    'email' => $order->email,
                    'remark' => 'Rebazz '.$order->order_no,
                    'logisticName' => $this->logistic($from, $order->country, $g),
                    'fromCountryCode' => $from,
                    'payType' => $autoPay ? 2 : 3,
                    'products' => array_map(fn ($l) => ['vid' => $l['vid'], 'quantity' => $l['qty']], $g),
                ];
                if (in_array($order->country, self::EU, true) && ! in_array($from, self::EU, true)) {
                    $body['iossType'] = 3; // CJ IOSS: btw vooraf afgedragen
                }
                $res = $this->call('POST', '/shopping/order/createOrderV2', $body);
                $newId = $res['orderId'] ?? $res['shipmentOrderId'] ?? null;
                if (! $newId) {
                    throw new RuntimeException('CJ gaf geen order-ID terug; controleer het CJ-dashboard voordat u opnieuw bestelt.');
                }
                $ids[] = $newId;
                // Direct vastleggen: mislukt een latere groep, dan is deze al bij CJ aangemaakt en mag niet dubbel besteld worden.
                $order->update(['cj_order_ids' => implode(',', array_filter($ids))]);
            }
            $order->update([
                'cj_order_ids' => implode(',', array_filter($ids)), 'cj_status' => $autoPay ? 'paid' : 'created', 'cj_error' => '',
                'cj_wait' => true, 'step' => max($order->step, 1),
            ]);
        } catch (Throwable $e) {
            Log::warning('CJ bestellen mislukt', ['order' => $order->order_no, 'error' => $e->getMessage()]);
            $partial = $order->fresh()->cj_order_ids ? ' [DEELS BESTELD bij CJ: '.$order->fresh()->cj_order_ids.' — niet opnieuw bestellen, handmatig afronden]' : '';
            // Deels bestelde bestelling: wel tracking blijven ophalen voor de groepen die al bij CJ staan.
            $order->update(['cj_status' => 'error', 'cj_error' => mb_substr($e->getMessage().$partial, 0, 450)] + ($partial ? ['cj_wait' => true] : []));
            $this->alertOwner($order, $e->getMessage().$partial);
        }

        return $order->fresh('items');
    }

    /** Meld de eigenaar dat een betaalde bestelling niet automatisch bij CJ is geplaatst. */
    public function alertOwner(Order $order, string $reason): void
    {
        Log::error('CJ-bestelling vereist aandacht', ['order' => $order->order_no, 'reason' => $reason]);
        $to = config('shop.owner_alert_email') ?: (\App\Models\Setting::brand()['email'] ?? null);
        if (! $to) {
            return;
        }
        try {
            Mail::raw("Bestelling {$order->order_no} is betaald maar niet automatisch bij CJ geplaatst.\n\nReden: {$reason}\n\nOpen het beheer om het opnieuw te proberen of handmatig te bestellen.", function ($m) use ($to, $order) {
                $m->to($to)->subject('Actie nodig: CJ-bestelling '.$order->order_no);
            });
        } catch (Throwable $e) {
            Log::warning('Alert-mail mislukt', ['error' => $e->getMessage()]);
        }
    }

    /** Haalt status en trackingcode op bij CJ. Geeft true terug als er iets veranderde. */
    public function sync(Order $order): bool
    {
        $ids = array_filter(explode(',', $order->cj_order_ids));
        if (! $ids) {
            return false;
        }
        $details = array_filter(array_map(fn ($id) => $this->call('GET', '/shopping/order/getOrderDetail?orderId='.urlencode($id)), $ids));
        $tracks = array_values(array_filter(array_map(fn ($d) => $d['trackNumber'] ?? $d['trackingNumber'] ?? null, $details)));
        $carriers = array_values(array_filter(array_map(fn ($d) => $d['logisticName'] ?? $d['trackingProvider'] ?? null, $details)));
        $statuses = array_map(fn ($d) => strtoupper((string) ($d['orderStatus'] ?? '')), $details);
        $before = $order->step;
        $patch = [];
        if ($tracks && implode(',', $tracks) !== $order->tracking) {
            $patch['tracking'] = mb_substr(implode(',', $tracks), 0, 450);
            $patch['carrier'] = mb_substr(implode(', ', array_unique($carriers)), 0, 60);
            if ($order->step < 2) {
                $patch['step'] = 2;
            }
        }
        if ($statuses && count(array_filter($statuses, fn ($s) => $s === 'DELIVERED')) === count($statuses) && $order->step < 4) {
            $patch += ['step' => 4, 'cj_wait' => false, 'delayed' => false];
        }
        if (in_array('CANCELLED', $statuses, true)) {
            $patch += ['cj_status' => 'error', 'cj_error' => 'Bestelling geannuleerd door CJ', 'cj_wait' => false];
        }
        if (! isset($patch['cj_wait']) && $order->paid_at && $order->paid_at->lt(now()->subDays(60))) {
            $patch['cj_wait'] = false;
        }
        if (! $patch) {
            return false;
        }
        $order->update($patch);
        if (($patch['step'] ?? $before) > $before) {
            OrderStatusMail::sendFor($order->fresh('items'));
        }

        return true;
    }

    /** @return array{checked: int, changed: int} */
    public function syncAll(): array
    {
        $checked = 0;
        $changed = 0;
        Order::where('cj_wait', true)->where('status', 'paid')->orderBy('id')->chunkById(50, function ($orders) use (&$checked, &$changed) {
            foreach ($orders as $o) {
                $checked++;
                try {
                    if ($this->sync($o)) {
                        $changed++;
                    }
                } catch (Throwable $e) {
                    Log::warning('CJ sync mislukt', ['order' => $o->order_no, 'error' => $e->getMessage()]);
                }
            }
        });

        return ['checked' => $checked, 'changed' => $changed];
    }
}
