<?php

namespace App\Billing;

/**
 * Stripe no longer has the customer a checkout was asked to reuse (deleted in the
 * dashboard, or the account's data was reset). The caller forgets the stored id and
 * asks again.
 */
class BillingCustomerMissingException extends BillingProviderException
{
}
