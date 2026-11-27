<?php

namespace App\Services;

use App\Models\Order;
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
    private function nextNumber(string $prefix, int $year): string
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
    public function pdf(Order $order, string $type = 'invoice'): string
    {
        $type === 'credit' ? $this->ensureCreditNote($order) : $this->ensureInvoice($order);
        $order->loadMissing('items');
        $vat = $this->tax->vatFor($order, Setting::get('tax.oss', true) !== false);
        $html = view('documents.invoice', [
            'o' => $order, 'brand' => Setting::brand(), 'vat' => $vat, 'credit' => $type === 'credit',
            'number' => $type === 'credit' ? $order->credit_note_number : $order->invoice_number,
            'date' => ($type === 'credit' ? $order->credit_note_issued_at : $order->invoice_issued_at) ?? now(),
        ])->render();

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
