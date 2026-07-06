<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Integration\Support;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Tenancy\Bundle\Health\TenantHealthChecker;

/**
 * Compiler pass that makes health services public so integration tests can retrieve
 * them from the compiled container.
 */
final class MakeHealthServicesPublicPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $ids = [
            'tenancy.health.checker',
            TenantHealthChecker::class,
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
