<?php

namespace App\Billing;

/**
 * Stripe no longer has the customer it was asked about (deleted in the dashboard, or
 * the account's data was reset). A checkout forgets the stored id and asks again
 * without it; the portal has nothing to open.
 */
class BillingCustomerMissingException extends BillingProviderException
{
}
