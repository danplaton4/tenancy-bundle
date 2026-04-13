<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\Messenger\MessageBusInterface;
use Tenancy\Bundle\Bootstrapper\BootstrapperChain;
use Tenancy\Bundle\Bootstrapper\DoctrineBootstrapper;
use Tenancy\Bundle\Command\TenantRunCommand;
use Tenancy\Bundle\Context\TenantContext;
use Tenancy\Bundle\EventListener\TenantContextOrchestrator;
use Tenancy\Bundle\Provider\DoctrineTenantProvider;
use Tenancy\Bundle\Provider\TenantProviderInterface;
use Tenancy\Bundle\Resolver\HeaderResolver;
use Tenancy\Bundle\Resolver\HostResolver;
use Tenancy\Bundle\Resolver\QueryParamResolver;
use Tenancy\Bundle\Resolver\ConsoleResolver;
use Tenancy\Bundle\Cache\TenantAwareCacheAdapter;
use Tenancy\Bundle\Messenger\TenantSendingMiddleware;
use Tenancy\Bundle\Messenger\TenantWorkerMiddleware;
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

    $services->set(HostResolver::class)
        ->args([
            service('tenancy.provider'),
            param('tenancy.host.app_domain'),
        ])
        ->tag('tenancy.resolver', ['priority' => 30]);

    $services->set(HeaderResolver::class)
        ->args([service('tenancy.provider')])
        ->tag('tenancy.resolver', ['priority' => 20]);

    $services->set(QueryParamResolver::class)
        ->args([service('tenancy.provider')])
        ->tag('tenancy.resolver', ['priority' => 10]);

    $services->set('tenancy.provider', DoctrineTenantProvider::class)
        ->args([
            service('doctrine.orm.default_entity_manager'),
            service('cache.app'),
            param('tenancy.tenant_entity_class'),
        ]);
    $services->alias(TenantProviderInterface::class, 'tenancy.provider');

    $services->set(ConsoleResolver::class)
        ->autoconfigure(true)
        ->args([
            service('tenancy.provider'),
            service('tenancy.context'),
            service('tenancy.bootstrapper_chain'),
            service('event_dispatcher'),
        ]);

    $services->set(TenantContextOrchestrator::class)
        ->autoconfigure(true)
        ->args([
            service('tenancy.context'),
            service('tenancy.bootstrapper_chain'),
            service('event_dispatcher'),
            service('tenancy.resolver_chain'),
        ]);

    if (interface_exists(\Doctrine\ORM\EntityManagerInterface::class)) {
        $services->set('tenancy.doctrine_bootstrapper', DoctrineBootstrapper::class)
            ->args([service('doctrine.orm.entity_manager')->nullOnInvalid()])
            ->tag('tenancy.bootstrapper', ['priority' => -10]);
    }

    $services->set('tenancy.cache_adapter', TenantAwareCacheAdapter::class)
        ->decorate('cache.app')
        ->args([
            service('.inner'),
            service('tenancy.context'),
            param('tenancy.cache_prefix_separator'),
        ]);


    $services->set('tenancy.command.run', TenantRunCommand::class)
        ->args([
            service('tenancy.provider'),
            param('kernel.project_dir'),
        ])
        ->tag('console.command');

    if (interface_exists(MessageBusInterface::class)) {
        $services->set('tenancy.messenger.sending_middleware', TenantSendingMiddleware::class)
            ->args([service('tenancy.context')]);

        $services->set('tenancy.messenger.worker_middleware', TenantWorkerMiddleware::class)
            ->args([
                service('tenancy.context'),
                service('tenancy.bootstrapper_chain'),
                service('tenancy.provider'),
                service('event_dispatcher'),
            ]);
    }
};
