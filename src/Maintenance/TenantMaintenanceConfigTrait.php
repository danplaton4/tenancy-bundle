<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Maintenance;

use Doctrine\ORM\Mapping as ORM;

/**
 * Default implementation of the maintenance-state accessor on a tenant entity.
 *
 * Users with a custom Tenant entity can `use TenantMaintenanceConfigTrait;` to
 * inherit the boolean column and its getter/setter pair — equivalent to
 * implementing isInMaintenance()/setInMaintenance() by hand. The shipped
 * {@see \Tenancy\Bundle\Entity\AbstractTenant} inlines the same column for the
 * out-of-the-box experience.
 *
 * The #[ORM\Column] attribute is only honored when doctrine/orm is installed;
 * with Doctrine absent the trait still works as plain PHP property storage.
 *
 * Do NOT use with {@see \Tenancy\Bundle\Entity\AbstractTenant}, which
 * already inlines `$inMaintenance`. Using both in the same entity will cause
 * Doctrine to see a duplicate column mapping and fail.
 *
 * See UPGRADE.md §0.4→0.5 for the migration path for custom Tenant entities.
 *
 * @see \Tenancy\Bundle\Entity\AbstractTenant AbstractTenant inlines this column (Phase 32 / MAINT-05)
 */
trait TenantMaintenanceConfigTrait
{
    #[ORM\Column(type: 'boolean')]
    private bool $inMaintenance = false;

    public function isInMaintenance(): bool
    {
        return $this->inMaintenance;
    }

    public function setInMaintenance(bool $inMaintenance): static
    {
        $this->inMaintenance = $inMaintenance;

        return $this;
    }
}
