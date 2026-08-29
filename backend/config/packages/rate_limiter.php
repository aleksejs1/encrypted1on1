<?php

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

/*
 * Named limiters (three from Phase 7f, two more for password reset, one more for
 * the in-app change-password flow, one more for account deletion, one more for
 * REGISTRATION_MODE=domain self-signup, one more for Phase B's cloud-mode
 * self-service company creation) — see
 * docs/history.md for why the original three endpoints specifically, and why
 * neither GET /api/activation-tokens/{token} nor
 * GET /api/password-reset-tokens/{token} is limited (read-only, side-effect-free,
 * same 256-bit token-entropy argument). sliding_window smooths out the
 * "burst right at the window boundary" gap a plain fixed_window policy has.
 * Each auto-registers as a `limiter.<name>` service, injected into
 * controllers via #[Autowire(service: ...)].
 *
 * limit/interval are env-overridable (see backend/.env's "Rate limits" section
 * and docs/deployment.md's own table) — the values below are just the defaults
 * .env falls back to, so a bare checkout with no env vars set behaves exactly
 * as before this became configurable. Requested after a real operator hit the
 * hardcoded invite limit sending invites through a production SMTP provider
 * with plenty of headroom to send faster than 10/hour.
 */
return static function (ContainerConfigurator $container): void {
    $container->extension('framework', [
        'rate_limiter' => [
            // Kept IP-keyed on purpose (see AuthController::login()'s own comment) —
            // 20/min still meaningfully throttles a single-source automated guesser while
            // tolerating a normal morning login burst from a shared office/VPN NAT, which
            // 5/min did not.
            'login' => [
                'policy' => 'sliding_window',
                'limit' => '%env(int:LOGIN_RATE_LIMIT)%',
                'interval' => '%env(LOGIN_RATE_LIMIT_INTERVAL)%',
            ],
            'invite' => [
                'policy' => 'sliding_window',
                'limit' => '%env(int:INVITE_RATE_LIMIT)%',
                'interval' => '%env(INVITE_RATE_LIMIT_INTERVAL)%',
            ],
            'activation_complete' => [
                'policy' => 'sliding_window',
                'limit' => '%env(int:ACTIVATION_COMPLETE_RATE_LIMIT)%',
                'interval' => '%env(ACTIVATION_COMPLETE_RATE_LIMIT_INTERVAL)%',
            ],
            'password_reset_request' => [
                'policy' => 'sliding_window',
                'limit' => '%env(int:PASSWORD_RESET_REQUEST_RATE_LIMIT)%',
                'interval' => '%env(PASSWORD_RESET_REQUEST_RATE_LIMIT_INTERVAL)%',
            ],
            'password_reset_complete' => [
                'policy' => 'sliding_window',
                'limit' => '%env(int:PASSWORD_RESET_COMPLETE_RATE_LIMIT)%',
                'interval' => '%env(PASSWORD_RESET_COMPLETE_RATE_LIMIT_INTERVAL)%',
            ],
            'change_password' => [
                'policy' => 'sliding_window',
                'limit' => '%env(int:CHANGE_PASSWORD_RATE_LIMIT)%',
                'interval' => '%env(CHANGE_PASSWORD_RATE_LIMIT_INTERVAL)%',
            ],
            'delete_account' => [
                'policy' => 'sliding_window',
                'limit' => '%env(int:DELETE_ACCOUNT_RATE_LIMIT)%',
                'interval' => '%env(DELETE_ACCOUNT_RATE_LIMIT_INTERVAL)%',
            ],
            'signup' => [
                'policy' => 'sliding_window',
                'limit' => '%env(int:SIGNUP_RATE_LIMIT)%',
                'interval' => '%env(SIGNUP_RATE_LIMIT_INTERVAL)%',
            ],
            'create_company' => [
                'policy' => 'sliding_window',
                'limit' => '%env(int:CREATE_COMPANY_RATE_LIMIT)%',
                'interval' => '%env(CREATE_COMPANY_RATE_LIMIT_INTERVAL)%',
            ],
        ],
    ]);
};
