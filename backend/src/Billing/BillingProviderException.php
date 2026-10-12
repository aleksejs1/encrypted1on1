<?php

namespace App\Billing;

/**
 * Stripe refused a request or couldn't be reached. The provider's own exception, so a
 * caller doesn't need the SDK's types; the SDK's exception is the previous one.
 */
class BillingProviderException extends \RuntimeException
{
}
