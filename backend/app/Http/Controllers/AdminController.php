<?php

namespace App\Http\Controllers;

use App\Mail\OrderStatusMail;
use App\Models\NewsletterSubscriber;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Services\CjService;
use App\Services\InvoiceService;
use App\Services\StockService;
use App\Services\StripeGateway;
use App\Services\TaxService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Throwable;

/** Beheer-API voor het dashboard (beveiligd met Sanctum-tokens). */
class AdminController extends Controller
{
    private const SETTING_KEYS = ['brand', 'checklist', 'automations', 'campaigns', 'videos', 'reviews', 'tax.oss'];

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate(['password' => ['required', 'string', 'max:200']]);
        $expected = (string) config('shop.admin_password');
        if (strlen($expected) < 10) {
            return response()->json(['message' => 'Stel ADMIN_PASSWORD in (minimaal 10 tekens) in het .env-bestand.'], 503);
        }
        $lockKey = 'admin-login:'.$request->ip();
        if (RateLimiter::tooManyAttempts($lockKey, 20)) {
            return response()->json(['message' => 'Te veel mislukte pogingen. Probeer het later opnieuw.'], 429);
        }
        if (! hash_equals($expected, $data['password'])) {
            RateLimiter::hit($lockKey, 3600); // naast throttle:5,1: maximaal 20 mislukte pogingen per uur per IP
            return response()->json(['message' => 'Onjuist wachtwoord.'], 401);
        }
        RateLimiter::clear($lockKey);
        $user = User::firstOrCreate(['email' => 'admin@rebazz.local'], ['name' => 'Eigenaar', 'password' => Hash::make($expected)]);
        $user->tokens()->where('expires_at', '<', now())->delete();

        return response()->json(['token' => $user->createToken('dashboard', ['admin'], now()->addDays(7))->plainTextToken]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['ok' => true]);
    }

    public function status(StripeGateway $stripe, CjService $cj): JsonResponse
    {
        return response()->json([
            'stripe' => $stripe->mode(),
            'webhook' => (bool) config('shop.stripe.webhook_secret'),
            'cj' => $cj->enabled(),
            'cjAutoPay' => (bool) config('shop.cj.auto_pay'),
            'mail' => OrderStatusMail::enabled(),
        ]);
    }

    public function orders(): JsonResponse
    {
        return response()->json(['orders' => Order::with('items')->orderByDesc('paid_at')->limit(1000)->get()->map->toApi()->values()]);
    }

    public function updateOrder(Request $request, Order $order): JsonResponse
    {
        $data = $request->validate([
            'step' => ['sometimes', 'integer', 'between:0,4'],
            'carrier' => ['sometimes', 'nullable', 'string', 'max:60'],
            'tracking' => ['sometimes', 'nullable', 'string', 'max:450'],
            'delayed' => ['sometimes', 'boolean'],
            'notify' => ['sometimes', 'boolean'],
        ]);
        $patch = array_intersect_key($data, array_flip(['step', 'carrier', 'delayed']));
        if (array_key_exists('tracking', $data)) {
            $patch['tracking'] = preg_replace('/[^A-Za-z0-9,\-]/', '', (string) $data['tracking']);
        }
        if (($patch['step'] ?? null) === 4) {
            $patch['cj_wait'] = false;
        }
        $order->update($patch);
        $order->load('items');
        $mailed = ! empty($data['notify']) && OrderStatusMail::sendFor($order);

        return response()->json(['order' => $order->toApi(), 'mailed' => $mailed]);
    }

    public function orderAction(Order $order, string $action, StripeGateway $stripe, CjService $cj): JsonResponse
    {
        try {
            switch ($action) {
                case 'notify':
                    return response()->json(['order' => $order->load('items')->toApi(), 'mailed' => OrderStatusMail::sendFor($order)]);
                case 'refund':
                    if ($order->status === 'refunded') {
                        return response()->json(['message' => 'Deze bestelling is al terugbetaald.'], 409);
                    }
                    $stripe->refund($order->payment_intent_id);
                    $order->update(['status' => 'refunded', 'cj_wait' => false]);
                    app(InvoiceService::class)->ensureCreditNote($order);
                    app(StockService::class)->restockIfUnshipped($order);
                    break;
                case 'cj-order':
                    if ($order->cj_order_ids) {
                        return response()->json(['message' => 'Deze bestelling staat al bij CJ.'], 409);
                    }
                    if ($order->cj_status === 'placing' && $order->updated_at?->gt(now()->subMinutes(15))) {
                        return response()->json(['message' => 'Deze bestelling wordt op dit moment bij CJ geplaatst. Wacht enkele minuten.'], 409);
                    }
                    if ($order->status !== 'paid') {
                        return response()->json(['message' => 'Alleen betaalde bestellingen kunnen bij CJ worden geplaatst.'], 409);
                    }
                    // Let op: controleer eerst het CJ-dashboard of de bestelling daar al bestaat (bijv. na een afgebroken poging).
                    $order->update(['cj_status' => '', 'cj_error' => '']);
                    $cj->placeOrder($order);
                    break;
                case 'cj-check':
                    $cj->sync($order);
                    break;
                default:
                    return response()->json(['message' => 'Onbekende actie'], 404);
            }
        } catch (Throwable $e) {
            Log::warning('Actie mislukt', ['order' => $order->order_no, 'action' => $action, 'error' => $e->getMessage()]);

            return response()->json(['message' => 'De actie is mislukt. Zie het logboek voor details.'], 502);
        }

        return response()->json(['order' => $order->fresh('items')->toApi()]);
    }

    public function cjSync(CjService $cj): JsonResponse
    {
        return response()->json($cj->syncAll());
    }

    public function products(): JsonResponse
    {
        return response()->json(['products' => Product::orderBy('created_at')->orderBy('id')->get()->map(fn ($p) => $p->toApi(true))->values()]);
    }

    private function productRules(?Product $product = null): array
    {
        return [
            'id' => $product ? ['nullable', 'string'] : ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9_-]+$/', Rule::unique('products', 'id')],
            'sku' => ['required', 'string', 'max:60', Rule::unique('products', 'sku')->ignore($product?->id)],
            'name' => ['required', 'string', 'max:200'],
            'category' => ['required', 'string', 'max:60'],
            'price' => ['required', 'numeric', 'min:0.5', 'max:100000'],
            'compare' => ['nullable', 'numeric', 'gt:price'],
            'cost' => ['nullable', 'numeric', 'min:0'],
            'image' => ['nullable', 'string', 'max:500', 'regex:/^(https:\/\/|\/)/'],
            'icon' => ['nullable', 'string', 'max:30'],
            'hue' => ['nullable', 'integer', 'between:0,360'],
            'supplier' => ['nullable', 'string', 'max:100'],
            'supplierUrl' => ['nullable', 'url', 'max:500'],
            'cjVid' => ['nullable', 'string', 'max:80'],
            'cjFrom' => ['nullable', 'string', 'size:2', 'alpha'],
            'cjLogistic' => ['nullable', 'string', 'max:80'],
            'warehouse' => ['nullable', 'string', 'max:60'],
            'days' => ['required', 'array', 'size:2'],
            'days.*' => ['integer', 'between:1,90'],
            'stock' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'trackStock' => ['sometimes', 'boolean'],
            'status' => ['required', 'in:active,draft'],
            'description' => ['nullable', 'string', 'max:5000'],
            'features' => ['nullable', 'array', 'max:12'],
            'features.*' => ['string', 'max:200'],
            // GPSR: verplicht voor actieve producten als SHOP_REQUIRE_GPSR=on (aanbevolen voor de livegang).
            'manufacturer' => [config('shop.require_gpsr') ? 'required_if:status,active' : 'nullable', 'nullable', 'string', 'max:300'],
            'euResponsible' => [config('shop.require_gpsr') ? 'required_if:status,active' : 'nullable', 'nullable', 'string', 'max:300'],
            'safetyInfo' => ['nullable', 'string', 'max:3000'],
        ];
    }

    public function storeProduct(Request $request): JsonResponse
    {
        $data = $request->validate($this->productRules());
        $product = Product::create(['id' => $data['id']] + Product::attributesFromApi($data));

        return response()->json(['product' => $product->toApi(true)], 201);
    }

    public function updateProduct(Request $request, Product $product): JsonResponse
    {
        $data = $request->validate($this->productRules($product));
        $product->update(Product::attributesFromApi($data));

        return response()->json(['product' => $product->fresh()->toApi(true)]);
    }

    public function destroyProduct(Product $product): JsonResponse
    {
        $product->delete(); // bestellingen bewaren hun eigen kopie van naam/prijs

        return response()->json(['ok' => true]);
    }

    public function tax(Request $request, TaxService $tax): JsonResponse
    {
        $data = $request->validate(['year' => ['required', 'integer', 'between:2020,2100'], 'quarter' => ['required', 'integer', 'between:0,4']]);

        return response()->json($tax->summary((int) $data['year'], (int) $data['quarter']));
    }

    private function allowedKey(string $key): bool
    {
        return in_array($key, self::SETTING_KEYS, true) || preg_match('/^tax\.input\.\d{4}-(\d|jaar)$/', $key) === 1;
    }

    public function setting(string $key): JsonResponse
    {
        abort_unless($this->allowedKey($key), 404);

        return response()->json(['value' => Setting::get($key)]);
    }

    public function saveSetting(Request $request, string $key): JsonResponse
    {
        abort_unless($this->allowedKey($key), 404);
        $value = $request->input('value');
        $rule = match (true) {
            $key === 'tax.oss' => ['value' => ['required', 'boolean']],
            str_starts_with($key, 'tax.input.') => ['value' => ['required', 'numeric', 'min:0', 'max:100000000']],
            default => ['value' => ['present', 'array']],
        };
        $request->validate($rule);
        if (strlen(json_encode($value)) > 200_000) {
            return response()->json(['message' => 'Te veel gegevens.'], 422);
        }
        if ($key === 'brand') {
            $value = array_filter(array_intersect_key((array) $value, config('shop.brand')), 'is_scalar');
            $value = array_map(fn ($v) => mb_substr(trim((string) $v), 0, 300), $value);
            if (isset($value['email']) && filter_var($value['email'], FILTER_VALIDATE_EMAIL) === false) {
                return response()->json(['message' => 'Ongeldig e-mailadres.'], 422);
            }
        }
        Setting::put($key, $value);

        return response()->json(['value' => $value]);
    }

    public function subscribers(): JsonResponse
    {
        return response()->json(['subscribers' => NewsletterSubscriber::confirmed()->orderByDesc('created_at')->limit(1000)->get(['email', 'lang', 'created_at'])]);
    }
}
