<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Profiler;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Contracts\Service\ResetInterface;
use Tenancy\Bundle\Event\TenantBootstrapped;
use Tenancy\Bundle\Event\TenantContextCleared;
use Tenancy\Bundle\Event\TenantResolved;

/**
 * Captures TenantResolved::$resolvedBy, TenantBootstrapped::$bootstrappers, and tenancy-namespaced
 * exception class+message from event time so TenantDataCollector::collect() can read them on kernel.response.
 *
 * Keeps TenantContext zero-dep (Phase 1 architectural rule) — the collector reads TenantContext directly
 * for slug/tenant_label, and reads THIS stash for resolved_by + bootstrappers + error metadata.
 *
 * Implements ResetInterface for long-running runtimes (FrankenPHP/Swoole/RoadRunner); autoconfigure
 * adds the `kernel.reset` tag automatically.
 */
#[AsEventListener(event: TenantResolved::class, method: 'onTenantResolved')]
#[AsEventListener(event: TenantBootstrapped::class, method: 'onTenantBootstrapped')]
#[AsEventListener(event: TenantContextCleared::class, method: 'onTenantContextCleared')]
#[AsEventListener(event: ExceptionEvent::class, method: 'onKernelException')]
final class TenantProfilerStash implements ResetInterface
{
    private const TENANCY_EXCEPTION_NAMESPACE_PREFIX = 'Tenancy\\Bundle\\Exception\\';

    private ?string $resolvedBy = null;

    /** @var string[] */
    private array $bootstrapperFqcns = [];

    /** @var array{class: string, message: string}|null */
    private ?array $capturedException = null;

    public function onTenantResolved(TenantResolved $event): void
    {
        $this->resolvedBy = $event->resolvedBy;
    }

    public function onTenantBootstrapped(TenantBootstrapped $event): void
    {
        $this->bootstrapperFqcns = array_values(array_map('strval', $event->bootstrappers));
    }

    public function onTenantContextCleared(TenantContextCleared $event): void
    {
        $this->reset();
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        $throwable = $event->getThrowable();
        if (!str_starts_with($throwable::class, self::TENANCY_EXCEPTION_NAMESPACE_PREFIX)) {
            return;
        }
        $this->capturedException = [
            'class' => $throwable::class,
            'message' => $throwable->getMessage(),
        ];
    }

    public function getResolvedBy(): ?string
    {
        return $this->resolvedBy;
    }

    /**
     * @return string[]
     */
    public function getBootstrapperFqcns(): array
    {
        return $this->bootstrapperFqcns;
    }

    /**
     * @return array{class: string, message: string}|null
     */
    public function getCapturedException(): ?array
    {
        return $this->capturedException;
    }

    public function reset(): void
    {
        $this->resolvedBy = null;
        $this->bootstrapperFqcns = [];
        $this->capturedException = null;
    }
}
