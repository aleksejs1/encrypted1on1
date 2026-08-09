<?php

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    $services = $container->services()
        ->defaults()
            ->autowire()
            ->autoconfigure()
            ->bind('string $frontendBaseUrl', '%env(FRONTEND_URL)%');

    $services->load('App\\', __DIR__.'/../src/')
        ->exclude(__DIR__.'/../src/Kernel.php');
};
