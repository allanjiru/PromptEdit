<?php

namespace App\Services;

use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class PixelforgeWebhookVerifier
{
    public function verify(Request $request): void
    {
        $secret = config('services.pixelforge.webhook_secret');

        if (! is_string($secret) || $secret === '') {
            throw new AccessDeniedHttpException(
                'Webhook verification is not configured.'
            );
        }

        $header = $request->header('Pixelforge-Signature');

        if (! is_string($header)) {
            throw new AccessDeniedHttpException(
                'Missing webhook signature.'
            );
        }

        // Expected format:
        // t=UNIX_TIMESTAMP,v1=HEX_HMAC
        if (! preg_match(
            '/(?:^|,)\s*t=(\d+)\s*,\s*v1=([a-fA-F0-9]{64})(?:\s*,|$)/',
            $header,
            $matches
        )) {
            throw new AccessDeniedHttpException(
                'Invalid webhook signature format.'
            );
        }

        $timestamp = (int) $matches[1];
        $signature = strtolower($matches[2]);

        if (abs(time() - $timestamp) > 300) {
            throw new AccessDeniedHttpException(
                'Webhook timestamp outside tolerance.'
            );
        }

        $signedPayload = $timestamp.'.'.$request->getContent();

        $expected = hash_hmac(
            'sha256',
            $signedPayload,
            $secret
        );

        if (! hash_equals($expected, $signature)) {
            throw new AccessDeniedHttpException(
                'Invalid webhook signature.'
            );
        }
    }
}
