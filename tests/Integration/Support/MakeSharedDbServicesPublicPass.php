<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Integration\Support;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Compiler pass that makes shared-DB tenancy services public so
 * integration tests can retrieve them from the compiled container.
 */
final class MakeSharedDbServicesPublicPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $ids = [
            'doctrine.orm.default_entity_manager',
            'tenancy.context',
            'tenancy.bootstrapper_chain',
            'tenancy.shared_driver',
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
