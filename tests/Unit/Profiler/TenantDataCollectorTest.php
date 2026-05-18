<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Unit\Profiler;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Tenancy\Bundle\Context\TenantContext;
use Tenancy\Bundle\Exception\TenantNotFoundException;
use Tenancy\Bundle\Profiler\TenantDataCollector;
use Tenancy\Bundle\Profiler\TenantProfilerStash;
use Tenancy\Bundle\TenantInterface;

final class TenantDataCollectorTest extends TestCase
{
    private TenantProfilerStash&MockObject $stash;
    private TenantContext $tenantContext;

    protected function setUp(): void
    {
        $this->stash = $this->createMock(TenantProfilerStash::class);
        $this->tenantContext = new TenantContext();
    }

    private function makeCollector(string $driver = 'database_per_tenant', string $landlord = 'default'): TenantDataCollector
    {
        return new TenantDataCollector($this->stash, $this->tenantContext, $driver, $landlord);
    }

    public function testGetNameReturnsTenancy(): void
    {
        self::assertSame('tenancy', $this->makeCollector()->getName());
    }

    public function testGetTemplateReturnsBundleNamespacedPath(): void
    {
        self::assertSame('@Tenancy/Collector/tenant.html.twig', TenantDataCollector::getTemplate());
    }

    public function testCollectProducesResolvedStateShape(): void
    {
        $tenant = $this->createMock(TenantInterface::class);
        $tenant->method('getSlug')->willReturn('acme');
        $tenant->method('getName')->willReturn('Acme Corp');
        $this->tenantContext->setTenant($tenant);

        $this->stash->method('getResolvedBy')->willReturn('X\\Y\\Resolver');
        $this->stash->method('getBootstrapperFqcns')->willReturn(['A\\B', 'C\\D']);
        $this->stash->method('getCapturedException')->willReturn(null);

        $collector = $this->makeCollector('database_per_tenant', 'default');
        $collector->collect(Request::create('/'), new Response());

        self::assertSame(
            [
                'state' => 'resolved',
                'slug' => 'acme',
                'tenant_label' => 'Acme Corp',
                'driver' => 'database_per_tenant',
                'connection_name' => 'tenant',
                'resolved_by' => 'X\\Y\\Resolver',
                'bootstrappers' => ['A\\B', 'C\\D'],
                'error' => null,
            ],
            $collector->getData()
        );
    }

    public function testCollectProducesNullStateWhenNoTenant(): void
    {
        $this->stash->method('getResolvedBy')->willReturn(null);
        $this->stash->method('getBootstrapperFqcns')->willReturn([]);
        $this->stash->method('getCapturedException')->willReturn(null);

        $collector = $this->makeCollector('shared_db', 'default');
        $collector->collect(Request::create('/'), new Response());
        $data = $collector->getData();

        self::assertSame('null', $data['state']);
        self::assertNull($data['slug']);
        self::assertNull($data['tenant_label']);
        self::assertNull($data['resolved_by']);
        self::assertSame([], $data['bootstrappers']);
        self::assertNull($data['error']);
    }

    public function testCollectProducesErrorStateWhenStashCapturedException(): void
    {
        $this->stash->method('getResolvedBy')->willReturn(null);
        $this->stash->method('getBootstrapperFqcns')->willReturn([]);
        $this->stash->method('getCapturedException')->willReturn([
            'class' => TenantNotFoundException::class,
            'message' => 'tenant x missing',
        ]);

        $collector = $this->makeCollector('shared_db', 'default');
        $collector->collect(Request::create('/'), new Response());
        $data = $collector->getData();

        self::assertSame('error', $data['state']);
        self::assertIsArray($data['error']);
        self::assertSame(TenantNotFoundException::class, $data['error']['class']);
        self::assertSame('tenant x missing', $data['error']['message']);
    }

    public function testCollectForSharedDbDriverUsesLandlordConnectionName(): void
    {
        $this->stash->method('getResolvedBy')->willReturn(null);
        $this->stash->method('getBootstrapperFqcns')->willReturn([]);
        $this->stash->method('getCapturedException')->willReturn(null);

        $collector = $this->makeCollector('shared_db', 'landlord_main');
        $collector->collect(Request::create('/'), new Response());

        self::assertSame('landlord_main', $collector->getData()['connection_name']);
    }

    public function testCollectForDatabasePerTenantDriverUsesTenantLiteral(): void
    {
        $this->stash->method('getResolvedBy')->willReturn(null);
        $this->stash->method('getBootstrapperFqcns')->willReturn([]);
        $this->stash->method('getCapturedException')->willReturn(null);

        $collector = $this->makeCollector('database_per_tenant', 'default');
        $collector->collect(Request::create('/'), new Response());

        self::assertSame('tenant', $collector->getData()['connection_name']);
    }

    public function testConnectionNameDsnLikeColonStringThrows(): void
    {
        $this->stash->method('getResolvedBy')->willReturn(null);
        $this->stash->method('getBootstrapperFqcns')->willReturn([]);
        $this->stash->method('getCapturedException')->willReturn(null);

        $collector = $this->makeCollector('shared_db', 'mysql://user:pass@host/db');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/looks like a DSN/');
        $collector->collect(Request::create('/'), new Response());
    }

    public function testConnectionNameDsnLikeAtStringThrows(): void
    {
        $this->stash->method('getResolvedBy')->willReturn(null);
        $this->stash->method('getBootstrapperFqcns')->willReturn([]);
        $this->stash->method('getCapturedException')->willReturn(null);

        $collector = $this->makeCollector('shared_db', 'user@host');
        $this->expectException(\RuntimeException::class);
        $collector->collect(Request::create('/'), new Response());
    }

    public function testDataHasExactlyEightKeys(): void
    {
        $this->stash->method('getResolvedBy')->willReturn(null);
        $this->stash->method('getBootstrapperFqcns')->willReturn([]);
        $this->stash->method('getCapturedException')->willReturn(null);

        $collector = $this->makeCollector('shared_db', 'default');
        $collector->collect(Request::create('/'), new Response());

        self::assertSame(
            ['state', 'slug', 'tenant_label', 'driver', 'connection_name', 'resolved_by', 'bootstrappers', 'error'],
            array_keys($collector->getData())
        );
    }

    public function testDataContainsOnlyScalarsAndStringArrays(): void
    {
        $tenant = $this->createMock(TenantInterface::class);
        $tenant->method('getSlug')->willReturn('acme');
        $tenant->method('getName')->willReturn('Acme');
        $this->tenantContext->setTenant($tenant);
        $this->stash->method('getResolvedBy')->willReturn('R');
        $this->stash->method('getBootstrapperFqcns')->willReturn(['A', 'B']);
        $this->stash->method('getCapturedException')->willReturn(null);

        $collector = $this->makeCollector();
        $collector->collect(Request::create('/'), new Response());
        $data = $collector->getData();

        foreach ($data as $key => $value) {
            if (null === $value) {
                continue;
            }
            if (is_scalar($value)) {
                self::assertIsScalar($value, "Key {$key} should be scalar or null");

                continue;
            }
            self::assertIsArray($value, "Key {$key} should be array if not null/scalar");
            foreach ($value as $sub) {
                self::assertIsString($sub, "Sub-value under {$key} must be string, got: ".gettype($sub));
            }
        }
    }

    public function testBootstrappersAreCoercedToStrings(): void
    {
        $this->stash->method('getResolvedBy')->willReturn(null);
        // Simulate stash returning non-string-keyed array with stringable values; coercion must produce list<string>.
        $this->stash->method('getBootstrapperFqcns')->willReturn([5 => 'X\\B1', 9 => 'Y\\B2']);
        $this->stash->method('getCapturedException')->willReturn(null);

        $collector = $this->makeCollector('shared_db', 'default');
        $collector->collect(Request::create('/'), new Response());
        $bootstrappers = $collector->getData()['bootstrappers'];

        self::assertSame(['X\\B1', 'Y\\B2'], $bootstrappers, 'bootstrappers must be array_values + array_map strval');
        self::assertSame([0, 1], array_keys($bootstrappers));
        foreach ($bootstrappers as $b) {
            self::assertIsString($b);
        }
    }
}
