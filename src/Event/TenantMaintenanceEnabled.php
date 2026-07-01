<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Event;

use Tenancy\Bundle\TenantInterface;

/**
 * Dispatched when a tenant transitions from "up" to "in maintenance".
 *
 * Only dispatched on a real bool transition — idempotent enable calls on a
 * tenant already in maintenance do NOT dispatch this event (MAINT-08 / D-08).
 *
 * @see TenantMaintenanceDisabled counterpart event
 */
final class TenantMaintenanceEnabled
{
    public function __construct(
        public readonly TenantInterface $tenant,
    ) {
    }
}
