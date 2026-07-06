<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Tenancy\Bundle\Bootstrapper\BootstrapperChain;
use Tenancy\Bundle\Bootstrapper\DoctrineBootstrapper;
use Tenancy\Bundle\Bootstrapper\FilesystemBootstrapper;
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
use Tenancy\Bundle\Filesystem\AdapterDsnParser;
use Tenancy\Bundle\Filesystem\LruFilesystemCache;
use Tenancy\Bundle\Filesystem\TenantContextClearedListener as FilesystemTenantContextClearedListener;
use Tenancy\Bundle\Mailer\LruTransportCache;
use Tenancy\Bundle\Mailer\SanitizingMailerDecorator;
use Tenancy\Bundle\Mailer\TenantAwareTransportsDecorator;
use Tenancy\Bundle\Mailer\TenantContextClearedListener;
use Tenancy\Bundle\Mailer\TenantMailerDecorator;
use Tenancy\Bundle\Mailer\TenantMessageDecorator;
use Tenancy\Bundle\Messenger\TenantSendingMiddleware;
use Tenancy\Bundle\Messenger\TenantWorkerMiddleware;
use Tenancy\Bundle\Provider\DoctrineTenantProvider;
use Tenancy\Bundle\Provider\TenantProviderInterface;
use Tenancy\Bundle\Command\TenantMaintenanceDisableCommand;
use Tenancy\Bundle\Command\TenantMaintenanceEnableCommand;
use Tenancy\Bundle\Command\TenantMaintenanceStatusCommand;
use Tenancy\Bundle\Command\TenantHealthCommand;
use Tenancy\Bundle\Controller\TenantHealthController;
use Tenancy\Bundle\Health\HealthResponseSanitizer;
use Tenancy\Bundle\Health\TenantHealthChecker;
use Tenancy\Bundle\Health\TenantHealthCheckerInterface;
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

        // TenantMailerDecorator (Plan 20-09) — INNER decorator on the
        // `mailer` service. Stamps X-Transport + From + Reply-To from the
        // active tenant BEFORE Symfony's Mailer::send routes the message.
        //
        // decoration_priority 10 = INNER (closer to original Mailer). The
        // SanitizingMailerDecorator below stays at priority 0 = OUTERMOST,
        // so its catch block still wraps any exception bubbling out of
        // the stamper or the inner mailer.
        //
        // Runtime chain (verified by AsyncCanaryTest):
        //   user -> SanitizingMailerDecorator -> TenantMailerDecorator
        //        -> Mailer -> mailer.transports -> SmtpTransport
        //
        // Closes the BOOT-04 architectural gap documented in
        // .planning/phases/20-mailer-bootstrapper/20-VERIFICATION.md
        // (Gap #1 — the MessageEvent listener fires AFTER routing).
        $services->set('tenancy.mailer.tenant_decorator', TenantMailerDecorator::class)
            ->decorate('mailer', null, 10)
            ->args([
                service('.inner'),
                service('tenancy.context'),
            ]);

        // SanitizingMailerDecorator — OUTERMOST decorator on `mailer`
        // (decoration_priority 0; explicit for clarity). Wraps any
        // TransportException / ExceptionInterface bubbling out of the
        // TenantMailerDecorator-stamped + Mailer-routed send path,
        // redacting DSN credentials via DsnSanitizer.
        $services->set('tenancy.mailer.sanitizing_decorator', SanitizingMailerDecorator::class)
            ->decorate('mailer', null, 0)
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

    if (interface_exists(\League\Flysystem\FilesystemOperator::class)) {
        // LruFilesystemCache — bounded per-tenant filesystem operator cache (default 32 slots).
        // Mirrors tenancy.mailer.lru_cache shape: constructor arg from %tenancy.filesystem.cache_size%.
        $services->set('tenancy.filesystem.lru_cache', LruFilesystemCache::class)
            ->args([param('tenancy.filesystem.cache_size')]);

        // AdapterDsnParser — zero-arg service; parses adapter DSNs for per_tenant_adapter mode.
        $services->set('tenancy.filesystem.adapter_dsn_parser', AdapterDsnParser::class);

        // FilesystemBootstrapper — joins the chain at priority -30 (runs AFTER Mailer at -20
        // on boot, BEFORE Mailer on clear per DEC-FILE-PRIORITY). The clear() step flushes
        // the LRU so per-tenant adapter instances are released cleanly.
        $services->set('tenancy.filesystem.bootstrapper', FilesystemBootstrapper::class)
            ->args([service('tenancy.filesystem.lru_cache')->nullOnInvalid()])
            ->tag('tenancy.bootstrapper', ['priority' => -30]);

        // FilesystemTenantContextClearedListener — subscribes to TenantContextCleared and flushes
        // the LruFilesystemCache. Belt-and-suspenders alongside FilesystemBootstrapper::clear() —
        // guarantees the cache is emptied regardless of which teardown path the kernel takes.
        // Mirrors tenancy.mailer.context_cleared_listener.
        $services->set('tenancy.filesystem.context_cleared_listener', FilesystemTenantContextClearedListener::class)
            ->args([service('tenancy.filesystem.lru_cache')])
            ->autoconfigure(true);
    }

    // Health check services — always registered (no optional-dependency guard).
    // TenantHealthChecker is the core probe orchestrator: set→probe→clear-in-finally.
    // HealthResponseSanitizer redacts DSN credentials before any health response (HEALTH-04).
    // Neither class imports EntityManagerInterface directly — no-Doctrine lane stays green (Pitfall 4).
    $services->set('tenancy.health.checker', TenantHealthChecker::class)
        ->args([
            service('tenancy.context'),
            service('tenancy.bootstrapper_chain'),
        ]);
    $services->alias(TenantHealthChecker::class, 'tenancy.health.checker');
    $services->alias(TenantHealthCheckerInterface::class, 'tenancy.health.checker');

    $services->set('tenancy.health.sanitizer', HealthResponseSanitizer::class);
    $services->alias(HealthResponseSanitizer::class, 'tenancy.health.sanitizer');

    // Health HTTP controller — PUBLIC so the router can resolve it (D-01, HEALTH-01/02/06).
    // No AbstractController — plain PHP service returning JsonResponse directly.
    // The two limit params come from the health config node in TenancyBundle::configure().
    $services->set('tenancy.health.controller', TenantHealthController::class)
        ->public()
        ->args([
            service('tenancy.health.checker'),
            service('tenancy.provider')->nullOnInvalid(),
            service('tenancy.health.sanitizer'),
            param('tenancy.health.fleet_default_limit'),
            param('tenancy.health.fleet_max_limit'),
        ]);

    // Health CLI command — tagged console.command so Symfony registers it (HEALTH-05).
    // Command delegates the entire probe lifecycle to TenantHealthChecker::checkOne();
    // it takes NO TenantContext dependency — the checker's finally owns the clear.
    $services->set('tenancy.command.health', TenantHealthCommand::class)
        ->args([
            service('tenancy.provider')->nullOnInvalid(),
            service('tenancy.health.checker'),
            service('tenancy.health.sanitizer'),
        ])
        ->tag('console.command');

    // Maintenance CLI commands — require Doctrine ORM (landlord EM for write operations).
    // The status command uses TenantProviderInterface::findAll() (nullOnInvalid for no-Doctrine lane).
    // The enable/disable commands default to doctrine.orm.default_entity_manager here;
    // TenancyBundle::loadExtension() rewires arg 0 to doctrine.orm.landlord_entity_manager
    // when database.enabled: true (T-32-15 mitigation, mirrors tenancy.provider rewire at line 251).
    if (interface_exists(Doctrine\ORM\EntityManagerInterface::class)) {
        // Status command uses TenantProviderInterface::findAll() — cache-bypassing operator path.
        $services->set('tenancy.command.maintenance.status', TenantMaintenanceStatusCommand::class)
            ->args([
                service('tenancy.provider')->nullOnInvalid(),
            ])
            ->tag('console.command');

        // Enable command: landlord-side write + PSR cache delete + event dispatch.
        $services->set('tenancy.command.maintenance.enable', TenantMaintenanceEnableCommand::class)
            ->args([
                service('doctrine.orm.default_entity_manager'),
                param('tenancy.tenant_entity_class'),
                service('cache.app'),
                service('event_dispatcher'),
            ])
            ->tag('console.command');

        // Disable command: mirror of enable command.
        $services->set('tenancy.command.maintenance.disable', TenantMaintenanceDisableCommand::class)
            ->args([
                service('doctrine.orm.default_entity_manager'),
                param('tenancy.tenant_entity_class'),
                service('cache.app'),
                service('event_dispatcher'),
            ])
            ->tag('console.command');
    }
};
