<?php

declare(strict_types=1);

namespace Tenancy\Bundle;

use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use Tenancy\Bundle\Bootstrapper\DatabaseSwitchBootstrapper;
use Tenancy\Bundle\Bootstrapper\TenantBootstrapperInterface;
use Tenancy\Bundle\DependencyInjection\Compiler\BootstrapperChainPass;
use Tenancy\Bundle\DependencyInjection\Compiler\ResolverChainPass;
use Tenancy\Bundle\Driver\SharedDriver;
use Tenancy\Bundle\EventListener\EntityManagerResetListener;
use Tenancy\Bundle\Filter\TenantAwareFilter;
use Tenancy\Bundle\Resolver\TenantResolverInterface;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

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

    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $container->import('../config/services.php');

        $builder->registerForAutoconfiguration(TenantBootstrapperInterface::class)
            ->addTag('tenancy.bootstrapper');

        $builder->registerForAutoconfiguration(TenantResolverInterface::class)
            ->addTag('tenancy.resolver');

        $container->parameters()
            ->set('tenancy.driver', $config['driver'])
            ->set('tenancy.strict_mode', $config['strict_mode'])
            ->set('tenancy.landlord_connection', $config['landlord_connection'])
            ->set('tenancy.tenant_entity_class', $config['tenant_entity_class'])
            ->set('tenancy.host.app_domain', $config['host']['app_domain'])
            ->set('tenancy.resolvers', $config['resolvers']);

        // Always-on: EntityManagerResetListener (works in both driver modes after resetManager() fix)
        $services = $container->services();
        $services->set(EntityManagerResetListener::class)
            ->autoconfigure(true)
            ->args([service('doctrine')]);

        if ($config['database']['enabled'] ?? false) {
            $container->parameters()->set('tenancy.database.enabled', true);

            $services = $container->services();

            $services->set('tenancy.database_switch_bootstrapper', DatabaseSwitchBootstrapper::class)
                ->args([service('doctrine.dbal.tenant_connection')])
                ->tag('tenancy.bootstrapper');

            // Rewire DoctrineTenantProvider to landlord EM (services.php is already imported above)
            $builder->getDefinition('tenancy.provider')
                ->setArgument(0, new Reference('doctrine.orm.landlord_entity_manager'));
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
    }

    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $mapping = [
            'TenancyBundle' => [
                'is_bundle' => false,
                'type' => 'attribute',
                'dir' => __DIR__ . '/Entity',
                'prefix' => 'Tenancy\\Bundle\\Entity',
                'alias' => 'TenancyBundle',
            ],
        ];

        $databaseEnabled = false;
        $isSharedDb      = false;
        foreach ($builder->getExtensionConfig('tenancy') as $config) {
            if (isset($config['database']['enabled'])) {
                $databaseEnabled = $config['database']['enabled'];
            }
            if (isset($config['driver']) && $config['driver'] === 'shared_db') {
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
                            'class'   => TenantAwareFilter::class,
                            'enabled' => true,
                        ],
                    ],
                ],
            ]);
        }

        // Messenger middleware auto-enrollment — zero config
        if (class_exists(\Symfony\Component\Messenger\MessageBusInterface::class)) {
            $messengerConfigs = $builder->getExtensionConfig('framework');
            $buses            = [];
            foreach ($messengerConfigs as $config) {
                foreach ($config['messenger']['buses'] ?? [] as $busName => $busConfig) {
                    $buses[$busName] = true;
                }
            }

            // Cover the default bus when no explicit buses section exists
            if (empty($buses)) {
                $buses['messenger.bus.default'] = true;
            }

            $middlewareToInject = [
                ['id' => 'tenancy.messenger.sending_middleware'],
                ['id' => 'tenancy.messenger.worker_middleware'],
            ];

            foreach (array_keys($buses) as $busName) {
                $builder->prependExtensionConfig('framework', [
                    'messenger' => [
                        'buses' => [
                            $busName => [
                                'middleware' => $middlewareToInject,
                            ],
                        ],
                    ],
                ]);
            }
        }
    }
}
