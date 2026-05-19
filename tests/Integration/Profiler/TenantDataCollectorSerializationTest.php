<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Integration\Profiler;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Tenancy\Bundle\Context\TenantContext;
use Tenancy\Bundle\Event\TenantBootstrapped;
use Tenancy\Bundle\Event\TenantResolved;
use Tenancy\Bundle\Exception\TenantNotFoundException;
use Tenancy\Bundle\Profiler\TenantDataCollector;
use Tenancy\Bundle\Profiler\TenantProfilerStash;
use Tenancy\Bundle\TenantInterface;

/**
 * Verifies TenantDataCollector survives PHP serialize() / unserialize() round-trip.
 *
 * This is the stored-profile reload contract — the Symfony Profiler writes serialized
 * collectors to var/cache/dev/profiler/{token} files and reads them back when the user
 * opens an old profile URL. With scalar-only $this->data (Plan 02 D-11), this is lossless.
 *
 * TenantProfilerStash is final (cannot be mocked) — these tests use a REAL stash and drive
 * its state via its public event listener methods, mirroring production wiring exactly.
 *
 * Phase 19 — DX-02 acceptance line 4.
 */
final class TenantDataCollectorSerializationTest extends TestCase
{
    private TenantProfilerStash $stash;
    private TenantContext $tenantContext;

    protected function setUp(): void
    {
        $this->stash = new TenantProfilerStash();
        $this->tenantContext = new TenantContext();
    }

    public function testCollectorRoundTripsResolvedState(): void
    {
        $tenant = $this->createMock(TenantInterface::class);
        $tenant->method('getSlug')->willReturn('acme');
        $tenant->method('getName')->willReturn('Acme Corp');
        $this->tenantContext->setTenant($tenant);

        $this->stash->onTenantResolved(new TenantResolved($tenant, Request::create('/'), 'Tenancy\\Bundle\\Resolver\\HostResolver'));
        $this->stash->onTenantBootstrapped(new TenantBootstrapped($tenant, [
            'Tenancy\\Bundle\\Bootstrapper\\Foo',
            'Tenancy\\Bundle\\Bootstrapper\\Bar',
        ]));

        $original = new TenantDataCollector($this->stash, $this->tenantContext, 'database_per_tenant', 'default');
        $original->collect(Request::create('/'), new Response());
        $originalData = $original->getData();

        $restored = unserialize(serialize($original));

        self::assertInstanceOf(TenantDataCollector::class, $restored);
        self::assertSame($originalData, $restored->getData(), 'getData() must be byte-identical after serialize/unserialize round-trip');
    }

    public function testCollectorRoundTripsNullState(): void
    {
        // Stash receives no events — getResolvedBy()=null, getBootstrapperFqcns()=[], getCapturedException()=null
        $collector = new TenantDataCollector($this->stash, $this->tenantContext, 'shared_db', 'default');
        $collector->collect(Request::create('/'), new Response());

        $restored = unserialize(serialize($collector));

        self::assertInstanceOf(TenantDataCollector::class, $restored);
        self::assertSame($collector->getData(), $restored->getData());
        self::assertSame('null', $restored->getData()['state']);
    }

    public function testCollectorRoundTripsErrorState(): void
    {
        $exception = new TenantNotFoundException('tenant "ghost" not found');
        $this->stash->onKernelException($this->makeExceptionEvent($exception));

        $collector = new TenantDataCollector($this->stash, $this->tenantContext, 'shared_db', 'default');
        $collector->collect(Request::create('/'), new Response());

        $restored = unserialize(serialize($collector));

        self::assertInstanceOf(TenantDataCollector::class, $restored);
        self::assertSame($collector->getData(), $restored->getData());
        $restoredData = $restored->getData();
        self::assertSame('error', $restoredData['state']);
        $error = $restoredData['error'];
        self::assertIsArray($error);
        self::assertSame(TenantNotFoundException::class, $error['class']);
        self::assertSame('tenant "ghost" not found', $error['message']);
    }

    public function testSerializedBlobContainsNoObjectReferences(): void
    {
        $tenant = $this->createMock(TenantInterface::class);
        $tenant->method('getSlug')->willReturn('acme');
        $tenant->method('getName')->willReturn('Acme');
        $this->tenantContext->setTenant($tenant);

        $this->stash->onTenantResolved(new TenantResolved($tenant, Request::create('/'), 'R'));
        $this->stash->onTenantBootstrapped(new TenantBootstrapped($tenant, ['A']));

        $collector = new TenantDataCollector($this->stash, $this->tenantContext, 'database_per_tenant', 'default');
        $collector->collect(Request::create('/'), new Response());

        $blob = serialize($collector);

        // Per DataCollector::__serialize (vendor: symfony/http-kernel), only $this->data round-trips.
        // The blob must NOT contain the stash, the TenantContext, or the TenantInterface mock —
        // those are constructor args, not state.
        self::assertStringNotContainsString('Closure', $blob);
        self::assertStringNotContainsString('Mock_', $blob);
        self::assertStringNotContainsString('TenantProfilerStash', $blob);
        self::assertStringNotContainsString('MockObject', $blob);
        self::assertStringNotContainsString('TenantContext', $blob);
    }

    private function makeExceptionEvent(\Throwable $exception): ExceptionEvent
    {
        $kernel = $this->createMock(HttpKernelInterface::class);

        return new ExceptionEvent(
            $kernel,
            Request::create('/'),
            HttpKernelInterface::MAIN_REQUEST,
            $exception,
        );
    }
}
