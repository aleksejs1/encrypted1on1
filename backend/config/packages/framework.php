<?php

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    // Empty by default — a directly-facing Caddy (the default docker-compose.prod.yml
    // topology) needs no trusted proxy at all, since it terminates TLS itself and
    // 'cookie_secure' => 'auto' below already sees the real scheme. Only needed when
    // this app sits behind another reverse proxy (docker-compose.prod.reverse-proxy.yml)
    // that terminates TLS itself and forwards plain HTTP — without this, 'auto' would
    // see that internal HTTP hop and silently drop the session cookie's Secure flag even
    // on real HTTPS traffic. Both framework.trusted_proxies/trusted_headers below only
    // activate when non-empty/non-falsy (verified directly against the installed
    // symfony/framework-bundle and symfony/http-kernel source, not assumed), so an empty
    // value here is a true no-op, not a silently-broken default.
    $trustedProxies = trim((string) ($_SERVER['TRUSTED_PROXIES'] ?? $_ENV['TRUSTED_PROXIES'] ?? ''));

    $container->extension('framework', [
        'secret' => '%env(APP_SECRET)%',
        'session' => [
            'enabled' => true,
            'cookie_httponly' => true,
            'cookie_samesite' => 'strict',
            // Symfony's own default — sends Secure only on HTTPS requests, so this
            // works over plain HTTP in dev without weakening the flag in prod.
            'cookie_secure' => 'auto',
        ],
        'csrf_protection' => true,
        'trusted_proxies' => $trustedProxies,
        'trusted_headers' => $trustedProxies !== ''
            ? ['x-forwarded-for', 'x-forwarded-host', 'x-forwarded-proto', 'x-forwarded-port']
            : [],
    ]);
};
