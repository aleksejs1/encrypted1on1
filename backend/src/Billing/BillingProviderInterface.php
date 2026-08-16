<?php

namespace App\Billing;

use App\Entity\Company;

/**
 * Phase D of private/cloud-service-plan.md (not tracked in git). One real implementation
 * exists (StripeBillingProvider) — this interface exists so BillingController's own
 * auth/gating logic can be tested without ever invoking real Stripe SDK code, not because
 * a second provider is planned.
 */
interface BillingProviderInterface
{
    /**
     * @return string a URL to redirect the browser to (Stripe Checkout)
     *
     * @throws BillingNotConfiguredException if this instance has no Stripe secret key/price id set
     */
    public function createCheckoutSession(Company $company, string $successUrl, string $cancelUrl): string;

    /**
     * @throws \RuntimeException if $signatureHeader doesn't verify against $payload — callers must treat this
     *                           as "reject the request" (400), never as "no such event happened"
     */
    public function constructWebhookEvent(string $payload, string $signatureHeader): WebhookEvent;
}
