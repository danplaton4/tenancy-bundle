<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Unit\Bootstrapper;

use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Tenancy\Bundle\Bootstrapper\DoctrineBootstrapper;
use Tenancy\Bundle\Bootstrapper\TenantBootstrapperInterface;
use Tenancy\Bundle\TenantInterface;

final class DoctrineBootstrapperTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private DoctrineBootstrapper $bootstrapper;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->bootstrapper = new DoctrineBootstrapper($this->em);
    }

    public function testBootClearsEntityManager(): void
    {
        $tenant = $this->createMock(TenantInterface::class);

        $this->em->expects($this->once())
            ->method('clear');

        $this->bootstrapper->boot($tenant);
    }

    public function testClearClearsEntityManager(): void
    {
        $this->em->expects($this->once())
            ->method('clear');

        $this->bootstrapper->clear();
    }

    public function testImplementsTenantBootstrapperInterface(): void
    {
        $this->assertInstanceOf(TenantBootstrapperInterface::class, $this->bootstrapper);
    }
}
