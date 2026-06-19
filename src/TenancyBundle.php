<?php

declare(strict_types=1);

namespace Tenancy\Bundle;

use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use Symfony\Component\Messenger\MessageBusInterface;
use Tenancy\Bundle\Bootstrapper\DatabaseSwitchBootstrapper;
use Tenancy\Bundle\Bootstrapper\TenantBootstrapperInterface;
use Tenancy\Bundle\Command\SharedEntityResyncCommand;
use Tenancy\Bundle\Command\TenantMigrateCommand;
use Tenancy\Bundle\DBAL\TenantDriverMiddleware;
use Tenancy\Bundle\DependencyInjection\Compiler\BootstrapperChainPass;
use Tenancy\Bundle\DependencyInjection\Compiler\CacheDecoratorContractPass;
use Tenancy\Bundle\DependencyInjection\Compiler\FilesystemContractPass;
use Tenancy\Bundle\DependencyInjection\Compiler\MailerTransportContractPass;
use Tenancy\Bundle\DependencyInjection\Compiler\MessengerMiddlewarePass;
use Tenancy\Bundle\DependencyInjection\Compiler\OriginHeaderResolverConfigPass;
use Tenancy\Bundle\DependencyInjection\Compiler\ResolverChainPass;
use Tenancy\Bundle\DependencyInjection\Compiler\SharedAsyncContractPass;
use Tenancy\Bundle\DependencyInjection\Compiler\SharedEntityMutualExclusionPass;
use Tenancy\Bundle\Driver\SharedDriver;
use Tenancy\Bundle\EventListener\EntityManagerResetListener;
use Tenancy\Bundle\Filter\TenantAwareFilter;
use Tenancy\Bundle\Message\SharedEntityChangedMessage;
use Tenancy\Bundle\MessageHandler\SharedEntityChangedMessageHandler;
use Tenancy\Bundle\Resolver\OriginHeaderResolver;
use Tenancy\Bundle\Resolver\TenantResolverInterface;
use Tenancy\Bundle\Shared\SharedEntityCopier;
use Tenancy\Bundle\Shared\TenantEmSwitcher;
use Tenancy\Bundle\Shared\TenantEmSwitcherInterface;
use Tenancy\Bundle\Subscriber\SharedEntitySyncSubscriber;
use Tenancy\Bundle\Subscriber\SharedEntityWriteProtectionListener;

class TenancyBundle extends AbstractBundle
{
    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
            ->scalarNode('driver')->defaultValue('database_per_tenant')
                ->validate()
                    ->ifNotInArray(['database_per_tenant', 'shared_db'])
                    ->thenInvalid('Invalid tenancy driver "%s"; expected "database_per_tenant" or "shared_db".')
                ->end()
            ->end()
            ->booleanNode('strict_mode')->defaultTrue()->end()
            ->scalarNode('landlord_connection')->defaultValue('default')->end()
            ->scalarNode('tenant_entity_class')->defaultValue('Tenancy\\Bundle\\Entity\\Tenant')->end()
            ->scalarNode('cache_prefix_separator')->defaultValue('.')->end()
            ->arrayNode('database')
            ->addDefaultsIfNotSet()
            ->children()
            ->booleanNode('enabled')->defaultFalse()->end()
            ->end()
            ->end()
            ->arrayNode('resolvers')
            ->scalarPrototype()->end()
            ->defaultValue(['host', 'header', 'query_param', 'console'])
            ->end()
            ->arrayNode('host')
            ->addDefaultsIfNotSet()
            ->children()
            ->scalarNode('app_domain')->defaultNull()->end()
            ->end()
            ->end()
            ->arrayNode('origin')
            ->addDefaultsIfNotSet()
            ->children()
            ->arrayNode('allow_list')
            ->defaultValue([])
            ->beforeNormalization()
                ->always(static function (mixed $v): array {
                    if (!is_array($v)) {
                        return [];
                    }

                    return array_map(
                        static fn (mixed $entry): mixed => is_string($entry)
                            ? ['origin' => $entry, 'slug' => null]
                            : $entry,
                        $v,
                    );
                })
            ->end()
            ->arrayPrototype()
            ->children()
            ->scalarNode('origin')->isRequired()->cannotBeEmpty()->end()
            ->scalarNode('slug')->defaultNull()->end()
            ->end()
            ->end()
            ->end()
            ->end()
            ->end()
            ->arrayNode('mailer')
            ->addDefaultsIfNotSet()
            ->children()
            ->integerNode('transport_cache_size')->defaultValue(32)->min(1)->end()
            ->scalarNode('async')
                ->defaultValue('auto')
                ->validate()
                    ->ifNotInArray(['auto', 'true', 'false'])
                    ->thenInvalid('tenancy.mailer.async must be one of "auto", "true", "false". Got %s')
                ->end()
            ->end()
            ->end()
            ->end()
            ->arrayNode('filesystem')
            ->addDefaultsIfNotSet()
            ->children()
            ->booleanNode('enabled')->defaultFalse()->end()
            ->booleanNode('allow_per_tenant_adapter')->defaultTrue()->end()
            ->scalarNode('prefix_template')->defaultValue('tenant_{slug}/')->end()
            ->integerNode('cache_size')->defaultValue(32)->min(1)->end()
            ->end()
            ->end()
            ->arrayNode('shared')
            ->addDefaultsIfNotSet()
            ->children()
            ->booleanNode('async')->defaultFalse()->end()
            ->end()
            ->end()
            ->end()
            ->validate()
                ->ifTrue(function (array $v): bool {
                    return ($v['driver'] ?? '') === 'shared_db'
                        && ($v['database']['enabled'] ?? false) === true;
                })
                ->thenInvalid(
                    'tenancy.driver: shared_db cannot be combined with tenancy.database.enabled: true. Choose one isolation strategy.'
                )
            ->end();
    }

    /** @param array<string, mixed> $config */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $container->import('../config/services.php');

        if ($builder->hasParameter('kernel.debug') && (bool) $builder->getParameter('kernel.debug')) {
            $container->import('../config/services_dev.php');
        }

        $builder->registerForAutoconfiguration(TenantBootstrapperInterface::class)
            ->addTag('tenancy.bootstrapper');

        $builder->registerForAutoconfiguration(TenantResolverInterface::class)
            ->addTag('tenancy.resolver');

        /** @var array<string, mixed> $hostConfig */
        $hostConfig = $config['host'];

        /** @var array<string, mixed> $databaseConfig */
        $databaseConfig = $config['database'] ?? [];

        /** @var array<string, mixed> $mailerConfig */
        $mailerConfig = $config['mailer'] ?? [];
        $mailerCacheSize = $mailerConfig['transport_cache_size'] ?? 32;
        $mailerAsyncRaw = $mailerConfig['async'] ?? 'auto';
        $mailerAsync = is_scalar($mailerAsyncRaw) ? (string) $mailerAsyncRaw : 'auto';

        /** @var array<string, mixed> $filesystemConfig */
        $filesystemConfig = $config['filesystem'] ?? [];
        $filesystemEnabledRaw = $filesystemConfig['enabled'] ?? false;
        $filesystemEnabled = is_scalar($filesystemEnabledRaw) ? (bool) $filesystemEnabledRaw : false;
        $filesystemAllowPerTenantRaw = $filesystemConfig['allow_per_tenant_adapter'] ?? true;
        $filesystemAllowPerTenant = is_scalar($filesystemAllowPerTenantRaw) ? (bool) $filesystemAllowPerTenantRaw : true;
        $filesystemPrefixTemplateRaw = $filesystemConfig['prefix_template'] ?? 'tenant_{slug}/';
        $filesystemPrefixTemplate = is_scalar($filesystemPrefixTemplateRaw) ? (string) $filesystemPrefixTemplateRaw : 'tenant_{slug}/';
        $filesystemCacheSizeRaw = $filesystemConfig['cache_size'] ?? 32;
        $filesystemCacheSize = is_int($filesystemCacheSizeRaw) ? $filesystemCacheSizeRaw : 32;

        /** @var array<string, mixed> $sharedConfig */
        $sharedConfig = $config['shared'] ?? [];
        $sharedAsync = is_scalar($sharedConfig['async'] ?? false) ? (bool) ($sharedConfig['async'] ?? false) : false;

        $container->parameters()
            ->set('tenancy.driver', $config['driver'])
            ->set('tenancy.strict_mode', $config['strict_mode'])
            ->set('tenancy.landlord_connection', $config['landlord_connection'])
            ->set('tenancy.tenant_entity_class', $config['tenant_entity_class'])
            ->set('tenancy.host.app_domain', $hostConfig['app_domain'])
            ->set('tenancy.resolvers', $config['resolvers'])
            ->set('tenancy.cache_prefix_separator', $config['cache_prefix_separator'])
            ->set('tenancy.mailer.transport_cache_size', $mailerCacheSize)
            ->set('tenancy.mailer.async', $mailerAsync)
            ->set('tenancy.filesystem.enabled', $filesystemEnabled)
            ->set('tenancy.filesystem.allow_per_tenant_adapter', $filesystemAllowPerTenant)
            ->set('tenancy.filesystem.prefix_template', $filesystemPrefixTemplate)
            ->set('tenancy.filesystem.cache_size', $filesystemCacheSize)
            ->set('tenancy.shared.async', $sharedAsync);

        /** @var list<string> $configuredResolvers */
        $configuredResolvers = $config['resolvers'];
        if (in_array('origin', $configuredResolvers, true)) {
            /** @var array<string, mixed> $originConfig */
            $originConfig = $config['origin'] ?? [];
            /** @var list<array<string, mixed>> $rawAllowList */
            $rawAllowList = $originConfig['allow_list'] ?? [];

            $builder->setParameter('tenancy.origin.allow_list', $rawAllowList);

            $services = $container->services();
            $services->set('tenancy.resolver.origin', OriginHeaderResolver::class)
                ->args([
                    service('tenancy.provider')->nullOnInvalid(),
                    service('logger')->nullOnInvalid(),
                    param('tenancy.origin.allow_list'),
                ])
                ->tag('tenancy.resolver', ['priority' => 25]);
        }

        // Always-on: EntityManagerResetListener (works in both driver modes after resetManager() fix)
        $services = $container->services();
        $services->set(EntityManagerResetListener::class)
            ->autoconfigure(true)
            ->args([service('doctrine')->nullOnInvalid()]);

        if ($databaseConfig['enabled'] ?? false) {
            if (!interface_exists(\Doctrine\DBAL\Driver\Middleware::class)) {
                throw new \LogicException('tenancy.database.enabled: true requires doctrine/dbal and doctrine/doctrine-bundle. Install them (composer require doctrine/doctrine-bundle) or switch to driver: shared_db.');
            }

            $container->parameters()->set('tenancy.database.enabled', true);

            $services = $container->services();

            $services->set('tenancy.database_switch_bootstrapper', DatabaseSwitchBootstrapper::class)
                ->args([service('doctrine.dbal.tenant_connection')])
                ->tag('tenancy.bootstrapper');

            // TenantDriverMiddleware — DBAL 4 driver-middleware that reads TenantContext on every
            // Connection::connect() and merges the active tenant's params over the landlord placeholder.
            // Scoped to the `tenant` connection only — landlord connection never sees tenant params.
            $services->set('tenancy.dbal.tenant_driver_middleware', TenantDriverMiddleware::class)
                ->args([service('tenancy.context')])
                ->tag('doctrine.middleware', ['connection' => 'tenant']);

            // Rewire DoctrineTenantProvider to landlord EM (services.php is already imported above)
            $builder->getDefinition('tenancy.provider')
                ->setArgument(0, new Reference('doctrine.orm.landlord_entity_manager'));

            // Override DoctrineBootstrapper to target tenant EM (services.php targets default = landlord)
            if (interface_exists(\Doctrine\ORM\EntityManagerInterface::class)) {
                $builder->getDefinition('tenancy.doctrine_bootstrapper')
                    ->setArgument(0, new Reference('doctrine.orm.tenant_entity_manager'));
            }

            // Override EntityManagerResetListener to reset only tenant EM (not landlord)
            $builder->getDefinition(EntityManagerResetListener::class)
                ->setArgument(1, ['tenant']);

            if (class_exists(\Doctrine\Migrations\DependencyFactory::class)) {
                $services->set('tenancy.command.migrate', TenantMigrateCommand::class)
                    ->args([
                        service('tenancy.provider'),
                        service('tenancy.bootstrapper_chain'),
                        service('tenancy.context'),
                        param('tenancy.driver'),
                        service('doctrine.dbal.tenant_connection'),
                        service('doctrine.migrations.configuration')->nullOnInvalid(),
                    ])
                    ->tag('console.command');
            }

            // Shared-entity sync: wired ONLY in the database_per_tenant path because
            // this is the only path that creates the landlord + tenant connections.
            // Under shared_db there is no landlord/tenant connection — registering here
            // prevents a missing-connection error (open-question A2 resolution).
            // NO autoconfigure — Doctrine subscribers must NOT use autoconfigure (Pattern 7).
            //
            // WR-05: because this block is gated on database.enabled, and the config validator
            // (above, ~line 121) forbids `shared_db` + `database.enabled: true`, the subscriber is
            // NEVER registered under the shared_db driver. The `'shared_db' === $driver`
            // short-circuit inside SharedEntitySyncSubscriber::postFlush() is therefore unreachable
            // under any wiring this bundle produces; it is kept as defence-in-depth for future
            // wiring changes only. The no-op under shared_db is structural (the service does not
            // exist), not a runtime branch. See SharedEntityNoDatabaseKernelTest::testNoOpUnderSharedDb().
            if (interface_exists(\Doctrine\ORM\EntityManagerInterface::class)) {
                // Copier first — subscriber and write-protection both depend on it.
                $services->set('tenancy.shared_entity_copier', SharedEntityCopier::class)
                    ->args([
                        service('logger'),
                    ]);

                // EM switcher — single source of truth for per-change/per-message tenant switching (W-02).
                $services->set('tenancy.shared.em_switcher', TenantEmSwitcher::class)
                    ->args([
                        service('tenancy.context'),
                        service('doctrine'),
                    ]);
                $services->alias(TenantEmSwitcherInterface::class, 'tenancy.shared.em_switcher');

                $services->set('tenancy.shared_entity_sync_subscriber', SharedEntitySyncSubscriber::class)
                    ->args([
                        service('tenancy.context'),
                        service('tenancy.provider'),
                        service('doctrine'),
                        service('logger'),
                        param('tenancy.driver'),
                        service('tenancy.shared_entity_copier'),
                        service('tenancy.shared.em_switcher'),
                    ])
                    ->tag('doctrine.event_listener', ['event' => 'onFlush', 'connection' => 'landlord'])
                    ->tag('doctrine.event_listener', ['event' => 'postFlush', 'connection' => 'landlord']);

                $services->set('tenancy.shared_entity_write_protection', SharedEntityWriteProtectionListener::class)
                    ->args([
                        service('tenancy.context'),
                        service('tenancy.shared_entity_copier'),
                    ])
                    ->tag('doctrine.event_listener', ['event' => 'onFlush', 'connection' => 'tenant']);

                // Resync command — NOT gated on doctrine/migrations (unlike tenancy:migrate).
                // Requires only EntityManagerInterface (already guarded by this block).
                $services->set('tenancy.command.shared_resync', SharedEntityResyncCommand::class)
                    ->args([
                        service('tenancy.provider'),
                        service('tenancy.bootstrapper_chain'),
                        service('tenancy.context'),
                        param('tenancy.driver'),
                        service('doctrine.orm.landlord_entity_manager'),
                        service('doctrine'),
                        service('tenancy.shared_entity_copier'),
                    ])
                    ->tag('console.command');

                // Async fan-out handler + subscriber bus injection — gated on Messenger presence
                // AND tenancy.shared.async: true. Without the async flag, the subscriber takes the
                // sync fan-out path (postFlush iterates tenants directly) even when Messenger is
                // installed — preserving the default synchronous behaviour.
                // Handler registration stays inside database.enabled (RESEARCH Pitfall 5 — landlord
                // EM only exists when database.enabled is true).
                if ($sharedAsync && interface_exists(MessageBusInterface::class)) {
                    // Wire the subscriber's 7th arg via the NAMED argument key (D-07, future-proof
                    // against arg-order changes). Using positional setArgument(6, ...) would silently
                    // break if the subscriber gains another arg before position 6.
                    $builder->getDefinition('tenancy.shared_entity_sync_subscriber')
                        ->setArgument('$bus', new Reference('messenger.bus.default'));

                    // Register the handler with an explicit messenger.message_handler tag.
                    // NO #[AsMessageHandler] — autoconfigure is not active for bundle services.
                    $services->set('tenancy.shared_entity_changed_handler', SharedEntityChangedMessageHandler::class)
                        ->args([
                            service('doctrine.orm.landlord_entity_manager'),
                            service('tenancy.provider'),
                            service('tenancy.shared_entity_copier'),
                            service('tenancy.context'),
                            service('doctrine'),
                            service('logger'),
                            service('tenancy.shared.em_switcher'),
                        ])
                        ->tag('messenger.message_handler', ['handles' => SharedEntityChangedMessage::class]);
                }
            }
        }

        if (($config['driver'] ?? 'database_per_tenant') === 'shared_db') {
            $services = $container->services();

            $services->set('tenancy.shared_driver', SharedDriver::class)
                ->args([
                    service('doctrine.orm.default_entity_manager'),
                    service('tenancy.context'),
                    '%tenancy.strict_mode%',
                ])
                ->tag('tenancy.bootstrapper');
        }
    }

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->addCompilerPass(new BootstrapperChainPass());
        $container->addCompilerPass(new ResolverChainPass());
        $container->addCompilerPass(new CacheDecoratorContractPass());
        $container->addCompilerPass(new OriginHeaderResolverConfigPass());
        if (interface_exists(MessageBusInterface::class)) {
            // Priority 1 ensures this runs BEFORE MessengerPass (priority 0) which consumes the parameter
            $container->addCompilerPass(new MessengerMiddlewarePass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, 1);
        }
        if (interface_exists(\Symfony\Component\Mailer\MailerInterface::class)) {
            $container->addCompilerPass(new MailerTransportContractPass());
        }
        if (interface_exists(\League\Flysystem\FilesystemOperator::class)) {
            $container->addCompilerPass(new FilesystemContractPass());
        }
        if (interface_exists(\Doctrine\ORM\EntityManagerInterface::class)) {
            $container->addCompilerPass(new SharedEntityMutualExclusionPass());
            $container->addCompilerPass(new SharedAsyncContractPass());
        }
    }

    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $mapping = [
            'TenancyBundle' => [
                'is_bundle' => false,
                'type' => 'attribute',
                'dir' => __DIR__.'/Entity',
                'prefix' => 'Tenancy\\Bundle\\Entity',
                'alias' => 'TenancyBundle',
            ],
        ];

        $databaseEnabled = false;
        $isSharedDb = false;
        foreach ($builder->getExtensionConfig('tenancy') as $config) {
            if (\is_array($config['database'] ?? null) && isset($config['database']['enabled'])) {
                $databaseEnabled = (bool) $config['database']['enabled'];
            }
            if (isset($config['driver']) && 'shared_db' === $config['driver']) {
                $isSharedDb = true;
            }
        }

        if ($databaseEnabled) {
            $builder->prependExtensionConfig('doctrine', [
                'orm' => [
                    'entity_managers' => [
                        'landlord' => [
                            'mappings' => $mapping,
                        ],
                    ],
                ],
            ]);
        } else {
            $builder->prependExtensionConfig('doctrine', [
                'orm' => [
                    'mappings' => $mapping,
                ],
            ]);
        }

        if ($isSharedDb) {
            $builder->prependExtensionConfig('doctrine', [
                'orm' => [
                    'filters' => [
                        'tenancy_aware' => [
                            'class' => TenantAwareFilter::class,
                            'enabled' => true,
                        ],
                    ],
                ],
            ]);
        }

        if ($builder->hasExtension('twig')) {
            $builder->prependExtensionConfig('twig', [
                'paths' => [
                    __DIR__.'/Resources/views' => 'Tenancy',
                ],
            ]);
        }
    }
}
