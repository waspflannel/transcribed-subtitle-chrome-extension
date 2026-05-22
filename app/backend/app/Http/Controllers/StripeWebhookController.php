<?php

namespace App\Http\Controllers;

use App\Services\Billing\StripeWebhookService;
use App\Services\Billing\StripeWebhookVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class StripeWebhookController extends Controller
{
    public function __invoke(
        Request $request,
        StripeWebhookVerifier $verifier,
        StripeWebhookService $webhooks,
    ): JsonResponse {
        $payload = $request->getContent();

        try {
            $event = $verifier->verify($payload, $request->header('Stripe-Signature'));
        } catch (RuntimeException) {
            return response()->json(['ok' => false], 400);
        }

        $webhooks->handle($event, $payload);

        return response()->json(['ok' => true]);
    }
}
