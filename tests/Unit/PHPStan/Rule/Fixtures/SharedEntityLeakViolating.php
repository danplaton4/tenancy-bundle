<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Unit\PHPStan\Rule\Fixtures;

use Doctrine\ORM\EntityManager;

/**
 * Concrete EntityManager typed as EntityManager (not interface) querying a
 * #[Shared] entity via ::class constant — should fire tenancy.sharedEntityLeak.
 */
function querySharedViaConcreteEm(EntityManager $em): void
{
    $em->find(SharedProductViolating::class, 1);
}
