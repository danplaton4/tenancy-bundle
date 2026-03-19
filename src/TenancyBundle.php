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
use Tenancy\Bundle\EventListener\EntityManagerResetListener;
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

        if ($config['database']['enabled'] ?? false) {
            $container->parameters()->set('tenancy.database.enabled', true);

            $services = $container->services();

            $services->set('tenancy.database_switch_bootstrapper', DatabaseSwitchBootstrapper::class)
                ->args([service('doctrine.dbal.tenant_connection')])
                ->tag('tenancy.bootstrapper');

            $services->set(EntityManagerResetListener::class)
                ->autoconfigure(true)
                ->args([service('doctrine')]);

            // Rewire DoctrineTenantProvider to landlord EM (services.php is already imported above)
            $builder->getDefinition('tenancy.provider')
                ->setArgument(0, new Reference('doctrine.orm.landlord_entity_manager'));
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
        foreach ($builder->getExtensionConfig('tenancy') as $config) {
            if (isset($config['database']['enabled'])) {
                $databaseEnabled = $config['database']['enabled'];
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
    }
}
