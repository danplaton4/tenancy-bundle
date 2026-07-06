<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Health;

use Tenancy\Bundle\TenantInterface;

/**
 * Sibling probe interface for bootstrappers that support health checks.
 *
 * This interface is intentionally NOT an extension of
 * {@see \Tenancy\Bundle\Bootstrapper\TenantBootstrapperInterface}. Implementing it
 * is purely opt-in — no existing bootstrapper is forced to adopt it (zero BC break,
 * HEALTH-03). A bootstrapper that implements this interface signals that it can
 * perform a read-only connectivity check without calling boot().
 *
 * The check() method MUST be side-effect-free with respect to global application
 * state. The caller ({@see TenantHealthChecker}) ensures TenantContext is set and
 * cleared around every invocation via a try/finally envelope.
 *
 * @see TenantHealthChecker  The service that drives the probe lifecycle
 * @see BootstrapperHealthResult  The result value object returned by this method
 */
interface HealthCheckBootstrapperInterface
{
    /**
     * Performs a read-only health probe for the given tenant.
     *
     * Implementations MUST NOT call boot() or clear() — those are full bootstrapper
     * lifecycle methods with side effects. The probe should verify connectivity only
     * (e.g., `SELECT 1`) and return a {@see BootstrapperHealthResult}.
     *
     * @param TenantInterface $tenant The tenant whose context is currently active
     */
    public function check(TenantInterface $tenant): BootstrapperHealthResult;
}
