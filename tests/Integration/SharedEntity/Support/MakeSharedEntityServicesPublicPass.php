<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Integration\SharedEntity\Support;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Compiler pass that makes shared-entity services public so integration tests
 * can retrieve them from the compiled container.
 *
 * Guards each service with hasDefinition()/hasAlias() because Wave 3 wiring
 * (tenancy.shared_entity_sync_subscriber, tenancy.shared_entity_write_protection)
 * does not exist until Plans 25-03/25-04 — the pass must tolerate their absence.
 *
 * Also exposes Phase 26 resync-command services (tenancy.shared_entity_copier,
 * tenancy.command.shared_resync) which land in Plans 26-02/26-03.
 */
final class MakeSharedEntityServicesPublicPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $ids = [
            'tenancy.shared_entity_sync_subscriber',
            'tenancy.shared_entity_write_protection',
            'tenancy.shared_entity_copier',
            'tenancy.command.shared_resync',
            'tenancy.context',
            'doctrine.orm.landlord_entity_manager',
            'doctrine',
            'doctrine.dbal.tenant_connection',
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
