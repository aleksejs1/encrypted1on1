<?php

namespace App\Billing;

use App\Entity\Company;

/**
 * Phase D of private/cloud-service-plan.md (not tracked in git). One real implementation
 * exists (StripeBillingProvider) — this interface exists so BillingController's own
 * auth/gating logic can be tested without ever invoking real Stripe SDK code
 * (BillingControllerTest), not because a second provider is planned.
 */
interface BillingProviderInterface
{
    /** Whether a purchase can be made here at all: every Stripe setting is set. Asks Stripe nothing. */
    public function isConfigured(): bool;

    /**
     * @param bool $reuseCustomer false to leave out the company's stored Stripe customer, after it turned out to be gone
     *
     * @return string a URL to redirect the browser to (Stripe Checkout)
     *
     * @throws BillingNotConfiguredException   unless all three Stripe settings are set
     * @throws BillingCustomerMissingException the company's stored Stripe customer is gone: forget it and ask again
     * @throws BillingProviderException        Stripe refused, or gave no answer in time
     */
    public function createCheckoutSession(Company $company, string $successUrl, string $cancelUrl, bool $reuseCustomer = true): string;

    /**
     * @throws \RuntimeException if $signatureHeader doesn't verify against $payload — callers must treat this
     *                           as "reject the request" (400), never as "no such event happened"
     */
    public function constructWebhookEvent(string $payload, string $signatureHeader): WebhookEvent;

    /**
     * The subscription's current state, read from Stripe: an event's own payload can be
     * older than the state it arrives in.
     *
     * @throws BillingNotConfiguredException
     * @throws BillingProviderException      an unknown id, a refusal, or no answer in time
     */
    public function retrieveSubscription(string $subscriptionId): SubscriptionSnapshot;
}
