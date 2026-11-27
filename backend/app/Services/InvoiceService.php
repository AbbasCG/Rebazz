<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderReturn;
use App\Models\Setting;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use RuntimeException;

/**
 * Facturen en creditnota's: doorlopende, unieke nummers (per jaar), PDF-weergave en ondertekende downloadlinks.
 * Een factuurnummer wordt toegekend bij het vastleggen van de betaling, zodat er geen nummers ontbreken.
 */
class InvoiceService
{
    public function __construct(private TaxService $tax) {}

    /** Volgend nummer, bijv. RBF-2026-00001. Vergrendelt de teller zodat gelijktijdige betalingen nooit hetzelfde nummer krijgen. */
    public function nextNumber(string $prefix, int $year): string
    {
        $key = strtolower($prefix).'-'.$year;

        return DB::transaction(function () use ($prefix, $year, $key) {
            DB::table('document_counters')->insertOrIgnore(['key' => $key, 'last' => 0]);
            $row = DB::table('document_counters')->where('key', $key)->lockForUpdate()->first();
            $next = (int) $row->last + 1;
            DB::table('document_counters')->where('key', $key)->update(['last' => $next]);

            return sprintf('%s-%d-%05d', $prefix, $year, $next);
        });
    }

    /** Geeft de bestelling een factuurnummer als die er nog geen heeft (ook oude bestellingen). */
    public function ensureInvoice(Order $order): Order
    {
        if ($order->invoice_number) {
            return $order;
        }

        return DB::transaction(function () use ($order) {
            $fresh = Order::whereKey($order->getKey())->lockForUpdate()->first();
            if (! $fresh->invoice_number) {
                $date = $fresh->paid_at ?? now();
                $fresh->update(['invoice_number' => $this->nextNumber('RBF', (int) $date->format('Y')), 'invoice_issued_at' => $date]);
            }
            $order->setRawAttributes($fresh->getAttributes(), true);

            return $order;
        });
    }

    /** Creditnota bij een volledige terugbetaling. Alleen als de bestelling echt is terugbetaald. */
    public function ensureCreditNote(Order $order): Order
    {
        if ($order->status !== 'refunded') {
            throw new RuntimeException('Alleen terugbetaalde bestellingen hebben een creditnota.');
        }
        $this->ensureInvoice($order);
        if ($order->credit_note_number) {
            return $order;
        }
        if ($order->total - $this->returnRefunds($order) <= 0.009) {
            return $order; // alles is al via retouren gecrediteerd: geen tweede creditnota (en geen gat in de nummering)
        }

        return DB::transaction(function () use ($order) {
            $fresh = Order::whereKey($order->getKey())->lockForUpdate()->first();
            if (! $fresh->credit_note_number) {
                $fresh->update(['credit_note_number' => $this->nextNumber('RBC', (int) now()->format('Y')), 'credit_note_issued_at' => now()]);
            }
            $order->setRawAttributes($fresh->getAttributes(), true);

            return $order;
        });
    }

    /** Ondertekende link (zonder login) voor de klant; de handtekening hangt van APP_KEY af en kan niet geraden worden. */
    public function signedUrl(Order $order, string $type = 'invoice'): string
    {
        return URL::signedRoute($type === 'credit' ? 'orders.credit-note' : 'orders.invoice', ['order' => $order->order_no]);
    }

    /** PDF als binaire string. $type: 'invoice' of 'credit'. */
    /** Wat van de bestelling al via retouren is terugbetaald (voor de creditnota van het restant). */
    public function returnRefunds(Order $order): float
    {
        return round((float) $order->returns()->whereNotNull('refunded_at')->sum('refund_amount'), 2);
    }

    /** Btw over een (deel)bedrag met hetzelfde tarief/regime als de bestelling. */
    private function vatForAmount(Order $order, float $amount): array
    {
        $base = $this->tax->vatFor($order, Setting::get('tax.oss', true) !== false);
        if (abs($amount - (float) $order->total) < 0.005) {
            return $base;
        }
        $excl = $amount / (1 + $base['rate'] / 100);

        return ['rate' => $base['rate'], 'regime' => $base['regime'], 'incl' => $amount, 'excl' => $excl, 'vat' => $amount - $excl, 'o' => $order, 'country' => $order->country];
    }

    public function pdf(Order $order, string $type = 'invoice'): string
    {
        $type === 'credit' ? $this->ensureCreditNote($order) : $this->ensureInvoice($order);
        $order->loadMissing('items');
        $total = (float) $order->total;
        $lines = null;
        if ($type === 'credit' && ($prior = $this->returnRefunds($order)) > 0) {
            // Eerdere retouren hebben hun eigen creditnota: deze nota crediteert alleen wat er nog van de bestelling over is.
            $total = round($order->total - $prior, 2);
            $lines = [['name' => 'Terugbetaling restant van bestelling '.$order->order_no, 'qty' => 1, 'price' => $total, 'total' => $total]];
        }

        return $this->render(view('documents.invoice', [
            'o' => $order, 'brand' => Setting::brand(), 'vat' => $this->vatForAmount($order, $total), 'credit' => $type === 'credit',
            'number' => $type === 'credit' ? $order->credit_note_number : $order->invoice_number,
            'date' => ($type === 'credit' ? $order->credit_note_issued_at : $order->invoice_issued_at) ?? now(),
            'lines' => $lines, 'total' => $total,
        ])->render());
    }

    /** Creditnota voor een geretourneerde bestelling (alleen na terugbetaling): met de geretourneerde artikelen en het terugbetaalde bedrag. */
    public function ensureReturnCreditNote(OrderReturn $return): OrderReturn
    {
        if ($return->refund_amount === null || ! $return->refunded_at) {
            throw new RuntimeException('Een creditnota voor een retour kan pas na de terugbetaling.');
        }
        $this->ensureInvoice($return->order);
        if ($return->credit_note_number) {
            return $return;
        }

        return DB::transaction(function () use ($return) {
            $fresh = OrderReturn::whereKey($return->getKey())->lockForUpdate()->first();
            if (! $fresh->credit_note_number) {
                $fresh->update(['credit_note_number' => $this->nextNumber('RBC', (int) now()->format('Y')), 'credit_note_issued_at' => now()]);
            }
            $return->setRawAttributes($fresh->getAttributes(), true);

            return $return;
        });
    }

    public function returnPdf(OrderReturn $return): string
    {
        $this->ensureReturnCreditNote($return);
        $order = $return->order;
        $return->loadMissing('items.orderItem');
        $amount = (float) $return->refund_amount;
        $lines = $return->items->map(fn ($ri) => [
            'name' => $ri->orderItem->name.($ri->orderItem->variant_label ? ' — '.$ri->orderItem->variant_label : ''), 'sku' => $ri->orderItem->sku,
            'qty' => $ri->qty, 'price' => $ri->orderItem->price, 'total' => $ri->orderItem->price * $ri->qty,
        ])->all();
        $itemsValue = $return->itemsValue();
        if (abs($amount - $itemsValue) > 0.005) { // bijv. inclusief verzendkosten of een afwijkend bedrag
            $lines[] = ['name' => $amount > $itemsValue ? 'Verzendkosten / aanvullende vergoeding' : 'Aftrek (bijv. waardevermindering)', 'qty' => 1, 'price' => round($amount - $itemsValue, 2), 'total' => round($amount - $itemsValue, 2)];
        }

        return $this->render(view('documents.invoice', [
            'o' => $order, 'brand' => Setting::brand(), 'vat' => $this->vatForAmount($order, $amount), 'credit' => true,
            'number' => $return->credit_note_number, 'date' => $return->credit_note_issued_at ?? now(),
            'lines' => $lines, 'total' => $amount, 'rma' => $return->rma_number,
        ])->render());
    }

    public function returnSignedUrl(OrderReturn $return): string
    {
        return URL::signedRoute('returns.credit-note', ['return' => $return->rma_number]);
    }

    private function render(string $html): string
    {
        $tmp = storage_path('app/dompdf');
        if (! is_dir($tmp)) {
            @mkdir($tmp, 0775, true);
        }
        $options = new Options;
        $options->set('defaultFont', 'DejaVu Sans'); // bevat het €-teken en Europese letters
        $options->set('isRemoteEnabled', false);     // geen externe bestanden laden
        $options->set('isPhpEnabled', false);
        $options->set('tempDir', $tmp);
        $options->set('fontDir', $tmp);
        $options->set('fontCache', $tmp);
        $pdf = new Dompdf($options);
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->setPaper('A4');
        $pdf->render();

        return $pdf->output();
    }

    public function filename(Order $order, string $type = 'invoice'): string
    {
        return ($type === 'credit' ? 'creditnota-' : 'factuur-').strtolower($type === 'credit' ? $order->credit_note_number : $order->invoice_number).'.pdf';
    }
}
