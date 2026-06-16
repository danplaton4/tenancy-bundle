<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Unit\PHPStan\Rule\Fixtures;

use Tenancy\Bundle\Attribute\TenantAware;

#[TenantAware]
class OnlyTenantAwareClean
{
}
