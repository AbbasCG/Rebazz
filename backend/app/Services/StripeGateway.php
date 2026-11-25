<?php

namespace App\Services;

use RuntimeException;
use Stripe\StripeClient;
use Stripe\Webhook;

/** Dunne laag rond de Stripe-SDK, zodat tests hem kunnen vervangen. */
class StripeGateway
{
    private ?StripeClient $client = null;

    public function configured(): bool
    {
        return (bool) config('shop.stripe.secret');
    }

    public function mode(): string
    {
        $key = (string) config('shop.stripe.secret');
        if (! $key) {
            return 'missing';
        }

        return str_starts_with($key, 'sk_live') || str_starts_with($key, 'rk_live') ? 'live' : 'test';
    }

    private function client(): StripeClient
    {
        if (! $this->configured()) {
            throw new RuntimeException('De betaalkoppeling is nog niet ingesteld (STRIPE_SECRET ontbreekt).');
        }

        return $this->client ??= new StripeClient(config('shop.stripe.secret'));
    }

    /** @return array<string, mixed> */
    public function createCheckoutSession(array $params): array
    {
        return $this->client()->checkout->sessions->create($params)->toArray();
    }

    /** @return array<string, mixed> */
    public function retrieveSession(string $id): array
    {
        return $this->client()->checkout->sessions->retrieve($id, ['expand' => ['payment_intent.latest_charge']])->toArray();
    }

    /** @return array<int, array<string, mixed>> */
    public function listPaidSessions(int $max = 500): array
    {
        $out = [];
        $params = ['limit' => 100, 'status' => 'complete', 'expand' => ['data.payment_intent.latest_charge']];
        foreach ($this->client()->checkout->sessions->all($params)->autoPagingIterator() as $s) {
            $out[] = $s->toArray();
            if (count($out) >= $max) {
                break;
            }
        }

        return $out;
    }

    public function refund(string $paymentIntentId): void
    {
        $this->client()->refunds->create(['payment_intent' => $paymentIntentId], ['idempotency_key' => 'refund-'.$paymentIntentId]);
    }

    /** Controleert de handtekening van een Stripe-webhook en geeft het event terug. */
    public function verifyWebhook(string $payload, string $signature): array
    {
        $secret = (string) config('shop.stripe.webhook_secret');
        if (! $secret) {
            throw new RuntimeException('STRIPE_WEBHOOK_SECRET ontbreekt.');
        }

        return Webhook::constructEvent($payload, $signature, $secret)->toArray();
    }
}
