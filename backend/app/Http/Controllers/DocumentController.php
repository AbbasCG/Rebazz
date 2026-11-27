<?php

namespace App\Http\Controllers;

use App\Models\Order;
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

        return $this->respond($order, 'credit', $svc);
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
