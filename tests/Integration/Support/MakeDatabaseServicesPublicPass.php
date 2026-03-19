<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Integration\Support;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Compiler pass that makes Doctrine/tenancy database services public so
 * integration tests can retrieve them from the compiled container.
 */
final class MakeDatabaseServicesPublicPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $ids = [
            'doctrine.dbal.tenant_connection',
            'doctrine.orm.tenant_entity_manager',
            'doctrine.orm.landlord_entity_manager',
            'tenancy.database_switch_bootstrapper',
            'tenancy.context',
            'tenancy.bootstrapper_chain',
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
