<?php

namespace App\Billing;

/** Thrown by a BillingProviderInterface method that needs a Stripe setting nobody configured — self-hosted's permanent, expected state. */
class BillingNotConfiguredException extends \RuntimeException
{
}
