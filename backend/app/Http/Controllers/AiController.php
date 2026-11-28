<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Product;
use App\Services\AiAssistant;
use App\Services\AiException;
use App\Services\ClaudeService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** AI-hulp in het beheer (Claude): voorstellen voor teksten en klantenservice-antwoorden. Niets wordt automatisch opgeslagen of verstuurd. */
class AiController extends Controller
{
    private function run(Closure $fn): JsonResponse
    {
        try {
            return response()->json($fn());
        } catch (AiException $e) {
            return response()->json(['error' => $e->errorCode, 'message' => $e->getMessage()], $e->status);
        }
    }

    public function status(Request $request, ClaudeService $claude): JsonResponse
    {
        $today = $claude->usageToday();

        return response()->json([
            'enabled' => $claude->enabled(), 'model' => $claude->model(),
            'today' => $today + ['limit' => (int) config('shop.ai.daily_limit'), 'mine' => $claude->usageToday($request->user()->id)['calls'], 'myLimit' => (int) config('shop.ai.user_daily_limit')],
            'month' => $claude->usageMonth(),
        ]);
    }

    public function description(Request $request, AiAssistant $ai): JsonResponse
    {
        $d = $request->validate([
            'name' => ['required', 'string', 'max:200'], 'category' => ['nullable', 'string', 'max:60'], 'features' => ['nullable', 'array', 'max:12'], 'features.*' => ['string', 'max:200'],
            'description' => ['nullable', 'string', 'max:5000'], 'notes' => ['nullable', 'string', 'max:1000'], 'manufacturer' => ['nullable', 'string', 'max:300'],
            'lang' => ['nullable', Rule::in(array_keys(AiAssistant::LANGS))],
        ]);

        return $this->run(fn () => $ai->describe($d, $d['lang'] ?? 'nl', $request->user()->id));
    }

    public function adCopy(Request $request, AiAssistant $ai): JsonResponse
    {
        $d = $request->validate([
            'product_id' => ['required', 'string', 'max:40'], 'platform' => ['required', 'in:meta,google,tiktok,pinterest'],
            'goal' => ['required', 'in:awareness,conversie,retargeting'], 'lang' => ['nullable', Rule::in(array_keys(AiAssistant::LANGS))],
        ]);
        $p = Product::findOrFail($d['product_id']);

        return $this->run(fn () => $ai->adCopy($p, $d['platform'], $d['goal'], $d['lang'] ?? 'nl', $request->user()->id));
    }

    public function videoScript(Request $request, AiAssistant $ai): JsonResponse
    {
        $d = $request->validate([
            'product_id' => ['required', 'string', 'max:40'], 'style' => ['required', 'in:ugc,demo,luxury,problem'], 'duration' => ['required', 'in:15,30,45,60'],
            'lang' => ['nullable', Rule::in(array_keys(AiAssistant::LANGS))], 'cta' => ['required', 'string', 'max:120'],
        ]);
        $p = Product::findOrFail($d['product_id']);

        return $this->run(fn () => $ai->videoScript($p, $d['style'], (int) $d['duration'], $d['lang'] ?? 'nl', $d['cta'], $request->user()->id));
    }

    public function supportReply(Request $request, AiAssistant $ai): JsonResponse
    {
        $d = $request->validate(['message' => ['required', 'string', 'min:2', 'max:4000'], 'order_no' => ['nullable', 'string', 'max:30']]);
        $order = null;
        if (! empty($d['order_no'])) {
            $order = Order::where('order_no', strtoupper(trim($d['order_no'])))->first();
            if (! $order) {
                return response()->json(['error' => 'order_not_found', 'message' => 'Dit bestelnummer bestaat niet.'], 422);
            }
        }

        return $this->run(fn () => $ai->supportReply($d['message'], $order, $request->user()->id));
    }
}
