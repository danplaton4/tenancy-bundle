<?php

declare(strict_types=1);

namespace Tenancy\Bundle;

use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use Tenancy\Bundle\Bootstrapper\TenantBootstrapperInterface;
use Tenancy\Bundle\DependencyInjection\Compiler\BootstrapperChainPass;

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
            ->end()
        ;
    }

    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $container->import('../config/services.php');

        $builder->registerForAutoconfiguration(TenantBootstrapperInterface::class)
            ->addTag('tenancy.bootstrapper');

        $container->parameters()
            ->set('tenancy.driver', $config['driver'])
            ->set('tenancy.strict_mode', $config['strict_mode'])
            ->set('tenancy.landlord_connection', $config['landlord_connection'])
            ->set('tenancy.tenant_entity_class', $config['tenant_entity_class']);
    }

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->addCompilerPass(new BootstrapperChainPass());
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
