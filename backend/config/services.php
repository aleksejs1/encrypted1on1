<?php

use App\Billing\BillingProviderInterface;
use App\Billing\StripeBillingProvider;
use App\Mailer\TimeoutEnforcingSmtpTransportFactory;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

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
        ->exclude([
            __DIR__.'/../src/Kernel.php',
            // Custom PHPStan rules implement PHPStan\Rules\Rule, a require-dev-only
            // interface — a --no-dev production install can't autoload it. Excluded from
            // the container entirely rather than relying on Symfony's compiler happening
            // to drop it as an unused/unloadable service (see
            // docs/architecture-invariants.md §1's PHPStan-rule note).
            __DIR__.'/../src/PHPStan',
        ]);

    // One real implementation for now (Phase D of private/cloud-service-plan.md, not
    // tracked in git) — the interface exists so BillingController's auth/gating logic
    // can be tested without ever invoking real Stripe SDK code, not because a second
    // implementation is planned.
    $services->alias(BillingProviderInterface::class, StripeBillingProvider::class);

    // Bounds AnketaNotifier's synchronous SMTP send to a real socket timeout instead of
    // PHP's default_socket_timeout ini (60s) — see the class docblock. Decoration requires
    // explicit wiring (the tag that makes this discoverable as *the* "smtp" factory doesn't
    // transfer from the decorated service automatically) and a fixed re-declared id, not the
    // plain autowired-by-FQCN definition load() already created for this class above.
    $services->set(TimeoutEnforcingSmtpTransportFactory::class)
        ->decorate('mailer.transport_factory.smtp')
        ->args([
            service('.inner'),
            10.0,
        ])
        ->tag('mailer.transport_factory');
};
