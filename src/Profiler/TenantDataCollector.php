<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Profiler;

use Symfony\Bundle\FrameworkBundle\DataCollector\AbstractDataCollector;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Tenancy\Bundle\Context\TenantContext;

/**
 * Web Profiler data collector for the Tenancy panel.
 *
 * Reads scalars synchronously on kernel.response via collect() (NOT the late variant — DX-02 acceptance line 2).
 * Stores an 8-key scalar-only array in $this->data so stored-profile reload round-trips losslessly.
 *
 * Three render states (D-04):
 *   - 'resolved': TenantContext->hasTenant() is true
 *   - 'error':    stash captured a Tenancy\Bundle\Exception\* exception
 *   - 'null':     no tenant resolved (public/landlord/health-check route) AND no error
 *
 * SECURITY (D-09): connection_name is a LABEL string ('tenant' or '%tenancy.landlord_connection%'),
 * NEVER a DSN. The match expression below produces only literal labels; the defensive `:`/`@` check
 * is a tripwire that throws if anyone ever wires a DSN-laden value through DI.
 */
final class TenantDataCollector extends AbstractDataCollector
{
    /**
     * @param string $driver             Value of %tenancy.driver% — 'database_per_tenant' | 'shared_db'
     * @param string $landlordConnection Value of %tenancy.landlord_connection% (defaults to 'default')
     */
    public function __construct(
        private readonly TenantProfilerStash $stash,
        private readonly TenantContext $tenantContext,
        private readonly string $driver,
        private readonly string $landlordConnection,
    ) {
    }

    public function collect(Request $request, Response $response, ?\Throwable $exception = null): void
    {
        $tenant = $this->tenantContext->getTenant();

        if (null !== $tenant) {
            $state = 'resolved';
        } elseif (null !== $this->stash->getCapturedException()) {
            $state = 'error';
        } else {
            $state = 'null';
        }

        $connectionName = match ($this->driver) {
            'database_per_tenant' => 'tenant',
            'shared_db' => $this->landlordConnection,
            default => null,
        };

        // Defensive tripwire (D-09): connection_name is a label, never a DSN.
        if (null !== $connectionName && (str_contains($connectionName, ':') || str_contains($connectionName, '@'))) {
            throw new \RuntimeException(sprintf('TenantDataCollector: connection_name "%s" looks like a DSN — never display credentials.', $connectionName));
        }

        $this->data = [
            'state' => $state,
            'slug' => $tenant?->getSlug(),
            'tenant_label' => $tenant?->getName(),
            'driver' => $this->driver,
            'connection_name' => $connectionName,
            'resolved_by' => $this->stash->getResolvedBy(),
            'bootstrappers' => array_values(array_map('strval', $this->stash->getBootstrapperFqcns())),
            'error' => $this->stash->getCapturedException(),
        ];
    }

    public function getName(): string
    {
        return 'tenancy';
    }

    public static function getTemplate(): string
    {
        return '@Tenancy/Collector/tenant.html.twig';
    }

    /**
     * Public accessor for $this->data — used by:
     *   - tests/Unit/Profiler/TenantDataCollectorTest (shape assertions)
     *   - tests/Integration/Profiler/TenantDataCollectorSerializationTest (round-trip equality)
     *   - Twig template indirectly via collector.data.* (works via __get on AbstractDataCollector,
     *     but explicit accessor aids tests and integration verification).
     *
     * @return array<string, mixed>
     */
    public function getData(): array
    {
        // AbstractDataCollector::$data is typed `array|Data`. We never wrap via VarDumper helpers (D-11),
        // so it is always a plain array here. The assertion documents the invariant.
        \assert(is_array($this->data), 'TenantDataCollector::$data must be a plain array — scalar-only invariant per D-11.');

        return $this->data;
    }
}
