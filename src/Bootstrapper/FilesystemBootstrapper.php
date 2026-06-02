<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Bootstrapper;

use Tenancy\Bundle\Filesystem\LruFilesystemCache;
use Tenancy\Bundle\TenantInterface;

/**
 * Tenant lifecycle participant for per-tenant Filesystem scoping.
 *
 * boot() is intentionally a no-op: tenant-specific path prefixing and
 * per-tenant adapter selection are resolved live at call-time by
 * FilesystemPrefixingDecorator (Plan 24-05) and TenantAwareFilesystemDecorator
 * (Plan 24-06) via TenantContext — no per-request state to set up here.
 *
 * clear() flushes the LRU filesystem cache so per-tenant adapter instances
 * are released cleanly between requests / messages. Registration priority
 * (-30) places this AFTER MailerBootstrapper (-20) on boot, and BEFORE it on
 * clear (BootstrapperChain::clear() reverses order). Filesystem adapter
 * cleanup runs before SMTP socket cleanup, which in turn runs before EM reset.
 *
 * @see .planning/phases/24-filesystem-bootstrapper/24-CONTEXT.md §DEC-FILE-PRIORITY
 * @see .planning/phases/24-filesystem-bootstrapper/24-CONTEXT.md §DEC-FILE-MODE
 */
final class FilesystemBootstrapper implements TenantBootstrapperInterface
{
    public function __construct(
        private readonly ?LruFilesystemCache $cache = null,
    ) {
    }

    public function boot(TenantInterface $tenant): void
    {
        // Intentional no-op. See class docblock.
    }

    public function clear(): void
    {
        $this->cache?->clear();
    }
}
