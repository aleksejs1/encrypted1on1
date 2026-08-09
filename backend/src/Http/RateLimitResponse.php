<?php

namespace App\Http;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\RateLimiter\RateLimit;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The identical "build a 429" shape needed by every rate-limited route
 * (Phase 7f: login, invite, activation-complete) — a real, mechanical
 * third call site, unlike the single-instance blob-pattern code elsewhere
 * in this app that deliberately stayed unabstracted.
 */
final class RateLimitResponse
{
    public static function create(RateLimit $limit, TranslatorInterface $translator): JsonResponse
    {
        $retryAfterSeconds = max(0, $limit->getRetryAfter()->getTimestamp() - time());

        return new JsonResponse(
            ['error' => $translator->trans('errors.too_many_requests')],
            429,
            ['Retry-After' => (string) $retryAfterSeconds],
        );
    }
}
