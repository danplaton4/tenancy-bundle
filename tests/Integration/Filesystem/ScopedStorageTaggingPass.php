<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Integration\Filesystem;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Test-only compiler pass that attaches `tenancy.scoped` DI tags to the
 * FilesystemTestKernel's storage services.
 *
 * The flysystem-bundle's extension builds its storage definitions during the
 * extension-load phase; registerContainerConfiguration closures all execute
 * BEFORE extension processing, so a closure-based addTag() finds no
 * definition. The canonical fix is a compiler pass that runs AFTER all
 * extensions have built their definitions — this class.
 *
 * Tags applied:
 *   - `users.storage`          → tenancy.scoped: strategy=prefix,
 *                                prefix_template="tenant_{slug}/"
 *   - `tenant_buckets.storage` → tenancy.scoped: strategy=per_tenant_adapter
 *   - `public.storage`         → intentionally UNTAGGED (DEC-FILE-MULTI escape
 *                                hatch; the bypass-scoping integration scenario
 *                                relies on it staying untagged)
 *
 * No-op when league/flysystem-bundle is not installed.
 */
final class ScopedStorageTaggingPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!interface_exists(\League\Flysystem\FilesystemOperator::class)) {
            return;
        }

        if ($container->hasDefinition('users.storage')) {
            $container->getDefinition('users.storage')->addTag('tenancy.scoped', [
                'strategy' => 'prefix',
                'prefix_template' => 'tenant_{slug}/',
            ]);
        }

        if ($container->hasDefinition('tenant_buckets.storage')) {
            $container->getDefinition('tenant_buckets.storage')->addTag('tenancy.scoped', [
                'strategy' => 'per_tenant_adapter',
            ]);
        }
    }
}
