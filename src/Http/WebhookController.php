<?php

namespace Ombabush\Fourthwall\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Ombabush\Fourthwall\Events\FourthwallWebhookReceived;
use Ombabush\Fourthwall\Fourthwall;

/**
 * Receives Fourthwall webhooks.
 *
 * The signature is checked over the RAW body before anything parses it —
 * HMAC-SHA256 with the webhook secret, base64, in X-Fourthwall-Hmac-SHA256,
 * compared in constant time. A request that fails that is a 401 and nothing
 * else happens.
 *
 * Product and collection events drop the cached catalogue so the next read
 * fetches it fresh — the same thing the official Next.js starter does with
 * revalidateTag(). Every verified event is then dispatched as
 * FourthwallWebhookReceived, so an order or a donation can be acted on by
 * the host without this package knowing what that means.
 */
class WebhookController
{
    public function __invoke(Request $request, Fourthwall $fourthwall): JsonResponse
    {
        $secret = (string) config('fourthwall.webhook.secret');

        if ($secret === '') {
            Log::error('[fourthwall] webhook received but FOURTHWALL_WEBHOOK_SECRET is not set');

            return response()->json(['error' => 'not configured'], 500);
        }

        if (! self::verify($request->getContent(), (string) $request->header('X-Fourthwall-Hmac-SHA256'), $secret)) {
            return response()->json(['error' => 'invalid signature'], 401);
        }

        $payload = json_decode($request->getContent(), true);

        if (! is_array($payload) || ! isset($payload['type'])) {
            return response()->json(['error' => 'invalid payload'], 400);
        }

        $type = (string) $payload['type'];
        $slug = $payload['data']['slug'] ?? null;

        // A product change can move it in or out of any collection, and the
        // event does not say which — so every cached collection goes.
        if (str_starts_with($type, 'PRODUCT_')) {
            $fourthwall->forget();
        } elseif ($type === 'COLLECTION_UPDATED') {
            $fourthwall->forget('collections');

            if ($slug) {
                $fourthwall->forget('collection.'.$slug);
            }
        }

        // What the Platform API told us: a promotion changed, or someone gave.
        if (str_starts_with($type, 'PROMOTION_')) {
            $fourthwall->forget('platform.promotions');
        } elseif ($type === 'DONATION') {
            $fourthwall->forget('platform.supporters');
        }

        event(new FourthwallWebhookReceived($type, $payload));

        return response()->json(['ok' => true, 'type' => $type]);
    }

    public static function verify(string $body, string $signature, string $secret): bool
    {
        if ($signature === '') {
            return false;
        }

        return hash_equals(base64_encode(hash_hmac('sha256', $body, $secret, true)), $signature);
    }
}
