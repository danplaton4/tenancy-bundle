<?php

declare(strict_types=1);

namespace Tenancy\Bundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\KernelEvents;
use Tenancy\Bundle\EventListener\TenantContextOrchestrator;

/**
 * Compile-time guard for the tenant maintenance-mode listener priority invariant.
 *
 * Ensures that 'tenancy.maintenance.listener' is registered at a kernel.request
 * priority strictly lower than {@see TenantContextOrchestrator::PRIORITY} (20).
 * The maintenance listener must run AFTER the tenant is resolved, so priority < 20
 * is required. Priority 16 is recommended (Success Criterion 3 / MAINT-03).
 *
 * Two safety checks when maintenance.enabled is true:
 *  1. The listener service 'tenancy.maintenance.listener' must be registered.
 *  2. Its kernel.event_listener tag for kernel.request must have priority < 20.
 *
 * Returns early (no-op) when tenancy.maintenance.enabled is false or absent.
 *
 * Registered unconditionally in TenancyBundle::build() — maintenance has no
 * optional library dependency (unlike the Mailer or Filesystem passes).
 *
 * @see TenantContextOrchestrator::PRIORITY the ceiling this pass enforces (value: 20)
 */
final class MaintenanceModeContractPass implements CompilerPassInterface
{
    /**
     * Container parameter holding the user's tenancy.maintenance.enabled config value.
     */
    private const ENABLED_PARAM = 'tenancy.maintenance.enabled';

    /**
     * Service ID for the maintenance mode listener.
     * MUST match the ID registered in TenancyBundle::loadExtension() — drift breaks the guard.
     */
    private const LISTENER_SERVICE_ID = 'tenancy.maintenance.listener';

    public function process(ContainerBuilder $container): void
    {
        // Early-return when maintenance feature is disabled (the default).
        // Mirrors FilesystemContractPass:70.
        if (!$container->hasParameter(self::ENABLED_PARAM) || !$container->getParameter(self::ENABLED_PARAM)) {
            return;
        }

        // Guard: listener service must be registered when maintenance is enabled.
        if (!$container->hasDefinition(self::LISTENER_SERVICE_ID)) {
            throw new \LogicException(sprintf('tenancy: maintenance.enabled is true but the maintenance listener service "%s" is not registered. Ensure the service is wired in TenancyBundle::loadExtension() when maintenance.enabled is true.', self::LISTENER_SERVICE_ID));
        }

        // Guard: listener must have kernel.request priority < TenantContextOrchestrator::PRIORITY.
        // With autoconfigure(true), the #[AsEventListener] attribute is converted to a
        // kernel.event_listener tag by ResolveInstanceofConditionalsPass before this pass runs.
        $def = $container->findDefinition(self::LISTENER_SERVICE_ID);
        $tags = $def->getTag('kernel.event_listener');

        $foundRequestTag = false;

        foreach ($tags as $tag) {
            if (($tag['event'] ?? '') !== KernelEvents::REQUEST) {
                continue;
            }

            $foundRequestTag = true;
            $priority = (int) ($tag['priority'] ?? 0);

            if ($priority >= TenantContextOrchestrator::PRIORITY) {
                throw new \LogicException(sprintf('tenancy: TenantMaintenanceModeListener must be registered at a kernel.request priority strictly lower than TenantContextOrchestrator::PRIORITY (%d) so the tenant is already resolved when maintenance is checked. Got priority %d. Set the listener priority to %d or lower (recommended: %d).', TenantContextOrchestrator::PRIORITY, $priority, TenantContextOrchestrator::PRIORITY - 1, 16/* TenantMaintenanceModeListener::PRIORITY — registered in plan 32-02 */));
            }
        }

        if (!$foundRequestTag) {
            throw new \LogicException(sprintf('tenancy: maintenance.enabled is true but service "%s" has no kernel.event_listener tag for kernel.request. Ensure autoconfigure is enabled on the listener service.', self::LISTENER_SERVICE_ID));
        }
    }
}
