<?php

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    $container->extension('doctrine_migrations', [
        'migrations_paths' => [
            'App\\Migrations' => '%kernel.project_dir%/migrations',
        ],
    ]);
};
