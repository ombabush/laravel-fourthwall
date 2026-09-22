<?php

namespace Ombabush\Fourthwall\Exceptions;

use Illuminate\Http\Client\Response;
use RuntimeException;

class FourthwallException extends RuntimeException
{
    public ?int $status = null;

    public static function fromResponse(Response $response, string $what): self
    {
        $body = $response->json();
        $detail = is_array($body) ? ($body['code'] ?? $body['title'] ?? $body['message'] ?? null) : null;

        $e = new self(sprintf('Fourthwall %s: HTTP %d%s', $what, $response->status(), $detail ? " ({$detail})" : ''));
        $e->status = $response->status();

        return $e;
    }

    public static function notConfigured(string $what): self
    {
        return new self("Fourthwall is not configured: {$what}.");
    }
}
