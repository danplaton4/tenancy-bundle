<?php

declare(strict_types=1);

namespace Tenancy\Bundle;

use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use Tenancy\Bundle\Bootstrapper\TenantBootstrapperInterface;
use Tenancy\Bundle\DependencyInjection\Compiler\BootstrapperChainPass;
use Tenancy\Bundle\DependencyInjection\Compiler\ResolverChainPass;
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
        ;
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
    }

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->addCompilerPass(new BootstrapperChainPass());
        $container->addCompilerPass(new ResolverChainPass());
    }

    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $builder->prependExtensionConfig('doctrine', [
            'orm' => [
                'mappings' => [
                    'TenancyBundle' => [
                        'is_bundle' => false,
                        'type' => 'attribute',
                        'dir' => __DIR__.'/Entity',
                        'prefix' => 'Tenancy\\Bundle\\Entity',
                        'alias' => 'TenancyBundle',
                    ],
                ],
            ],
        ]);
    }
}
