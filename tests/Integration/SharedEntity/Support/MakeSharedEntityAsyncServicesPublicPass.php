<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Integration\SharedEntity\Support;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Compiler pass that makes shared-entity async services public so integration tests
 * can retrieve them from the compiled container.
 *
 * Extends the sync-mode pass with the async handler and message bus IDs needed by
 * SharedEntityAsyncCanaryTest.
 *
 * Guards each service with hasDefinition()/hasAlias() so a missing service does not
 * break compilation (tolerates intermediate test-kernel states during Wave 3 landing).
 */
final class MakeSharedEntityAsyncServicesPublicPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $ids = [
            'tenancy.shared_entity_changed_handler',
            'messenger.bus.default',
            'tenancy.shared_entity_sync_subscriber',
            'tenancy.shared_entity_copier',
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
