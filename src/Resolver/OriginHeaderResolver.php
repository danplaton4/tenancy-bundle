<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Resolver;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Tenancy\Bundle\Exception\TenantNotFoundException;
use Tenancy\Bundle\Provider\TenantProviderInterface;
use Tenancy\Bundle\TenantInterface;

/**
 * Resolves the active tenant from the browser-set `Origin` HTTP header.
 *
 * Registered at resolver-chain priority 25 (above HeaderResolver=20, below HostResolver=30):
 * Origin is browser-locked for cross-origin XHR/fetch so it is a stronger tenant signal than
 * X-Tenant-ID, but explicit subdomain routing (HostResolver) still wins.
 *
 * Trust model: `Origin` is set by the browser and cannot be forged from JS on a non-CORS
 * request, but it IS trivially settable from curl / Postman / native mobile / server-to-server.
 * This resolver is a routing hint, not an authentication factor — pair it with a real auth layer.
 * See `docs/user-guide/origin-header-resolver.md` § Trust Model.
 */
final class OriginHeaderResolver implements TenantResolverInterface
{
    public const HEADER_NAME = 'Origin';
    public const MISMATCH_HEADER_NAME = 'X-Tenant-ID';

    /**
     * @param list<array{
     *     origin: string,
     *     host: string,
     *     scheme: string,
     *     port: int,
     *     is_wildcard: bool,
     *     wildcard_suffix: ?string,
     *     slug: ?string,
     * }> $allowList Normalized at compile time by OriginHeaderResolverConfigPass
     */
    public function __construct(
        private readonly TenantProviderInterface $tenantProvider,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly array $allowList = [],
    ) {
    }

    public function resolve(Request $request): ?TenantInterface
    {
        // D-07: CORS preflight short-circuit BEFORE Origin parsing — preflight must not throw.
        if ('OPTIONS' === $request->getMethod()) {
            return null;
        }

        // D-08: absent or empty Origin header → fall through resolver chain.
        $origin = $request->headers->get(self::HEADER_NAME);
        if (null === $origin || '' === $origin) {
            return null;
        }

        // D-09: unparseable Origin → silently null (no log spam from misconfigured clients).
        $slug = $this->matchOrigin($origin);
        if (null === $slug) {
            return null;
        }

        try {
            $tenant = $this->tenantProvider->findBySlug($slug);
        } catch (TenantNotFoundException) {
            return null;
        }
        // TenantInactiveException is NOT caught — bubbles up as HTTP 403

        // D-11: peek X-Tenant-ID; warn on mismatch (Origin wins, no extra DB roundtrip).
        $headerSlug = $request->headers->get(self::MISMATCH_HEADER_NAME);
        if (null !== $headerSlug && '' !== $headerSlug
            && 0 !== strcasecmp($headerSlug, $tenant->getSlug())) {
            $this->logger->warning('Origin/X-Tenant-ID mismatch — Origin wins', [
                'origin' => $origin,
                'origin_slug' => $tenant->getSlug(),
                'header_slug' => $headerSlug,
                'winner' => 'origin',
            ]);
        }

        return $tenant;
    }

    private function matchOrigin(string $origin): ?string
    {
        $parts = parse_url($origin);
        if (false === $parts || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $scheme = strtolower((string) $parts['scheme']);
        if ('http' !== $scheme && 'https' !== $scheme) {
            return null;
        }

        $host = strtolower((string) $parts['host']);
        if ('' === $host) {
            return null;
        }

        $port = isset($parts['port']) ? (int) $parts['port'] : ('https' === $scheme ? 443 : 80);
        $normalized = sprintf('%s://%s:%d', $scheme, $host, $port);

        foreach ($this->allowList as $entry) {
            if ($entry['is_wildcard']) {
                if ($scheme !== $entry['scheme'] || $port !== $entry['port']) {
                    continue;
                }
                $suffix = $entry['wildcard_suffix'];
                if (null === $suffix || !str_ends_with($host, $suffix)) {
                    continue;
                }
                $label = substr($host, 0, -strlen($suffix));
                if ('' === $label || str_contains($label, '.')) {
                    continue; // wildcard accepts exactly one leftmost label
                }

                return $label;
            }

            if ($normalized === $entry['origin']) {
                return $entry['slug'];
            }
        }

        return null;
    }
}
