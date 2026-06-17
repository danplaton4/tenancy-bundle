<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Unit\PHPStan\Rule\Fixtures;

use Doctrine\ORM\Mapping as ORM;
use Tenancy\Bundle\Attribute\TenantAware;

#[TenantAware]
#[ORM\Entity]
class TenantIdPositionalNonStringViolating
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private int $id;

    // WR-04: exercises the $args[1] (type) positional fallback in checkViaReflection().
    // ORM\Column positional signature: name=0, type=1, length=2, precision=3, scale=4, unique=5, nullable=6.
    // Positional type='integer' at index 1 (no named args) → checkViaReflection reads $args[1] → fires non-string error.
    #[ORM\Column('tenant_id', 'integer')]
    private int $tenantId;
}
