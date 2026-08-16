<?php

use App\Billing\BillingProviderInterface;
use App\Billing\StripeBillingProvider;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    $services = $container->services()
        ->defaults()
            ->autowire()
            ->autoconfigure()
            ->bind('string $frontendBaseUrl', '%env(FRONTEND_URL)%')
            ->bind('string $mailerFrom', '%env(MAILER_FROM)%')
            ->bind('bool $cloudMode', '%env(bool:CLOUD_MODE)%')
            ->bind('int $defaultSeatLimit', '%env(int:DEFAULT_SEAT_LIMIT)%')
            ->bind('string $stripeSecretKey', '%env(STRIPE_SECRET_KEY)%')
            ->bind('string $stripeWebhookSecret', '%env(STRIPE_WEBHOOK_SECRET)%')
            ->bind('string $stripePriceId', '%env(STRIPE_PRICE_ID)%');

    $services->load('App\\', __DIR__.'/../src/')
        ->exclude(__DIR__.'/../src/Kernel.php');

    // One real implementation for now (Phase D of private/cloud-service-plan.md, not
    // tracked in git) — the interface exists so BillingController's auth/gating logic
    // can be tested without ever invoking real Stripe SDK code, not because a second
    // implementation is planned.
    $services->alias(BillingProviderInterface::class, StripeBillingProvider::class);
};
