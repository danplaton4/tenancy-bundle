<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Resolver;

use Symfony\Component\HttpFoundation\Request;
use Tenancy\Bundle\Exception\TenantNotFoundException;
use Tenancy\Bundle\Provider\TenantProviderInterface;
use Tenancy\Bundle\TenantInterface;

final class HostResolver implements TenantResolverInterface
{
    public function __construct(
        private readonly TenantProviderInterface $tenantProvider,
        private readonly ?string $appDomain = null,
    ) {}

    public function resolve(Request $request): ?TenantInterface
    {
        if ($this->appDomain === null) {
            return null;
        }

        $slug = $this->extractSlug($request->getHost());
        if ($slug === null) {
            return null;
        }

        try {
            return $this->tenantProvider->findBySlug($slug);
        } catch (TenantNotFoundException) {
            return null; // Let chain try other resolvers
        }
        // TenantInactiveException is NOT caught — bubbles up as HTTP 403
    }

    private function extractSlug(string $host): ?string
    {
        $host = strtolower($host);

        // Strip www. prefix
        if (str_starts_with($host, 'www.')) {
            $host = substr($host, 4);
        }

        $suffix = '.' . strtolower($this->appDomain);

        // Host must end with .app_domain
        if (!str_ends_with($host, $suffix)) {
            return null;
        }

        // Strip app_domain suffix to get subdomain prefix
        $subdomain = substr($host, 0, -strlen($suffix));

        if ($subdomain === '' || $subdomain === false) {
            return null; // Host is exactly app_domain, no subdomain
        }

        // For multi-segment subdomains (api.acme), take the last segment as the slug
        $parts = explode('.', $subdomain);
        $slug = end($parts);

        return ($slug !== '' && $slug !== false) ? $slug : null;
    }
}
