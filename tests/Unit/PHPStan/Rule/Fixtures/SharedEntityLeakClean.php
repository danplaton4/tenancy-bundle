<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Unit\PHPStan\Rule\Fixtures;

use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;

/**
 * D-03 conservative: EntityManagerInterface-typed caller querying a #[Shared] entity.
 * Rule must stay silent — PHPStan cannot distinguish landlord EM from tenant EM
 * when both are typed as the interface.
 */
function querySharedViaInterface(EntityManagerInterface $em): void
{
    $em->find(SharedProductViolating::class, 1);
}

/**
 * Non-shared entity — concrete EntityManager, but entity is NOT #[Shared].
 * Rule must stay silent.
 */
function queryNonSharedViaConcrete(EntityManager $em): void
{
    $em->find(TenantIdValidClean::class, 1);
}
