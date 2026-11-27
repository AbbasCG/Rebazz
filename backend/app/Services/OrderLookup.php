<?php

namespace App\Services;

use App\Models\Order;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Zoekt een bestelling op bestelnummer + e-mailadres (voor de klant zonder account). Per bestelnummer begrensd (los van het IP),
 * zodat gedistribueerd raden van e-mailadressen niet werkt. Een foute combinatie telt mee, een goede niet.
 */
class OrderLookup
{
    /** @return array{order: ?Order, locked: bool} */
    public function verify(string $orderNo, string $email): array
    {
        $orderNo = strtoupper(trim($orderNo));
        $key = 'track:'.$orderNo;
        if (RateLimiter::tooManyAttempts($key, 8)) {
            return ['order' => null, 'locked' => true];
        }
        $order = Order::where('order_no', $orderNo)->first();
        if (! $order || strcasecmp($order->email, trim($email)) !== 0) {
            RateLimiter::hit($key, 3600);

            return ['order' => null, 'locked' => false];
        }

        return ['order' => $order, 'locked' => false];
    }
}
