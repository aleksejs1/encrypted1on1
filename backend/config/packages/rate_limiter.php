<?php

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

/**
 * Named limiters (three from Phase 7f, two more for password reset, one more for
 * the in-app change-password flow, one more for account deletion, one more for
 * REGISTRATION_MODE=domain self-signup) — see
 * CLAUDE.md for why the original three endpoints specifically, and why
 * neither GET /api/activation-tokens/{token} nor
 * GET /api/password-reset-tokens/{token} is limited (read-only, side-effect-free,
 * same 256-bit token-entropy argument). sliding_window smooths out the
 * "burst right at the window boundary" gap a plain fixed_window policy has.
 * Each auto-registers as a `limiter.<name>` service, injected into
 * controllers via #[Autowire(service: ...)].
 */
return static function (ContainerConfigurator $container): void {
    $container->extension('framework', [
        'rate_limiter' => [
            'login' => [
                'policy' => 'sliding_window',
                'limit' => 5,
                'interval' => '1 minute',
            ],
            'invite' => [
                'policy' => 'sliding_window',
                'limit' => 10,
                'interval' => '1 hour',
            ],
            'activation_complete' => [
                'policy' => 'sliding_window',
                'limit' => 10,
                'interval' => '1 minute',
            ],
            'password_reset_request' => [
                'policy' => 'sliding_window',
                'limit' => 5,
                'interval' => '1 hour',
            ],
            'password_reset_complete' => [
                'policy' => 'sliding_window',
                'limit' => 10,
                'interval' => '1 minute',
            ],
            'change_password' => [
                'policy' => 'sliding_window',
                'limit' => 5,
                'interval' => '1 hour',
            ],
            'delete_account' => [
                'policy' => 'sliding_window',
                'limit' => 5,
                'interval' => '1 hour',
            ],
            'signup' => [
                'policy' => 'sliding_window',
                'limit' => 5,
                'interval' => '1 hour',
            ],
        ],
    ]);
};
