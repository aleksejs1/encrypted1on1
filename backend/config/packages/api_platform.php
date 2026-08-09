<?php

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    $container->extension('api_platform', [
        'title' => 'encrypted1on1',
        'version' => '0.1.0',
        'defaults' => [
            'route_prefix' => '/api',
        ],
    ]);
};
