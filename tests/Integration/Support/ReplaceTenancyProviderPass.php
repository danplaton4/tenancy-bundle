<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Integration\Support;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Compiler pass that replaces the real tenancy.provider (DoctrineTenantProvider, which requires
 * Doctrine EM and Cache) with a NullTenantProvider so the container compiles in the minimal
 * test kernel environment (no Doctrine bundle configured).
 */
final class ReplaceTenancyProviderPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition('tenancy.provider')) {
            return;
        }

        $container->getDefinition('tenancy.provider')
            ->setClass(NullTenantProvider::class)
            ->setArguments([])
            ->clearTags();
    }
}
