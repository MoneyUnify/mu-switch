<?php

namespace App\Http\Controllers;

use App\ApiResponse;
use App\Http\Controllers\Providers\PaypalController;
use App\Jobs\ProcessPaypalWebhookEvent;
use App\Models\PaymentProvider;
use App\Models\ProviderWebhookEvent;
use App\Support\Paypal\PaypalClient;
use App\Support\Paypal\PaypalException;
use App\Support\ProviderCallContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Receives PayPal webhooks for one PayPal provider. A genuine event is stored
 * and answered at once; a queued job then re-reads its payment from PayPal,
 * so state always comes from PayPal and a slow PayPal call never makes the
 * delivery time out.
 */
class PaypalWebhookController extends Controller
{
    /**
     * Verify, de-duplicate and queue a webhook delivery.
     */
    public function __invoke(Request $request, PaymentProvider $provider): JsonResponse
    {
        if ($provider->class !== PaypalController::class) {
            return ApiResponse::error('Not Found', 404);
        }

        $client = PaypalClient::fromConfig($provider->config ?? []);
        $webhookId = trim((string) ($provider->config['webhook_id'] ?? ''));

        if (! $client || $webhookId === '') {
            return ApiResponse::error('Webhooks are not configured for this provider', 422);
        }

        $event = $request->json()->all();

        if (! is_string($event['id'] ?? null) || ! is_string($event['event_type'] ?? null)) {
            return ApiResponse::error('Malformed webhook event', 400);
        }

        try {
            $genuine = $this->isGenuine($request, $client, $provider, $webhookId);
        } catch (PaypalException) {
            return ApiResponse::error('Could not reach PayPal, please retry', 503);
        }

        if (! $genuine) {
            return ApiResponse::error('Invalid webhook signature', 401);
        }

        $record = ProviderWebhookEvent::createOrFirst(
            ['payment_provider_id' => $provider->id, 'event_id' => $event['id']],
            ['event_type' => $event['event_type'], 'payload' => $event],
        );

        if ($record->processed_at) {
            return ApiResponse::success('Event already processed');
        }

        ProcessPaypalWebhookEvent::dispatch($record);

        return ApiResponse::success('Event received');
    }

    /**
     * Confirm the delivery with PayPal's signature-verification API.
     */
    private function isGenuine(Request $request, PaypalClient $client, PaymentProvider $provider, string $webhookId): bool
    {
        ProviderCallContext::set($provider);

        try {
            return $client->verifyWebhookSignature([
                'auth_algo' => $request->header('PAYPAL-AUTH-ALGO'),
                'cert_url' => $request->header('PAYPAL-CERT-URL'),
                'transmission_id' => $request->header('PAYPAL-TRANSMISSION-ID'),
                'transmission_sig' => $request->header('PAYPAL-TRANSMISSION-SIG'),
                'transmission_time' => $request->header('PAYPAL-TRANSMISSION-TIME'),
            ], $request->getContent(), $webhookId);
        } finally {
            ProviderCallContext::clear();
        }
    }
}
