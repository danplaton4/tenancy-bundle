<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Integration\SharedEntity\Support;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Compiler pass that replaces the real tenancy.provider (DoctrineTenantProvider) with
 * StubMultiTenantProvider, which returns two deterministic test tenants without any
 * Doctrine EM or cache dependency.
 *
 * Mirrors ReplaceTenancyProviderPass in tests/Integration/Support/.
 */
final class ReplaceWithStubMultiTenantProviderPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition('tenancy.provider')) {
            return;
        }

        $container->getDefinition('tenancy.provider')
            ->setClass(StubMultiTenantProvider::class)
            ->setArguments([])
            ->clearTags();
    }
}
