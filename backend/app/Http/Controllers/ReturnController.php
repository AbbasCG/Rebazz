<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\OrderReturn;
use App\Models\Setting;
use App\Services\InvoiceService;
use App\Services\OrderLookup;
use App\Services\ReturnService;
use App\Services\StripeGateway;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

/** Retouren: aanvragen door de klant (met bestelnummer + e-mail) en afhandelen door de beheerder. */
class ReturnController extends Controller
{
    /** Wat de klant over zijn retouren te zien krijgt (ook gebruikt door het volgen van een bestelling). */
    public static function customerView(Order $order, ReturnService $service): array
    {
        $order->loadMissing('returns.items.orderItem');
        $brand = Setting::brand();
        $overview = $service->overview($order);

        return [
            'returnable' => $overview['eligible'],
            'returnDeadline' => $overview['deadline'],
            'items' => $overview['items'],
            'returns' => $order->returns->sortByDesc('id')->values()->map(fn (OrderReturn $r) => [
                'rma' => $r->rma_number,
                'status' => $r->status,
                'reason' => $r->reason,
                'date' => $r->created_at?->toIso8601String(),
                'items' => $r->items->map(fn ($i) => ['name' => $i->orderItem->name.($i->orderItem->variant_label ? ' — '.$i->orderItem->variant_label : ''), 'qty' => $i->qty])->all(),
                'note' => in_array($r->status, ['approved', 'rejected'], true) ? $r->admin_note : null,
                'returnAddress' => in_array($r->status, ['approved', 'received'], true) ? ($brand['returnAddress'] ?? $brand['address'] ?? null) : null,
                'refundAmount' => $r->status === 'refunded' ? $r->refund_amount : null,
                'creditNoteUrl' => $r->status === 'refunded' && $r->credit_note_number ? app(InvoiceService::class)->returnSignedUrl($r) : null,
            ])->all(),
        ];
    }

    /** Klant vraagt een retour aan. Zelfde controle als bij het volgen van een bestelling (bestelnummer + e-mailadres, begrensd per bestelling). */
    public function store(Request $request, OrderLookup $lookup, ReturnService $service): JsonResponse
    {
        $data = $request->validate([
            'order_no' => ['required', 'string', 'max:30'],
            'email' => ['required', 'email', 'max:200'],
            'items' => ['required', 'array', 'min:1', 'max:30'],
            'items.*.id' => ['required', 'integer'],
            'items.*.qty' => ['required', 'integer', 'min:1', 'max:20'],
            'reason' => ['required', Rule::in(OrderReturn::REASONS)],
            'comment' => ['nullable', 'string', 'max:1000'],
        ]);
        ['order' => $order, 'locked' => $locked] = $lookup->verify($data['order_no'], $data['email']);
        if ($locked) {
            return response()->json(['error' => 'too_many_attempts'], 429);
        }
        if (! $order) {
            return response()->json(['error' => 'not_found'], 404);
        }
        try {
            $return = $service->request($order, $data['items'], $data['reason'], $data['comment'] ?? null);
        } catch (DomainException $e) {
            return response()->json(['error' => 'not_allowed', 'message' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true, 'rma' => $return->rma_number] + self::customerView($order->fresh(), $service), 201);
    }

    /* ---------------- Beheer ---------------- */

    private function adminShape(OrderReturn $r): array
    {
        $r->loadMissing('order', 'items.orderItem');
        $o = $r->order;

        return [
            'id' => $r->rma_number, 'status' => $r->status, 'reason' => $r->reason, 'comment' => $r->comment, 'adminNote' => $r->admin_note,
            'returnTracking' => $r->return_tracking, 'refundAmount' => $r->refund_amount, 'creditNote' => $r->credit_note_number,
            'date' => $r->created_at?->toIso8601String(), 'approvedAt' => $r->approved_at?->toIso8601String(), 'receivedAt' => $r->received_at?->toIso8601String(),
            'refundedAt' => $r->refunded_at?->toIso8601String(),
            'order' => ['id' => $o->order_no, 'customer' => $o->customer_name, 'email' => $o->email, 'country' => $o->country, 'total' => $o->total, 'status' => $o->status],
            'items' => $r->items->map(fn ($i) => [
                'name' => $i->orderItem->name.($i->orderItem->variant_label ? ' — '.$i->orderItem->variant_label : ''),
                'qty' => $i->qty, 'price' => $i->orderItem->price, 'tracked' => $i->orderItem->stock_deducted > 0, 'restocked' => $i->restocked,
            ])->all(),
            'suggestedRefund' => $r->itemsValue(),
        ];
    }

    public function index(): JsonResponse
    {
        $list = OrderReturn::with('order', 'items.orderItem')->orderByDesc('id')->limit(500)->get();

        return response()->json(['returns' => $list->map(fn ($r) => $this->adminShape($r))->values()]);
    }

    public function action(OrderReturn $return, string $action, Request $request, ReturnService $service, StripeGateway $stripe): JsonResponse
    {
        try {
            switch ($action) {
                case 'approve':
                    $d = $request->validate(['note' => ['nullable', 'string', 'max:1000'], 'tracking' => ['nullable', 'string', 'max:120']]);
                    $service->approve($return, $d['note'] ?? null, $d['tracking'] ?? null);
                    break;
                case 'reject':
                    $d = $request->validate(['note' => ['required', 'string', 'min:3', 'max:1000']]);
                    $service->reject($return, $d['note']);
                    break;
                case 'receive':
                    $d = $request->validate(['restock' => ['sometimes', 'boolean']]);
                    $service->receive($return, (bool) ($d['restock'] ?? false));
                    break;
                case 'refund':
                    $d = $request->validate(['amount' => ['required', 'numeric', 'min:0.01', 'max:100000']]);
                    $service->refund($return, (float) $d['amount'], $stripe);
                    break;
                default:
                    return response()->json(['message' => 'Onbekende actie'], 404);
            }
        } catch (ValidationException $e) {
            throw $e; // ongeldige invoer: gewoon een 422 met de veldfouten, geen "actie mislukt"
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        } catch (Throwable $e) {
            Log::warning('Retouractie mislukt', ['rma' => $return->rma_number, 'action' => $action, 'error' => $e->getMessage()]);

            return response()->json(['message' => 'De actie is mislukt. Zie het logboek voor details.'], 502);
        }

        return response()->json(['return' => $this->adminShape($return->fresh())]);
    }
}
