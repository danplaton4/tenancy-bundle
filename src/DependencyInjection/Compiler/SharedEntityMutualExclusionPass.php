<?php

declare(strict_types=1);

namespace Tenancy\Bundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Compile-time mutual-exclusion guard for #[Shared] and #[TenantAware] attributes (D-04 / DEC-SHARE-03).
 *
 * Walks all services tagged 'tenancy.shared_entity' and throws \LogicException if any
 * service's class carries BOTH #[Shared] and #[TenantAware] simultaneously.
 *
 * A shared entity is a landlord-side master record synced to all tenant EMs; a TenantAware
 * entity is tenant-scoped via the SQL filter. These roles are mutually exclusive — combining
 * them on a single class would create an irreconcilable data-ownership conflict and is a
 * data-leak bug class.
 *
 * Discovery mechanism: users must register shared entity class definitions with the
 * 'tenancy.shared_entity' container tag (mirrors the 'tenancy.scoped' tag in FilesystemContractPass).
 * Only tagged classes are inspected at compile time. Phase 28 (DX-03) adds a PHPStan rule
 * for editor-time detection on top of this boot-time guard.
 *
 * Returns early (no-op) when Doctrine ORM is absent — Doctrine is an optional dependency.
 *
 * @see .planning/phases/25-shared-entities-sync-mode/25-CONTEXT.md §D-04 / DEC-SHARE-03
 * @see src/DependencyInjection/Compiler/FilesystemContractPass.php (structural analog)
 */
final class SharedEntityMutualExclusionPass implements CompilerPassInterface
{
    /**
     * Tag applied by users to register a shared entity class for compile-time inspection.
     * Users MUST tag their shared entity service definitions with this tag for the guard to
     * inspect them; a class never registered as a tagged service is not checked at compile
     * time (Phase 28 PHPStan rule catches it at edit time).
     */
    private const TAG = 'tenancy.shared_entity';

    public function process(ContainerBuilder $container): void
    {
        // Early-return when Doctrine ORM is not installed (optional dependency).
        if (!interface_exists(\Doctrine\ORM\EntityManagerInterface::class)) {
            return;
        }

        foreach ($container->findTaggedServiceIds(self::TAG) as $id => $_tags) {
            $definition = $container->getDefinition($id);
            $class = $definition->getClass() ?? $id;

            if (!class_exists($class)) {
                continue;
            }

            $rc = new \ReflectionClass($class);
            $hasShared = [] !== $rc->getAttributes(\Tenancy\Bundle\Attribute\Shared::class);
            $hasTenantAware = [] !== $rc->getAttributes(\Tenancy\Bundle\Attribute\TenantAware::class);

            if ($hasShared && $hasTenantAware) {
                throw new \LogicException(sprintf('tenancy: entity "%s" cannot carry both #[Shared] and #[TenantAware]. A shared entity is a landlord-side master; a TenantAware entity is tenant-scoped. Pick one.', $class));
            }
        }
    }
}
