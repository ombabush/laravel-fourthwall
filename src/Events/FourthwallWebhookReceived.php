<?php

namespace Ombabush\Fourthwall\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A verified webhook. `$type` is Fourthwall's event name — ORDER_PLACED,
 * DONATION, PRODUCT_UPDATED, COLLECTION_UPDATED, … — and `$payload` is the
 * whole decoded body.
 *
 *   Event::listen(function (FourthwallWebhookReceived $e) {
 *       if ($e->type === 'DONATION') { … }
 *   });
 */
class FourthwallWebhookReceived
{
    use Dispatchable;

    public function __construct(
        public readonly string $type,
        public readonly array $payload,
    ) {}

    public function data(): array
    {
        return $this->payload['data'] ?? [];
    }
}
