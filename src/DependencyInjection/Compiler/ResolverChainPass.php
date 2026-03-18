<?php

declare(strict_types=1);

namespace Tenancy\Bundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\PriorityTaggedServiceTrait;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Tenancy\Bundle\Resolver\ResolverChain;

final class ResolverChainPass implements CompilerPassInterface
{
    use PriorityTaggedServiceTrait;

    public function process(ContainerBuilder $container): void
    {
        if (!$container->has(ResolverChain::class)) {
            return;
        }

        $definition = $container->findDefinition(ResolverChain::class);

        $resolvers = $this->findAndSortTaggedServices('tenancy.resolver', $container);

        foreach ($resolvers as $resolver) {
            $definition->addMethodCall('addResolver', [$resolver]);
        }
    }
}
