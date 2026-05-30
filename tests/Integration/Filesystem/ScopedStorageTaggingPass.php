<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Integration\Filesystem;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Test-only compiler pass that attaches the `tenancy.scoped` DI tag (with
 * Phase 24 attributes — strategy: prefix, prefix_template: "tenant_{slug}/")
 * to the FlysystemTestKernel's `users.storage` definition.
 *
 * The flysystem-bundle's extension builds its storage definitions during the
 * extension-load phase; registerContainerConfiguration closures all execute
 * BEFORE extension processing, so a closure-based addTag() finds no
 * definition. The canonical fix is a compiler pass that runs AFTER all
 * extensions have built their definitions — this class.
 *
 * `public.storage` intentionally stays UNTAGGED — that's DEC-FILE-MULTI's
 * "escape hatch" for landlord-only / shared filesystems and the bypass-
 * scoping integration scenario relies on it.
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

        if (!$container->hasDefinition('users.storage')) {
            return;
        }

        $container->getDefinition('users.storage')->addTag('tenancy.scoped', [
            'strategy' => 'prefix',
            'prefix_template' => 'tenant_{slug}/',
        ]);
    }
}
