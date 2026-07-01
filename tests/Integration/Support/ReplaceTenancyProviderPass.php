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
        if ($container->hasDefinition('tenancy.provider')) {
            $container->getDefinition('tenancy.provider')
                ->setClass(NullTenantProvider::class)
                ->setArguments([])
                ->clearTags();
        }

        // Remove maintenance commands — they reference doctrine.orm.default_entity_manager
        // which is not registered in test kernels without a Doctrine bundle configured.
        // The commands are Doctrine-guarded by interface_exists in services.php, but the ORM
        // interface is present in the dev environment; only the EM service is absent here.
        foreach (['tenancy.command.maintenance.enable', 'tenancy.command.maintenance.disable', 'tenancy.command.maintenance.status'] as $id) {
            if ($container->hasDefinition($id)) {
                $container->removeDefinition($id);
            }
        }
    }
}
