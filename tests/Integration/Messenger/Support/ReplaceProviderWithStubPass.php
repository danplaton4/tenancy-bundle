<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Integration\Messenger\Support;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Compiler pass that adapts the container for the no-Doctrine Messenger test kernel:
 * 1. Replaces tenancy.provider (requires Doctrine EM + Cache) with StubTenantProvider.
 * 2. Replaces tenancy.doctrine_bootstrapper (requires Doctrine EM) with NoOpBootstrapper.
 * 3. Removes EntityManagerResetListener (requires 'doctrine' ManagerRegistry service).
 *
 * The StubTenantProvider is populated with test tenants in setUpBeforeClass via container->get().
 */
final class ReplaceProviderWithStubPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if ($container->hasDefinition('tenancy.provider')) {
            $container->getDefinition('tenancy.provider')
                ->setClass(StubTenantProvider::class)
                ->setArguments([])
                ->clearTags();
        }

        // Replace doctrine bootstrapper with no-op — Doctrine EM not available in Messenger test kernel
        if ($container->hasDefinition('tenancy.doctrine_bootstrapper')) {
            $container->getDefinition('tenancy.doctrine_bootstrapper')
                ->setClass(NoOpBootstrapper::class)
                ->setArguments([]);
        }

        // Remove EntityManagerResetListener — requires 'doctrine' ManagerRegistry, not available here
        if ($container->hasDefinition(\Tenancy\Bundle\EventListener\EntityManagerResetListener::class)) {
            $container->removeDefinition(\Tenancy\Bundle\EventListener\EntityManagerResetListener::class);
        }
    }
}
