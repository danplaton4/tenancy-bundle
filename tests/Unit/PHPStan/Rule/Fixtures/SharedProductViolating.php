<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Unit\PHPStan\Rule\Fixtures;

use Tenancy\Bundle\Attribute\Shared;

/** A landlord-side master entity used to test Rule 2 (SharedEntityLeakRule). */
#[Shared]
class SharedProductViolating
{
}
