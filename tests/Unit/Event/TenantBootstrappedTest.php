<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Unit\Event;

use PHPUnit\Framework\TestCase;
use Tenancy\Bundle\Event\TenantBootstrapped;
use Tenancy\Bundle\TenantInterface;

final class TenantBootstrappedTest extends TestCase
{
    public function testCarriesTenantAndBootstrapperFqcns(): void
    {
        $tenant = $this->createMock(TenantInterface::class);
        $bootstrappers = ['App\\Boot1', 'App\\Boot2'];

        $event = new TenantBootstrapped($tenant, $bootstrappers);

        $this->assertSame($tenant, $event->tenant);
        $this->assertSame($bootstrappers, $event->bootstrappers);
    }

    public function testEmptyBootstrappersArray(): void
    {
        $tenant = $this->createMock(TenantInterface::class);

        $event = new TenantBootstrapped($tenant, []);

        $this->assertSame([], $event->bootstrappers);
    }
}
