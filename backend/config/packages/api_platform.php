<?php

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    $container->extension('api_platform', [
        'title' => 'encrypted1on1',
        'version' => '0.1.0',
        'defaults' => [
            'route_prefix' => '/api',
        ],
        // Plain JSON, not JSON-LD, as the default: the frontend has no use for
        // hypermedia/JSON-LD semantics, and it keeps every API Platform
        // resource's response shape consistent with the hand-written
        // controllers (AuthController, AnketaController), which return plain
        // JSON too.
        'formats' => ['json' => ['application/json']],
    ]);
};
