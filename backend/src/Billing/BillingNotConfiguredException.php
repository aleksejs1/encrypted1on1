<?php

namespace App\Billing;

/** Thrown by BillingProviderInterface::createCheckoutSession() when no Stripe secret key/price id is configured — self-hosted's permanent, expected state. */
class BillingNotConfiguredException extends \RuntimeException
{
}
