<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Unit\PHPStan\Rule\Fixtures;

use Doctrine\ORM\Mapping as ORM;
use Tenancy\Bundle\Attribute\TenantAware;

#[TenantAware]
#[ORM\Entity]
class TenantIdPositionalNullableViolating
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private int $id;

    // WR-04: exercises the $args[6] (nullable) positional fallback in checkViaReflection().
    // ORM\Column positional signature: name=0, type=1, length=2, precision=3, scale=4, unique=5, nullable=6.
    // Positional nullable=true at index 6 (no named args) → checkViaReflection reads $args[6] → fires nullable error.
    // Type is a valid 'string' here — the ONLY violation is nullable=true at position 6, isolating the $args[6] path.
    #[ORM\Column('tenant_id', 'string', 63, null, null, false, true)]
    private ?string $tenantId;
}
