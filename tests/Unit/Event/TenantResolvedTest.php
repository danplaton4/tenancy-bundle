<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Unit\Event;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Symfony\Component\HttpFoundation\Request;
use Tenancy\Bundle\Event\TenantResolved;
use Tenancy\Bundle\TenantInterface;

final class TenantResolvedTest extends TestCase
{
    public function testCarriesTenantRequestAndResolvedBy(): void
    {
        $tenant = $this->createMock(TenantInterface::class);
        $request = Request::create('/');
        $resolvedBy = 'Tenancy\Bundle\Resolver\HostResolver';

        $event = new TenantResolved($tenant, $request, $resolvedBy);

        $this->assertSame($tenant, $event->tenant);
        $this->assertSame($request, $event->request);
        $this->assertSame($resolvedBy, $event->resolvedBy);
    }

    public function testRequestCanBeNull(): void
    {
        $tenant = $this->createMock(TenantInterface::class);

        $event = new TenantResolved($tenant, null, 'Tenancy\Bundle\Resolver\ConsoleResolver');

        $this->assertNull($event->request);
    }

    public function testPropertiesAreReadonly(): void
    {
        $rc = new ReflectionClass(TenantResolved::class);

        foreach (['tenant', 'request', 'resolvedBy'] as $propertyName) {
            $property = $rc->getProperty($propertyName);
            $this->assertTrue(
                $property->isReadOnly(),
                sprintf('Property $%s must be readonly', $propertyName),
            );
        }
    }
}
