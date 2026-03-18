<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Tenancy\Bundle\Bootstrapper\BootstrapperChain;
use Tenancy\Bundle\Context\TenantContext;
use Tenancy\Bundle\EventListener\TenantContextOrchestrator;
use Tenancy\Bundle\Provider\DoctrineTenantProvider;
use Tenancy\Bundle\Provider\TenantProviderInterface;
use Tenancy\Bundle\Resolver\ResolverChain;

use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
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

    $services->set('tenancy.resolver_chain', ResolverChain::class)
        ->public(false);
    $services->alias(ResolverChain::class, 'tenancy.resolver_chain');

    $services->set('tenancy.provider', DoctrineTenantProvider::class)
        ->args([
            service('doctrine.orm.default_entity_manager'),
            service('cache.app'),
            param('tenancy.tenant_entity_class'),
        ]);
    $services->alias(TenantProviderInterface::class, 'tenancy.provider');

    $services->set(TenantContextOrchestrator::class)
        ->autoconfigure(true)
        ->args([
            service('tenancy.context'),
            service('tenancy.bootstrapper_chain'),
            service('event_dispatcher'),
            service('tenancy.resolver_chain'),
        ]);
};
