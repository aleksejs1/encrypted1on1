<?php

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
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
    ]);
};
