<?php

declare(strict_types=1);

namespace Tenancy\Bundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Asserts at container compile time that the `tenancy.origin.allow_list` parameter is
 * non-empty and well-formed whenever the `'origin'` resolver short-name is configured
 * in `tenancy.resolvers`. Normalizes each entry (port defaults, lowercased host,
 * wildcard-suffix extraction) so the runtime resolver does pure equality + suffix-check.
 *
 * Without this pass, an empty allow-list silently auto-rejects every Origin (no tenant
 * resolves and the chain falls through), a mid-string wildcard `app.*.example.com`
 * compiles fine and creates a routing footgun, and a path-bearing origin like
 * `https://acme.app.example.com/api` parses cleanly but mismatches every browser-sent
 * `Origin` value. This pass turns each of those into a clear container-build error.
 *
 * Self-gated: short-circuits silently when `'origin'` is not in `tenancy.resolvers`,
 * so the pass can be registered unconditionally in `TenancyBundle::build()`.
 */
final class OriginHeaderResolverConfigPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasParameter('tenancy.resolvers')) {
            return;
        }

        /** @var list<string> $resolvers */
        $resolvers = $container->getParameter('tenancy.resolvers');
        if (!in_array('origin', $resolvers, true)) {
            return;
        }

        $allowList = $container->hasParameter('tenancy.origin.allow_list')
            ? $container->getParameter('tenancy.origin.allow_list')
            : [];

        if (!is_array($allowList) || [] === $allowList) {
            throw new \InvalidArgumentException('tenancy.origin.allow_list is empty but "origin" is configured in tenancy.resolvers — either remove "origin" from resolvers or add at least one allow-list entry');
        }

        $normalized = [];
        foreach ($allowList as $entry) {
            $normalized[] = $this->normalizeEntry($entry);
        }

        $container->setParameter('tenancy.origin.allow_list', $normalized);
    }

    /**
     * @return array{
     *     origin: string, host: string, scheme: string, port: int,
     *     is_wildcard: bool, wildcard_suffix: ?string, slug: ?string,
     * }
     */
    private function normalizeEntry(mixed $entry): array
    {
        if (!is_array($entry) || !isset($entry['origin']) || !is_string($entry['origin']) || '' === $entry['origin']) {
            throw new \InvalidArgumentException(sprintf('tenancy.origin.allow_list entry "%s" is unparseable — must be an absolute origin URL (scheme://host[:port])', $this->describe($entry)));
        }

        $raw = $entry['origin'];
        $parts = parse_url($raw);
        if (false === $parts || !isset($parts['scheme'], $parts['host'])) {
            throw new \InvalidArgumentException(sprintf('tenancy.origin.allow_list entry "%s" is unparseable — must be an absolute origin URL (scheme://host[:port])', $raw));
        }

        $scheme = strtolower((string) $parts['scheme']);
        if ('http' !== $scheme && 'https' !== $scheme) {
            throw new \InvalidArgumentException(sprintf('tenancy.origin.allow_list entry "%s" is unparseable — must be an absolute origin URL (scheme://host[:port])', $raw));
        }

        if ((isset($parts['path']) && '' !== $parts['path'])
            || (isset($parts['query']) && '' !== $parts['query'])
            || (isset($parts['fragment']) && '' !== $parts['fragment'])
            || isset($parts['user']) || isset($parts['pass'])) {
            throw new \InvalidArgumentException(sprintf('tenancy.origin.allow_list entry "%s" contains a path/query — origin URLs must be bare authorities', $raw));
        }

        $host = strtolower((string) $parts['host']);
        if ('' === $host) {
            throw new \InvalidArgumentException(sprintf('tenancy.origin.allow_list entry "%s" is unparseable — must be an absolute origin URL (scheme://host[:port])', $raw));
        }

        $port = isset($parts['port']) ? (int) $parts['port'] : ('https' === $scheme ? 443 : 80);

        $isWildcard = false;
        $wildcardSuffix = null;
        if (str_contains($host, '*')) {
            // Acceptable shape: exactly "*." followed by a label-bearing suffix, no further "*".
            if (!str_starts_with($host, '*.') || 1 !== substr_count($host, '*')) {
                throw new \InvalidArgumentException(sprintf('tenancy.origin.allow_list entry "%s" contains a mid-string wildcard — only one leftmost label may be "*"', $raw));
            }
            $tail = substr($host, 2); // drop "*."
            if ('' === $tail || str_starts_with($tail, '.') || !str_contains($tail, '.')) {
                // Examples rejected: "*", "*.", "*.com" with empty/degenerate suffix, "*..foo"
                throw new \InvalidArgumentException(sprintf('tenancy.origin.allow_list entry "%s" contains a mid-string wildcard — only one leftmost label may be "*"', $raw));
            }
            $isWildcard = true;
            $wildcardSuffix = '.'.$tail;
        }

        $slug = $entry['slug'] ?? null;
        if (null !== $slug && (!is_string($slug) || '' === $slug)) {
            throw new \InvalidArgumentException(sprintf('tenancy.origin.allow_list entry "%s" has an invalid slug — must be a non-empty string or null (wildcard entries derive slug at runtime)', $raw));
        }
        if (!$isWildcard && null === $slug) {
            throw new \InvalidArgumentException(sprintf('tenancy.origin.allow_list entry "%s" requires an explicit slug when the origin contains no wildcard label', $raw));
        }

        return [
            'origin' => sprintf('%s://%s:%d', $scheme, $host, $port),
            'host' => $host,
            'scheme' => $scheme,
            'port' => $port,
            'is_wildcard' => $isWildcard,
            'wildcard_suffix' => $wildcardSuffix,
            'slug' => $isWildcard ? null : $slug,
        ];
    }

    private function describe(mixed $entry): string
    {
        if (is_string($entry)) {
            return $entry;
        }
        if (is_array($entry) && isset($entry['origin']) && is_string($entry['origin'])) {
            return $entry['origin'];
        }

        return get_debug_type($entry);
    }
}
