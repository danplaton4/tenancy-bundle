<?php

declare(strict_types=1);

namespace App\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Attaches the `tenancy.scoped` tag to the FlysystemBundle `users.storage`
 * definition so the bundle scopes it per-tenant (prefix mode).
 *
 * Why a compiler pass and not services.yaml: flysystem-bundle builds its
 * storage definitions (class League\Flysystem\Filesystem) during extension
 * load. The real `users.storage` therefore only exists at compile time.
 * Declaring `users.storage` in services.yaml creates a separate, classless
 * definition that shadows flysystem's — and when the bundle's
 * FilesystemContractPass decorates it, the renamed `.inner` service has no
 * class and the container fails to compile ("has no class").
 *
 * Registered at TYPE_BEFORE_OPTIMIZATION priority 10 (see Kernel::build()) so
 * it runs before the bundle's FilesystemContractPass (priority 0), which reads
 * this tag to inject the decorator. Mirrors the bundle's own integration-test
 * ScopedStorageTaggingPass.
 */
final class ScopedStorageTaggingPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition('users.storage')) {
            return;
        }

        $container->getDefinition('users.storage')->addTag('tenancy.scoped', [
            'strategy' => 'prefix',
            'prefix_template' => 'tenant_{slug}/',
        ]);
    }
}
