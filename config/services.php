<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Tenancy\Bundle\Bootstrapper\BootstrapperChain;
use Tenancy\Bundle\Bootstrapper\DoctrineBootstrapper;
use Tenancy\Bundle\Bootstrapper\MailerBootstrapper;
use Tenancy\Bundle\Cache\TenantAwareCacheAdapter;
use Tenancy\Bundle\Cache\TenantAwareTagAwareCacheAdapter;
use Tenancy\Bundle\Command\Install\BundlesPhpInstaller;
use Tenancy\Bundle\Command\Install\Step\MailerSetupStep;
use Tenancy\Bundle\Command\TenancyInstallCommand;
use Tenancy\Bundle\Command\TenantInitCommand;
use Tenancy\Bundle\Command\TenantRunCommand;
use Tenancy\Bundle\Context\TenantContext;
use Tenancy\Bundle\EventListener\TenantContextOrchestrator;
use Tenancy\Bundle\Mailer\LruTransportCache;
use Tenancy\Bundle\Mailer\SanitizingMailerDecorator;
use Tenancy\Bundle\Mailer\TenantAwareTransportsDecorator;
use Tenancy\Bundle\Mailer\TenantContextClearedListener;
use Tenancy\Bundle\Mailer\TenantMessageDecorator;
use Tenancy\Bundle\Messenger\TenantSendingMiddleware;
use Tenancy\Bundle\Messenger\TenantWorkerMiddleware;
use Tenancy\Bundle\Provider\DoctrineTenantProvider;
use Tenancy\Bundle\Provider\TenantProviderInterface;
use Tenancy\Bundle\Resolver\ConsoleResolver;
use Tenancy\Bundle\Resolver\HeaderResolver;
use Tenancy\Bundle\Resolver\HostResolver;
use Tenancy\Bundle\Resolver\QueryParamResolver;
use Tenancy\Bundle\Resolver\ResolverChain;

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

    if (interface_exists(Doctrine\ORM\EntityManagerInterface::class)) {
        $services->set('tenancy.provider', DoctrineTenantProvider::class)
            ->args([
                service('doctrine.orm.default_entity_manager'),
                service('cache.app'),
                param('tenancy.tenant_entity_class'),
            ]);
        $services->alias(TenantProviderInterface::class, 'tenancy.provider');
    }

    $services->set(HostResolver::class)
        ->args([
            service('tenancy.provider')->nullOnInvalid(),
            param('tenancy.host.app_domain'),
        ])
        ->tag('tenancy.resolver', ['priority' => 30]);

    $services->set(HeaderResolver::class)
        ->args([service('tenancy.provider')->nullOnInvalid()])
        ->tag('tenancy.resolver', ['priority' => 20]);

    $services->set(QueryParamResolver::class)
        ->args([service('tenancy.provider')->nullOnInvalid()])
        ->tag('tenancy.resolver', ['priority' => 10]);

    $services->set(ConsoleResolver::class)
        ->autoconfigure(true)
        ->args([
            service('tenancy.provider')->nullOnInvalid(),
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

    if (interface_exists(Doctrine\ORM\EntityManagerInterface::class)) {
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

    $services->set('tenancy.cache_adapter.taggable', TenantAwareTagAwareCacheAdapter::class)
        ->decorate('cache.app.taggable')
        ->args([
            service('.inner'),
            service('tenancy.context'),
            param('tenancy.cache_prefix_separator'),
        ]);

    $services->set('tenancy.command.run', TenantRunCommand::class)
        ->args([
            service('tenancy.provider')->nullOnInvalid(),
            param('kernel.project_dir'),
        ])
        ->tag('console.command');

    $services->set('tenancy.command.init', TenantInitCommand::class)
        ->args([
            param('kernel.project_dir'),
        ])
        ->tag('console.command');

    $services->set('tenancy.command.install.bundles_php_installer', BundlesPhpInstaller::class);

    $services->set('tenancy.command.install', TenancyInstallCommand::class)
        ->args([
            param('kernel.project_dir'),
            service('tenancy.command.install.bundles_php_installer'),
            service('tenancy.mailer.install_step')->nullOnInvalid(),
            param('tenancy.tenant_entity_class'),
        ])
        ->tag('console.command');

    if (interface_exists(MessageBusInterface::class)) {
        $services->set('tenancy.messenger.sending_middleware', TenantSendingMiddleware::class)
            ->args([service('tenancy.context')]);

        $services->set('tenancy.messenger.worker_middleware', TenantWorkerMiddleware::class)
            ->args([
                service('tenancy.context'),
                service('tenancy.bootstrapper_chain'),
                service('tenancy.provider')->nullOnInvalid(),
                service('event_dispatcher'),
            ]);
    }

    if (interface_exists(MailerInterface::class)) {
        // LruTransportCache — bounded per-tenant transport cache (default 32 slots)
        $services->set('tenancy.mailer.lru_cache', LruTransportCache::class)
            ->args([param('tenancy.mailer.transport_cache_size')]);

        // MailerBootstrapper — joins the chain at priority -20 (runs AFTER
        // DoctrineBootstrapper on boot, BEFORE on clear per D-07). The clear()
        // step flushes the LRU so per-tenant SMTP sockets are closed cleanly.
        $services->set('tenancy.mailer.bootstrapper', MailerBootstrapper::class)
            ->args([service('tenancy.mailer.lru_cache')->nullOnInvalid()])
            ->tag('tenancy.bootstrapper', ['priority' => -20]);

        // TenantMessageDecorator — MessageEvent listener (priority 100) that
        // stamps X-Transport: tenant_<slug> + From / Reply-To from the active
        // tenant. Service ID MUST be exactly 'tenancy.mailer.message_decorator'
        // — the MailerTransportContractPass checks for this exact ID.
        $services->set('tenancy.mailer.message_decorator', TenantMessageDecorator::class)
            ->args([service('tenancy.context')])
            ->autoconfigure(true);

        // TenantAwareTransportsDecorator — decorates mailer.transports so the
        // worker (and sync path) route tenant_<slug> X-Transport headers via
        // the tenant's mailerDsn. The 5th constructor arg @event_dispatcher
        // is wired so SentMessageEvent / FailedMessageEvent fire from tenant
        // transports identically to the landlord transport (RESEARCH Q2).
        $services->set('tenancy.mailer.transports_decorator', TenantAwareTransportsDecorator::class)
            ->decorate('mailer.transports')
            ->args([
                service('.inner'),
                service('tenancy.provider')->nullOnInvalid(),
                service('tenancy.mailer.lru_cache'),
                service('tenancy.context'),
                service('event_dispatcher'),
            ]);

        // SanitizingMailerDecorator — wraps the MailerInterface so
        // TransportExceptionInterface bubbles up with the DSN password
        // redacted out of the message text.
        $services->set('tenancy.mailer.sanitizing_decorator', SanitizingMailerDecorator::class)
            ->decorate('mailer')
            ->args([service('.inner')]);

        // MailerSetupStep — tenancy:install --with-mailer (Plan 20-08 / D-09).
        // Encapsulates the 3 install actions: AST-insert TenantMailerConfigTrait
        // into the user's Tenant entity, scaffold Doctrine migration for the 3
        // mailer columns, append commented-out `mailer:` defaults to
        // config/packages/tenancy.yaml. Constructor takes no required args.
        $services->set('tenancy.mailer.install_step', MailerSetupStep::class);

        // TenantContextClearedListener — subscribes to TenantContextCleared
        // and flushes the LruTransportCache so per-tenant SMTP sockets are
        // closed cleanly at request/message teardown. Belt-and-suspenders
        // alongside MailerBootstrapper::clear() — guarantees the cache is
        // emptied regardless of which teardown path the kernel takes
        // (roadmap success criterion 6).
        $services->set('tenancy.mailer.context_cleared_listener', TenantContextClearedListener::class)
            ->args([service('tenancy.mailer.lru_cache')])
            ->autoconfigure(true);
    }
};
