<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Integration\Filesystem;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Compiler pass that replaces `tenancy.provider` with StubFilesystemTenantProvider
 * so Filesystem integration tests can resolve named stub tenants without
 * requiring a real Doctrine ORM / SQLite table.
 *
 * Also removes EntityManagerResetListener (requires 'doctrine' ManagerRegistry)
 * when the Doctrine bundle is NOT wired with actual entity mappings that would
 * conflict — mirrors the pattern from ReplaceProviderWithStubPass used by the
 * Mailer integration tests.
 *
 * @see StubFilesystemTenantProvider
 */
final class ReplaceFilesystemProviderPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if ($container->hasDefinition('tenancy.provider')) {
            $container->getDefinition('tenancy.provider')
                ->setClass(StubFilesystemTenantProvider::class)
                ->setArguments([])
                ->clearTags();
        }
    }
}
