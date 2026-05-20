<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Mailer;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Tenancy\Bundle\Event\TenantContextCleared;

/**
 * Flushes the per-tenant SMTP transport cache when the tenant context is cleared.
 *
 * Subscribes to the bundle's TenantContextCleared event — dispatched by both
 * TenantContextOrchestrator (HTTP path: kernel.terminate / kernel.exception)
 * and TenantWorkerMiddleware (async path: after each handled message). This
 * guarantees the LruTransportCache is reset regardless of which teardown
 * path the kernel takes.
 *
 * Redundant with MailerBootstrapper::clear() — which also calls
 * LruTransportCache::clear(). Two paths to the same outcome is intentional:
 * the BootstrapperChain may not always be invoked (e.g. in some Messenger
 * middleware orderings), but TenantContextCleared is always dispatched.
 * Belt-and-suspenders on socket lifecycle (roadmap success criterion 6).
 */
final class TenantContextClearedListener implements EventSubscriberInterface
{
    public function __construct(private readonly LruTransportCache $cache)
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
