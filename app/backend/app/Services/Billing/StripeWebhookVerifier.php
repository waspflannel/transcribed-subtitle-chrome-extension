<?php

namespace App\Services\Billing;

use JsonException;
use RuntimeException;

final class StripeWebhookVerifier
{
    /**
     * @return array<string, mixed>
     */
    public function verify(string $payload, ?string $signatureHeader): array
    {
        $secret = config('billing.stripe.webhook_secret');

        if (! is_string($secret) || $secret === '') {
            throw new RuntimeException('STRIPE_WEBHOOK_SECRET is not configured.');
        }

        if (! is_string($signatureHeader) || $signatureHeader === '') {
            throw new RuntimeException('Stripe signature header is missing.');
        }

        $parts = $this->parseSignatureHeader($signatureHeader);
        $timestamp = $parts['timestamp'];
        $signatures = $parts['signatures'];
        $tolerance = max(1, (int) config('billing.stripe.webhook_tolerance_seconds', 300));

        if (abs(time() - $timestamp) > $tolerance) {
            throw new RuntimeException('Stripe webhook timestamp is outside the allowed tolerance.');
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$payload, $secret);

        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                try {
                    $event = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
                } catch (JsonException $exception) {
                    throw new RuntimeException('Stripe webhook payload is invalid JSON.', previous: $exception);
                }

                if (! is_array($event)) {
                    throw new RuntimeException('Stripe webhook payload must decode to an object.');
                }

                return $event;
            }
        }

        throw new RuntimeException('Stripe webhook signature verification failed.');
    }

    /**
     * @return array{timestamp: int, signatures: array<int, string>}
     */
    private function parseSignatureHeader(string $signatureHeader): array
    {
        $timestamp = null;
        $signatures = [];

        foreach (explode(',', $signatureHeader) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, null);

            if ($key === 't' && is_string($value) && ctype_digit($value)) {
                $timestamp = (int) $value;
            }

            if ($key === 'v1' && is_string($value) && $value !== '') {
                $signatures[] = $value;
            }
        }

        if ($timestamp === null || $signatures === []) {
            throw new RuntimeException('Stripe signature header is malformed.');
        }

        return [
            'timestamp' => $timestamp,
            'signatures' => $signatures,
        ];
    }
}
