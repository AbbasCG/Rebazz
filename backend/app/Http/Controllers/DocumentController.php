<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\OrderReturn;
use App\Services\InvoiceService;
use Illuminate\Http\Response;

/** Factuur en creditnota als PDF: voor de klant via ondertekende link, voor de beheerder via het beheer. */
class DocumentController extends Controller
{
    public function invoice(Order $order, InvoiceService $svc): Response
    {
        abort_unless(in_array($order->status, ['paid', 'refunded'], true), 404);

        return $this->respond($order, 'invoice', $svc);
    }

    public function creditNote(Order $order, InvoiceService $svc): Response
    {
        abort_unless($order->status === 'refunded', 404);
        // Is alles al via retouren (met eigen creditnota's) terugbetaald, dan is er geen restant meer om te crediteren.
        abort_unless($order->total - $svc->returnRefunds($order) > 0.009, 404);

        return $this->respond($order, 'credit', $svc);
    }

    /** Creditnota van een retour (alleen na terugbetaling), via ondertekende link voor de klant of via het beheer. */
    public function returnCreditNote(OrderReturn $return, InvoiceService $svc): Response
    {
        abort_unless($return->status === 'refunded' && $return->refund_amount !== null, 404);
        $pdf = $svc->returnPdf($return);

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="creditnota-'.strtolower($return->credit_note_number).'.pdf"',
            'Cache-Control' => 'private, no-store',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }

    private function respond(Order $order, string $type, InvoiceService $svc): Response
    {
        $pdf = $svc->pdf($order, $type);

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$svc->filename($order, $type).'"',
            'Cache-Control' => 'private, no-store',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }
}
