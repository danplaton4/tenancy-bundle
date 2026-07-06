<?php

declare(strict_types=1);

namespace Tenancy\Bundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Tenancy\Bundle\Health\Liip\TenantConnectivityCheck;

/**
 * Compiler pass that auto-registers a liip_monitor.check-tagged service for
 * per-tenant DB connectivity when liip/monitor-bundle is installed (HEALTH-07).
 *
 * Guards via interface_exists(Laminas\Diagnostics\Check\CheckInterface::class) —
 * the Laminas CheckInterface is always present iff liip/monitor-bundle is installed
 * (it is a hard transitive dependency of liip/monitor-bundle). Using the interface
 * rather than the bundle class name is more robust (Assumption A2 from 33-RESEARCH.md).
 *
 * When the guard fails (liip absent), this pass is a complete no-op.
 * The self-contained HTTP endpoints and CLI command continue to work without
 * any liip_monitor.check service present (HEALTH-07 absence direction).
 *
 * When the guard passes (liip present):
 *  - Registers {@see TenantConnectivityCheck} with its three service references
 *  - Tags the service with 'liip_monitor.check' for auto-discovery by the liip runner
 *
 * Registered unconditionally in TenancyBundle::build() — the pass self-guards
 * internally, following the same pattern as MaintenanceModeContractPass.
 *
 * @see TenantConnectivityCheck  The liip check adapter (delegates to TenantHealthCheckerInterface)
 * @see TenancyBundle::build()   Where this pass is registered unconditionally
 */
final class HealthCheckIntegrationPass implements CompilerPassInterface
{
    /**
     * Tag name that the liip monitor runner uses to discover checks.
     */
    private const LIIP_TAG = 'liip_monitor.check';

    /**
     * Service ID for the registered TenantConnectivityCheck.
     * Using a prefixed tenancy ID avoids collisions with user-registered checks.
     */
    private const CHECK_SERVICE_ID = 'tenancy.health.liip.tenant_connectivity_check';

    public function process(ContainerBuilder $container): void
    {
        // Early-return when liip/monitor-bundle is not installed.
        // Laminas\Diagnostics\Check\CheckInterface is always present iff liip is installed
        // (it is a hard transitive dependency). More robust than checking the bundle FQCN.
        if (!interface_exists(\Laminas\Diagnostics\Check\CheckInterface::class)) {
            return;
        }

        // Register TenantConnectivityCheck as a liip_monitor.check-tagged service.
        // The three constructor args mirror the service IDs registered in config/services.php.
        $definition = new Definition(TenantConnectivityCheck::class);
        $definition->setArguments([
            new Reference('tenancy.health.checker'),
            new Reference('tenancy.provider'),
            new Reference('tenancy.health.sanitizer'),
        ]);
        $definition->addTag(self::LIIP_TAG);

        $container->setDefinition(self::CHECK_SERVICE_ID, $definition);
    }
}
