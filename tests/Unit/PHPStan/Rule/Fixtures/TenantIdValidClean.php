<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Unit\PHPStan\Rule\Fixtures;

use Doctrine\ORM\Mapping as ORM;
use Tenancy\Bundle\Attribute\TenantAware;

#[TenantAware]
#[ORM\Entity]
class TenantIdValidClean
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private int $id;

    #[ORM\Column(name: 'tenant_id', type: 'string')]
    private string $tenantId;
}
