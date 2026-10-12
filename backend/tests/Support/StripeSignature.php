<?php

namespace App\Tests\Support;

/**
 * Signs a webhook payload the way Stripe documents
 * (https://stripe.com/docs/webhooks#verify-manually):
 * `t={timestamp},v1=hmac_sha256(secret, "{timestamp}.{payload}")`, the scheme
 * \Stripe\Webhook::constructEvent() verifies, so a payload signed here is to that code a
 * genuine Stripe delivery. Local HMAC only, no network call.
 */
final class StripeSignature
{
    public static function header(string $payload, string $secret): string
    {
        $timestamp = time();

        return \sprintf('t=%d,v1=%s', $timestamp, hash_hmac('sha256', "{$timestamp}.{$payload}", $secret));
    }
}
