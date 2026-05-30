<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Filesystem;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Tenancy\Bundle\Event\TenantContextCleared;

/**
 * Flushes the per-tenant FilesystemOperator LRU cache when the tenant context is cleared.
 *
 * Subscribes to the bundle's TenantContextCleared event — dispatched by both
 * TenantContextOrchestrator (HTTP path: kernel.terminate / kernel.exception)
 * and TenantWorkerMiddleware (async path: after each handled message). This
 * guarantees the LruFilesystemCache is reset regardless of which teardown
 * path the kernel takes.
 *
 * Redundant with FilesystemBootstrapper::clear() (Plan 24-07) — which also
 * calls LruFilesystemCache::clear(). Two paths to the same outcome is
 * intentional: the BootstrapperChain may not always be invoked (e.g. in some
 * Messenger middleware orderings), but TenantContextCleared is always
 * dispatched. Belt-and-suspenders on per-tenant adapter lifecycle — mirrors
 * the Phase 20 src/Mailer/TenantContextClearedListener shape exactly,
 * substituting LruFilesystemCache for LruTransportCache.
 *
 * @see .planning/phases/24-filesystem-bootstrapper/24-CONTEXT.md §DEC-FILE-PRIORITY
 * @see src/Mailer/TenantContextClearedListener.php
 */
final class TenantContextClearedListener implements EventSubscriberInterface
{
    public function __construct(private readonly LruFilesystemCache $cache)
    {
    }

    public function onContextCleared(TenantContextCleared $event): void
    {
        $this->cache->clear();
    }

    /**
     * @return array<class-string, string>
     */
    public static function getSubscribedEvents(): array
    {
        return [TenantContextCleared::class => 'onContextCleared'];
    }
}
