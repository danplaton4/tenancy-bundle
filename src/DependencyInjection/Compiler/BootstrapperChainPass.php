<?php

declare(strict_types=1);

namespace Tenancy\Bundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\PriorityTaggedServiceTrait;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Tenancy\Bundle\Bootstrapper\BootstrapperChain;

final class BootstrapperChainPass implements CompilerPassInterface
{
    use PriorityTaggedServiceTrait;

    public function process(ContainerBuilder $container): void
    {
        if (!$container->has(BootstrapperChain::class)) {
            return;
        }

        $definition = $container->findDefinition(BootstrapperChain::class);

        $bootstrappers = $this->findAndSortTaggedServices('tenancy.bootstrapper', $container);

        foreach ($bootstrappers as $bootstrapper) {
            $definition->addMethodCall('addBootstrapper', [$bootstrapper]);
        }
    }
}
