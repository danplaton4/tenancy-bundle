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
use Tenancy\Bundle\Command\TenantMigrateCommand;
use Tenancy\Bundle\DependencyInjection\Compiler\BootstrapperChainPass;
use Tenancy\Bundle\DependencyInjection\Compiler\MessengerMiddlewarePass;
use Tenancy\Bundle\DependencyInjection\Compiler\ResolverChainPass;
use Tenancy\Bundle\Driver\SharedDriver;
use Tenancy\Bundle\EventListener\EntityManagerResetListener;
use Tenancy\Bundle\Filter\TenantAwareFilter;
use Tenancy\Bundle\Resolver\TenantResolverInterface;

class TenancyBundle extends AbstractBundle
{
    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
            ->scalarNode('driver')->defaultValue('database_per_tenant')->end()
            ->booleanNode('strict_mode')->defaultTrue()->end()
            ->scalarNode('landlord_connection')->defaultValue('default')->end()
            ->scalarNode('tenant_entity_class')->defaultValue('Tenancy\\Bundle\\Entity\\Tenant')->end()
            ->scalarNode('cache_prefix_separator')->defaultValue(':')->end()
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

        $builder->registerForAutoconfiguration(TenantBootstrapperInterface::class)
            ->addTag('tenancy.bootstrapper');

        $builder->registerForAutoconfiguration(TenantResolverInterface::class)
            ->addTag('tenancy.resolver');

        /** @var array<string, mixed> $hostConfig */
        $hostConfig = $config['host'];

        /** @var array<string, mixed> $databaseConfig */
        $databaseConfig = $config['database'] ?? [];

        $container->parameters()
            ->set('tenancy.driver', $config['driver'])
            ->set('tenancy.strict_mode', $config['strict_mode'])
            ->set('tenancy.landlord_connection', $config['landlord_connection'])
            ->set('tenancy.tenant_entity_class', $config['tenant_entity_class'])
            ->set('tenancy.host.app_domain', $hostConfig['app_domain'])
            ->set('tenancy.resolvers', $config['resolvers'])
            ->set('tenancy.cache_prefix_separator', $config['cache_prefix_separator']);

        // Always-on: EntityManagerResetListener (works in both driver modes after resetManager() fix)
        $services = $container->services();
        $services->set(EntityManagerResetListener::class)
            ->autoconfigure(true)
            ->args([service('doctrine')->nullOnInvalid()]);

        if ($databaseConfig['enabled'] ?? false) {
            $container->parameters()->set('tenancy.database.enabled', true);

            $services = $container->services();

            $services->set('tenancy.database_switch_bootstrapper', DatabaseSwitchBootstrapper::class)
                ->args([service('doctrine.dbal.tenant_connection')])
                ->tag('tenancy.bootstrapper');

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
        if (interface_exists(MessageBusInterface::class)) {
            // Priority 1 ensures this runs BEFORE MessengerPass (priority 0) which consumes the parameter
            $container->addCompilerPass(new MessengerMiddlewarePass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, 1);
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
    }
}
