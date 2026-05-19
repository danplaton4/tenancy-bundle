<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Bootstrapper;

use Tenancy\Bundle\Mailer\LruTransportCache;
use Tenancy\Bundle\TenantInterface;

/**
 * Tenant lifecycle participant for per-tenant Mailer dispatch.
 *
 * boot() is intentionally a no-op: the tenant's mailerDsn is read live at
 * send-time by TenantAwareTransportsDecorator (via TenantContext +
 * TenantProviderInterface). There is no per-request state to set up here —
 * see .planning/phases/20-mailer-bootstrapper/20-RESEARCH.md Assumption A3.
 *
 * clear() flushes the LRU transport cache so per-tenant SMTP sockets are
 * closed cleanly between requests / messages. Registration priority (-20)
 * places this AFTER DatabaseSwitchBootstrapper / DoctrineBootstrapper on
 * boot, and BEFORE them on clear (BootstrapperChain::clear() reverses).
 * Mailer socket cleanup MUST happen before EM reset (D-07).
 */
final class MailerBootstrapper implements TenantBootstrapperInterface
{
    public function __construct(
        private readonly ?LruTransportCache $cache = null,
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
