<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Integration\SharedEntity\Support;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Compiler pass that makes resync-command services public so integration tests
 * can retrieve them from the compiled container.
 *
 * Guards each service with hasDefinition()/hasAlias() because the new services
 * (tenancy.shared_entity_copier, tenancy.command.shared_resync) do not exist until
 * Plans 26-02/26-03 — the pass must tolerate their absence during Wave 0.
 */
final class MakeSharedEntityResyncServicesPublicPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $ids = [
            'tenancy.shared_entity_copier',
            'tenancy.command.shared_resync',
            'tenancy.shared_entity_sync_subscriber',
            'tenancy.shared_entity_write_protection',
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
