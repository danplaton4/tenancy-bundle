<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Integration\Filesystem;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Test-only compiler pass that exposes Phase 24 Filesystem services + the
 * underlying Flysystem bundle's storage services so integration tests can
 * fetch them via $container->get().
 *
 * Service IDs that are not (yet) registered in this kernel are skipped
 * silently — the pass tolerates missing definitions via hasDefinition /
 * hasAlias guards. This lets the same pass live alongside both Wave-0
 * scaffolding kernels (no Phase-24 production services yet) and the
 * fully-wired Wave-3 container.
 *
 * No-op when league/flysystem-bundle is not installed (FilesystemOperator
 * interface absent → bundle wiring never produced these services).
 *
 * Mirrors MakeMailerServicesPublicPass.
 */
final class MakeFilesystemServicesPublicPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!interface_exists(\League\Flysystem\FilesystemOperator::class)) {
            return;
        }

        $ids = [
            // Bundle core (needed for any Phase 24 assertion that walks the
            // bootstrapper chain or reads the tenant context).
            'tenancy.context',
            'tenancy.provider',
            'tenancy.bootstrapper_chain',
            // Flysystem-bundle storage services (bare names per the bundle's
            // FlysystemExtension — see RESEARCH §Pitfall 1). The FilesystemTestKernel
            // registers exactly these two.
            'users.storage',
            'public.storage',
            // Phase 24 services — populated in Wave 1-3; absent in Wave 0 → the
            // hasDefinition guard makes those entries no-op for now.
            'tenancy.filesystem.lru_cache',
            'tenancy.filesystem.prefixing_decorator',
            'tenancy.filesystem.tenant_aware_decorator',
            'tenancy.filesystem.bootstrapper',
            'tenancy.filesystem.context_cleared_listener',
            // Symfony framework surface used by integration assertions.
            'event_dispatcher',
        ];

        foreach ($ids as $id) {
            if ($container->hasDefinition($id)) {
                $container->getDefinition($id)->setPublic(true);
            } elseif ($container->hasAlias($id)) {
                $container->getAlias($id)->setPublic(true);
            }
        }
    }
}
