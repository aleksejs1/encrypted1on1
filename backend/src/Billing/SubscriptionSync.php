<?php

namespace App\Billing;

use App\Entity\Company;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * What a subscription event leads to: read the subscription's current state from Stripe,
 * find its company, and let the company apply it (Company::applySubscription() has the
 * rules). The same for every event type, so the order events arrive in doesn't matter.
 *
 * Accepted: two events of one subscription handled at the same moment can commit in
 * either order. Both read the current state, so they can only differ within the
 * fraction of a second around a real change.
 */
class SubscriptionSync
{
    public function __construct(
        private readonly BillingProviderInterface $billingProvider,
        private readonly EntityManagerInterface $entityManager,
        private readonly Plan $plan,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @throws BillingProviderException when Stripe doesn't return the subscription; the
     *                                  caller answers Stripe with an error, and Stripe
     *                                  delivers the event again later
     */
    public function sync(string $subscriptionId, ?string $checkoutCompanyId): SubscriptionOutcome
    {
        $subscription = $this->billingProvider->retrieveSubscription($subscriptionId);

        $company = $this->findCompany($subscription, $checkoutCompanyId);
        if (null === $company) {
            // Somebody may have paid for nothing: an operator has to look at this one.
            $this->logger->error('Stripe subscription {subscription} ({status}) belongs to no company: no company has it stored, and its metadata.company_id names none.', [
                'subscription' => $subscription->id,
                'status' => $subscription->status,
            ]);

            return SubscriptionOutcome::NoCompany;
        }

        $outcome = $company->applySubscription($subscription, $this->plan);
        $this->entityManager->flush();

        $context = ['subscription' => $subscription->id, 'status' => $subscription->status, 'company' => $company->getId(), 'own' => $company->getStripeSubscriptionId()];
        match ($outcome) {
            SubscriptionOutcome::IgnoredSecondPaying => $this->logger->error('Company {company} has two paying Stripe subscriptions: {own}, which it keeps, and {subscription}. Cancel and refund one of them by hand.', $context),
            SubscriptionOutcome::IgnoredNotOurs => $this->logger->warning('Ignored Stripe subscription {subscription} ({status}) for company {company}: it is not paying and not the company\'s own ({own}).', $context),
            default => null,
        };

        return $outcome;
    }

    /**
     * By the stored subscription id first: a known subscription must not lose its
     * company because somebody edited its tag in the Stripe dashboard. Never by the
     * customer id: another subscription of the same customer is not this company's.
     */
    private function findCompany(SubscriptionSnapshot $subscription, ?string $checkoutCompanyId): ?Company
    {
        $companies = $this->entityManager->getRepository(Company::class);

        $company = $companies->findOneBy(['stripeSubscriptionId' => $subscription->id]);
        foreach ([$subscription->companyId, $checkoutCompanyId] as $companyId) {
            if (null === $company && null !== $companyId) {
                $company = $companies->find($companyId);
            }
        }

        return $company;
    }
}
