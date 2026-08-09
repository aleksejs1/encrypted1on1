<?php

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    $container->extension('framework', [
        'test' => true,
        // The default native session storage is one real PHP session per process —
        // it can't represent two independent logged-in users (e.g. employee +
        // manager) within a single functional test. This swaps in the storage
        // Symfony's own docs recommend for exactly that scenario.
        'session' => [
            'storage_factory_id' => 'session.storage.factory.mock_file',
        ],
    ]);
};
