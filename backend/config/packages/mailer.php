<?php

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    $container->extension('framework', [
        'mailer' => [
            'dsn' => '%env(MAILER_DSN)%',
        ],
    ]);
};
