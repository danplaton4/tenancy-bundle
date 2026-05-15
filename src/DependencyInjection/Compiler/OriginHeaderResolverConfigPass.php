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
        foreach ($allowList as $index => $entry) {
            $normalized[] = $this->normalizeEntry($entry, (int) $index);
        }

        $container->setParameter('tenancy.origin.allow_list', $normalized);
    }

    /**
     * @return array{
     *     origin: string, host: string, scheme: string, port: int,
     *     is_wildcard: bool, wildcard_suffix: ?string, slug: ?string,
     * }
     */
    private function normalizeEntry(mixed $entry, int $index): array
    {
        if (!is_array($entry) || !isset($entry['origin']) || !is_string($entry['origin']) || '' === $entry['origin']) {
            throw new \InvalidArgumentException(sprintf('tenancy.origin.allow_list[%d] (%s) is unparseable — must be an absolute origin URL (scheme://host[:port])', $index, $this->describe($entry)));
        }

        $raw = $entry['origin'];
        $parts = parse_url($raw);
        if (false === $parts || !isset($parts['scheme'], $parts['host'])) {
            throw new \InvalidArgumentException(sprintf('tenancy.origin.allow_list[%d] ("%s") is unparseable — must be an absolute origin URL (scheme://host[:port])', $index, $raw));
        }

        $scheme = strtolower((string) $parts['scheme']);
        if ('http' !== $scheme && 'https' !== $scheme) {
            throw new \InvalidArgumentException(sprintf('tenancy.origin.allow_list[%d] ("%s") is unparseable — must be an absolute origin URL (scheme://host[:port])', $index, $raw));
        }

        if ((isset($parts['path']) && '' !== $parts['path'])
            || (isset($parts['query']) && '' !== $parts['query'])
            || (isset($parts['fragment']) && '' !== $parts['fragment'])
            || isset($parts['user']) || isset($parts['pass'])) {
            throw new \InvalidArgumentException(sprintf('tenancy.origin.allow_list[%d] ("%s") contains a path/query — origin URLs must be bare authorities', $index, $raw));
        }

        $host = strtolower((string) $parts['host']);
        if ('' === $host) {
            throw new \InvalidArgumentException(sprintf('tenancy.origin.allow_list[%d] ("%s") is unparseable — must be an absolute origin URL (scheme://host[:port])', $index, $raw));
        }

        $port = isset($parts['port']) ? (int) $parts['port'] : ('https' === $scheme ? 443 : 80);

        $isWildcard = false;
        $wildcardSuffix = null;
        if (str_contains($host, '*')) {
            // Reject any extra '*' beyond the single leftmost label.
            if (substr_count($host, '*') > 1) {
                throw new \InvalidArgumentException(sprintf('tenancy.origin.allow_list[%d] ("%s") contains a mid-string wildcard — only one leftmost label may be "*"', $index, $raw));
            }
            // A lone '*' or '*' anywhere other than the leftmost label is a mid-string wildcard.
            // Bare-suffix issues (e.g. '*' alone, '*.com', '*.', '*..foo') are reported separately.
            if (!str_starts_with($host, '*.')) {
                if ('*' === $host) {
                    throw new \InvalidArgumentException(sprintf('tenancy.origin.allow_list[%d] ("%s") has an invalid wildcard suffix — wildcard must be "*." followed by at least two labels (e.g. "*.example.com")', $index, $raw));
                }
                throw new \InvalidArgumentException(sprintf('tenancy.origin.allow_list[%d] ("%s") contains a mid-string wildcard — only one leftmost label may be "*"', $index, $raw));
            }
            $tail = substr($host, 2); // drop "*."
            if ('' === $tail || str_starts_with($tail, '.') || !str_contains($tail, '.')) {
                // Examples rejected: "*.", "*.com" with empty/degenerate suffix, "*..foo"
                throw new \InvalidArgumentException(sprintf('tenancy.origin.allow_list[%d] ("%s") has an invalid wildcard suffix — wildcard must be "*." followed by at least two labels (e.g. "*.example.com")', $index, $raw));
            }
            $isWildcard = true;
            $wildcardSuffix = '.'.$tail;
        }

        $slug = $entry['slug'] ?? null;
        if (null !== $slug && (!is_string($slug) || '' === $slug)) {
            throw new \InvalidArgumentException(sprintf('tenancy.origin.allow_list[%d] ("%s") has an invalid slug — must be a non-empty string or null (wildcard entries derive slug at runtime)', $index, $raw));
        }
        if ($isWildcard && null !== $slug) {
            throw new \InvalidArgumentException(sprintf('tenancy.origin.allow_list[%d] ("%s") is a wildcard entry but specifies an explicit slug — wildcard entries derive the slug from the matched label at runtime; either remove the wildcard or remove the slug', $index, $raw));
        }
        if (!$isWildcard && null === $slug) {
            throw new \InvalidArgumentException(sprintf('tenancy.origin.allow_list[%d] ("%s") requires an explicit slug when the origin contains no wildcard label', $index, $raw));
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

    /**
     * Render a configuration value for inclusion in an error message.
     *
     * Strings are wrapped in quotes; arrays containing a string `origin` key fall
     * back to that origin; everything else is rendered via `var_export()` and
     * truncated to ~80 characters so the operator sees the actual offending
     * value rather than just `"int"` or `"array"`.
     */
    private function describe(mixed $entry): string
    {
        if (is_string($entry)) {
            return sprintf('"%s"', $entry);
        }
        if (is_array($entry) && isset($entry['origin']) && is_string($entry['origin'])) {
            return sprintf('"%s"', $entry['origin']);
        }

        $rendered = var_export($entry, true);
        if (strlen($rendered) > 80) {
            $rendered = substr($rendered, 0, 77).'...';
        }

        return sprintf('%s: %s', get_debug_type($entry), $rendered);
    }
}
