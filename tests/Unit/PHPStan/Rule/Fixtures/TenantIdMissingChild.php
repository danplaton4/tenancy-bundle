<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Unit\PHPStan\Rule\Fixtures;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class TenantIdMissingChild extends TenantAwareParent
{
    #[ORM\Column(type: 'string')]
    private string $name;
}
