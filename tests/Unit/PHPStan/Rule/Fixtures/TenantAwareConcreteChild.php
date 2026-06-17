<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Unit\PHPStan\Rule\Fixtures;

use Doctrine\ORM\Mapping as ORM;

/**
 * WR-02 fixture: concrete #[ORM\Entity] subclass of TenantAwareParent (#[ORM\MappedSuperclass]).
 *
 * Inherits #[TenantAware] from TenantAwareParent but declares NO tenant_id column.
 * Proves that the MappedSuperclass exemption (parent silent) does NOT silence concrete children:
 * this class MUST fire tenancy.tenantIdDrift while TenantAwareParent is SILENT.
 */
#[ORM\Entity]
class TenantAwareConcreteChild extends TenantAwareParent
{
    #[ORM\Column(type: 'string')]
    private string $title;
}
