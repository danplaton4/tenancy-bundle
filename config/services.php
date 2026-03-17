<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Tenancy\Bundle\Bootstrapper\BootstrapperChain;
use Tenancy\Bundle\Context\TenantContext;
use Tenancy\Bundle\EventListener\TenantContextOrchestrator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->set('tenancy.context', TenantContext::class)
        ->public();
    $services->alias(TenantContext::class, 'tenancy.context');

    $services->set('tenancy.bootstrapper_chain', BootstrapperChain::class)
        ->public(false)
        ->args([service('event_dispatcher')]);
    $services->alias(BootstrapperChain::class, 'tenancy.bootstrapper_chain');

    $services->set(TenantContextOrchestrator::class)
        ->autoconfigure(true)
        ->args([
            service('tenancy.context'),
            service('tenancy.bootstrapper_chain'),
            service('event_dispatcher'),
        ]);
};
