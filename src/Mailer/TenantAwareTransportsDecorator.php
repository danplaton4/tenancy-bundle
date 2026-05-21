<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Mailer;

use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Message;
use Symfony\Component\Mime\RawMessage;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Tenancy\Bundle\Context\TenantContext;
use Tenancy\Bundle\Provider\TenantProviderInterface;

/**
 * Decorates Symfony's mailer.transports registry. Intercepts X-Transport names
 * matching `tenant_<slug>`, resolves the tenant via TenantProviderInterface,
 * builds an SMTP transport from the tenant's mailerDsn, and caches the
 * transport in a bounded LRU.
 *
 * All other X-Transport names pass through to the inner Transports unchanged.
 * Messages without X-Transport pass through unchanged.
 *
 * Implements TransportInterface (the alias for the decorated mailer.transports
 * service). The decorator is itself injected into MessageHandler on the worker
 * side via the standard Symfony decoration chain.
 *
 * Per RESEARCH Q2 RESOLVED — the optional EventDispatcherInterface constructor
 * argument is passed to Transport::fromDsn so SentMessageEvent / FailedMessageEvent
 * fire from tenant transports identically to the landlord transport.
 *
 * The transportFactory Closure is injectable for testability — production wiring
 * uses the default factory that delegates to Transport::fromDsn.
 *
 * X-Transport stamping is performed UPSTREAM by TenantMailerDecorator
 * (decoration_priority 10 on the `mailer` service). This decorator only
 * READS the header to make a routing decision — it does not mutate it.
 *
 * @see TenantMailerDecorator
 */
final class TenantAwareTransportsDecorator implements TransportInterface
{
    /** @var \Closure(string, ?EventDispatcherInterface): TransportInterface */
    private \Closure $transportFactory;

    /**
     * @param \Closure(string, ?EventDispatcherInterface): TransportInterface|null $transportFactory
     */
    public function __construct(
        private readonly TransportInterface $inner,
        private readonly ?TenantProviderInterface $provider,
        private readonly LruTransportCache $cache,
        private readonly TenantContext $context,
        private readonly ?EventDispatcherInterface $eventDispatcher = null,
        ?\Closure $transportFactory = null,
    ) {
        $this->transportFactory = $transportFactory
            ?? static fn (string $dsn, ?EventDispatcherInterface $dispatcher): TransportInterface => Transport::fromDsn($dsn, $dispatcher);
    }

    public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
    {
        if (!$message instanceof Message || !$message->getHeaders()->has('X-Transport')) {
            return $this->inner->send($message, $envelope);
        }

        $header = $message->getHeaders()->get('X-Transport');
        if (null === $header) {
            return $this->inner->send($message, $envelope);
        }

        $headerValue = $header->getBodyAsString();

        if (!str_starts_with($headerValue, 'tenant_')) {
            return $this->inner->send($message, $envelope);
        }

        $slug = substr($headerValue, 7); // strip "tenant_" prefix

        // Plan 20-11 / REVIEW BL-02 — empty-slug guard.
        // X-Transport === 'tenant_' (literal, no slug after the underscore)
        // produces $slug === ''. The empty string flowing into
        // TenantProviderInterface::findBySlug('') is a hostile-input vector:
        // a pathological provider could return the first row of the tenants
        // table. The cross-tenant guard below only catches the case where an
        // active tenant is set; this guard catches the no-active-tenant path
        // (worker-pre-restoration, sync-context misuse).
        if ('' === $slug) {
            throw new \RuntimeException('tenancy: refusing to route mail — X-Transport "tenant_" has an empty slug.');
        }

        // Plan 20-11 / REVIEW BL-02 — character-set guard.
        // Slugs in this bundle match [a-z0-9_-]+ (lower-case alphanumerics
        // plus `-` and `_`). Reject any other shape BEFORE the provider
        // round-trip so user-supplied providers never see weird input.
        // Catches: whitespace, dots, slashes, uppercase, unicode, etc.
        if (1 !== preg_match('/^[a-z0-9_-]+$/', $slug)) {
            throw new \RuntimeException(sprintf('tenancy: refusing to route mail — X-Transport "tenant_%s" has an invalid slug (must match [a-z0-9_-]+).', $slug));
        }

        // Defensive cross-tenant guard (T-20-03-02 mitigation): if a tenant is active
        // in the context AND its slug differs from the routed header slug, this
        // indicates a stale / cross-context message. Refuse to send rather than
        // leak across tenants. Messages dispatched without an active context (e.g.
        // worker before TenantWorkerMiddleware restores context) bypass this guard.
        $activeTenant = $this->context->getTenant();
        if (null !== $activeTenant && $activeTenant->getSlug() !== $slug) {
            throw new \RuntimeException(sprintf('tenancy: refusing to route mail — message X-Transport "tenant_%s" does not match active tenant "%s". Possible cross-tenant message leak.', $slug, $activeTenant->getSlug()));
        }

        $transport = $this->cache->get($slug) ?? $this->buildAndCache($slug);

        // WR-08 fix (Plan 20-09): do NOT remove X-Transport from the
        // caller's message. The tenant-specific transport returned above
        // does not re-route on the header (it's a leaf transport bound to
        // tenant_<slug>'s DSN), so leaving it intact is harmless. Removing
        // it would silently strip routing metadata from a Message instance
        // the caller may re-send — causing cross-tenant misroute on retry.
        return $transport->send($message, $envelope);
    }

    public function __toString(): string
    {
        return 'tenant-aware:'.$this->inner;
    }

    private function buildAndCache(string $slug): TransportInterface
    {
        if (null === $this->provider) {
            throw new \RuntimeException(sprintf('tenancy: cannot build transport for "tenant_%s" — no TenantProviderInterface wired. Configure tenancy.provider in services.php.', $slug));
        }

        $tenant = $this->provider->findBySlug($slug);
        $dsn = $tenant->getMailerDsn();
        if (null === $dsn) {
            throw new \RuntimeException(sprintf('tenancy: tenant "%s" has no mailerDsn configured — cannot route mail via tenant transport.', $slug));
        }

        $transport = ($this->transportFactory)($dsn, $this->eventDispatcher);
        $this->cache->set($slug, $transport);

        return $transport;
    }
}
