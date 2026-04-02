<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Integration\Testing\Support;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Compiler pass that makes all tenancy and Doctrine services public so
 * the InteractsWithTenancy trait can retrieve them from the test container.
 */
final class MakeTenancyTestServicesPublicPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $ids = [
            'tenancy.context',
            \Tenancy\Bundle\Context\TenantContext::class,
            'tenancy.bootstrapper_chain',
            'doctrine.dbal.tenant_connection',
            'doctrine.orm.tenant_entity_manager',
            'doctrine.orm.landlord_entity_manager',
            'tenancy.doctrine_bootstrapper',
            'tenancy.cache_adapter',
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
