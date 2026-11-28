<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\NewsletterSubscriber;
use App\Models\Order;
use App\Models\Product;
use App\Models\WishlistItem;
use App\Services\InvoiceService;
use App\Services\PrivacyService;
use App\Services\ReturnService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Het klantaccount: eigen bestellingen, retouren, verlanglijst, profiel en eigen gegevens (inzage/wissen). Alles is beperkt tot de ingelogde klant. */
class AccountController extends Controller
{
    private function me(Request $request): Customer
    {
        return $request->user();
    }

    private function newsletterOn(Customer $c): bool
    {
        return NewsletterSubscriber::whereRaw('lower(email) = ?', [$c->email])->whereNotNull('confirmed_at')->exists();
    }

    public function show(Request $request): JsonResponse
    {
        $c = $this->me($request);

        return response()->json(['email' => $c->email, 'name' => $c->name, 'newsletter' => $this->newsletterOn($c)]);
    }

    public function update(Request $request): JsonResponse
    {
        $c = $this->me($request);
        $d = $request->validate(['name' => ['sometimes', 'nullable', 'string', 'max:150'], 'newsletter' => ['sometimes', 'boolean']]);
        if (array_key_exists('name', $d)) {
            $c->update(['name' => trim(strip_tags((string) $d['name']))]);
        }
        if (array_key_exists('newsletter', $d)) {
            $sub = NewsletterSubscriber::whereRaw('lower(email) = ?', [$c->email]);
            if ($d['newsletter']) {
                // De klant zet de schakelaar zelf aan terwijl het mailadres al geverifieerd is (inlogcode): dat is een bewuste, bevestigde aanmelding.
                NewsletterSubscriber::updateOrCreate(['email' => $c->email], ['lang' => substr((string) $request->input('lang', 'nl'), 0, 2), 'confirmed_at' => now(), 'confirm_token' => null]);
            } else {
                $sub->delete();
            }
        }

        return $this->show($request);
    }

    private function orderShape(Order $o, ReturnService $returns, bool $detail = false): array
    {
        $invoices = app(InvoiceService::class);
        $base = [
            'order_no' => $o->order_no, 'date' => $o->paid_at?->toIso8601String(), 'status' => $o->status, 'step' => $o->step, 'delayed' => $o->delayed,
            'carrier' => $o->carrier, 'tracking' => $o->tracking, 'total' => $o->total,
            'items' => $o->items->map(fn ($i) => ['name' => $i->name, 'variant' => $i->variant_label ?: null, 'qty' => $i->qty, 'price' => $i->price])->values()->all(),
            'invoiceUrl' => in_array($o->status, ['paid', 'refunded'], true) ? $invoices->signedUrl($o) : null,
            'anonymized' => (bool) $o->anonymized_at,
        ];
        if (! $detail) {
            return $base + ['returnable' => $returns->overview($o)['eligible'], 'returnCount' => $o->returns->count()];
        }

        $view = ReturnController::customerView($o, $returns);

        // De bestelregels (items) en de retourbare regels (returnItems, met bestelregel-id en aantal) zijn twee verschillende lijsten.
        return $base + [
            'shipping' => $o->shipping, 'discount' => $o->discount, 'country' => $o->country,
            'returnable' => $view['returnable'], 'returnDeadline' => $view['returnDeadline'], 'returnItems' => $view['items'], 'returns' => $view['returns'],
        ];
    }

    public function orders(Request $request, ReturnService $returns): JsonResponse
    {
        $orders = $this->me($request)->orders()->with('items', 'returns.items.orderItem')->orderByDesc('id')->limit(200)->get();

        return response()->json(['orders' => $orders->map(fn (Order $o) => $this->orderShape($o, $returns))->values()]);
    }

    private function ownOrder(Request $request, string $orderNo): Order
    {
        $o = $this->me($request)->orders()->where('order_no', strtoupper(trim($orderNo)))->with('items', 'returns.items.orderItem')->first();
        abort_unless($o, 404); // andermans bestelling is voor deze klant niet te onderscheiden van een onbestaande

        return $o;
    }

    public function order(Request $request, string $orderNo, ReturnService $returns): JsonResponse
    {
        return response()->json($this->orderShape($this->ownOrder($request, $orderNo), $returns, true));
    }

    /** Retour aanvragen voor een eigen bestelling: geen bestelnummer/e-mail-controle nodig, het account is het bewijs. */
    public function createReturn(Request $request, ReturnService $returns): JsonResponse
    {
        $d = $request->validate([
            'order_no' => ['required', 'string', 'max:30'],
            'items' => ['required', 'array', 'min:1', 'max:30'], 'items.*.id' => ['required', 'integer'], 'items.*.qty' => ['required', 'integer', 'min:1', 'max:20'],
            'reason' => ['required', Rule::in(\App\Models\OrderReturn::REASONS)], 'comment' => ['nullable', 'string', 'max:1000'],
        ]);
        $order = $this->ownOrder($request, $d['order_no']);
        try {
            $r = $returns->request($order, $d['items'], $d['reason'], $d['comment'] ?? null);
        } catch (DomainException $e) {
            return response()->json(['error' => 'not_allowed', 'message' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true, 'rma' => $r->rma_number] + $this->orderShape($this->ownOrder($request, $d['order_no']), $returns, true), 201);
    }

    /* ---------------- Verlanglijst ---------------- */

    private function wishIds(Customer $c): array
    {
        return WishlistItem::where('customer_id', $c->id)->join('products', 'products.id', '=', 'wishlist_items.product_id')
            ->where('products.status', 'active')->orderByDesc('wishlist_items.id')->pluck('wishlist_items.product_id')->all();
    }

    public function wishlist(Request $request): JsonResponse
    {
        return response()->json(['ids' => $this->wishIds($this->me($request))]);
    }

    public function wishlistAdd(Request $request, string $product): JsonResponse
    {
        abort_unless(Product::active()->whereKey($product)->exists(), 404);
        WishlistItem::firstOrCreate(['customer_id' => $this->me($request)->id, 'product_id' => $product], ['created_at' => now()]);

        return $this->wishlist($request);
    }

    public function wishlistRemove(Request $request, string $product): JsonResponse
    {
        WishlistItem::where('customer_id', $this->me($request)->id)->where('product_id', $product)->delete();

        return $this->wishlist($request);
    }

    /** Verlanglijst van de gast (localStorage) samenvoegen met die van het account na het inloggen. */
    public function wishlistMerge(Request $request): JsonResponse
    {
        $ids = $request->validate(['ids' => ['required', 'array', 'max:100'], 'ids.*' => ['string', 'max:40']])['ids'];
        $c = $this->me($request);
        foreach (Product::active()->whereIn('id', $ids)->pluck('id') as $id) {
            WishlistItem::firstOrCreate(['customer_id' => $c->id, 'product_id' => $id], ['created_at' => now()]);
        }

        return $this->wishlist($request);
    }

    /* ---------------- Eigen gegevens (AVG) ---------------- */

    /** Inzage/export: de klant is al geverifieerd, dus direct downloaden. */
    public function export(Request $request, PrivacyService $privacy)
    {
        $c = $this->me($request);
        $data = $privacy->export($c->email);
        $data['account'] = ['email' => $c->email, 'name' => $c->name, 'created_at' => $c->created_at?->toIso8601String(), 'last_login_at' => $c->last_login_at?->toIso8601String(), 'wishlist' => $this->wishIds($c)];
        $privacy->log($c->email, 'export', 'customer', ['orders' => count($data['orders']), 'account' => true]);

        return response()->json($data, 200, ['Content-Disposition' => 'attachment; filename="mijn-gegevens.json"', 'Cache-Control' => 'private, no-store'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** Account en gegevens wissen (met inachtneming van de wettelijke bewaartermijn van bestelgegevens). Alle tokens worden ingetrokken. */
    public function erase(Request $request, PrivacyService $privacy): JsonResponse
    {
        $c = $this->me($request);
        $result = $privacy->erase($c->email);
        $privacy->log($c->email, 'erase', 'customer', ['newsletter_deleted' => $result['newsletter_deleted'], 'minimized' => $result['orders_minimized'], 'anonymized' => $result['orders_anonymized'], 'account' => true]);
        $c->tokens()->delete();
        $c->delete(); // verlanglijst verdwijnt mee; bestellingen houden alleen het (geminimaliseerde) record dat de wet vereist

        return response()->json($result + ['account_deleted' => true]);
    }
}
