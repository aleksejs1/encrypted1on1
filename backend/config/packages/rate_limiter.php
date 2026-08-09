<?php

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

/**
 * Three named limiters (Phase 7f) — see CLAUDE.md for why these three
 * endpoints specifically and why GET /api/activation-tokens/{token} isn't
 * limited. sliding_window smooths out the "burst right at the window
 * boundary" gap a plain fixed_window policy has. Each auto-registers as a
 * `limiter.<name>` service, injected into controllers via #[Autowire(service: ...)].
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
        ],
    ]);
};
