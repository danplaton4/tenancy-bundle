<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Event;

use Tenancy\Bundle\TenantInterface;

/**
 * Dispatched when a tenant transitions from "in maintenance" back to "up".
 *
 * Only dispatched on a real bool transition — idempotent disable calls on a
 * tenant already "up" do NOT dispatch this event (MAINT-08 / D-08).
 *
 * @see TenantMaintenanceEnabled counterpart event
 */
final class TenantMaintenanceDisabled
{
    public function __construct(
        public readonly TenantInterface $tenant,
    ) {
    }
}
