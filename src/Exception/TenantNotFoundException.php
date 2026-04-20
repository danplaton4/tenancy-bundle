<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Exception;

use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Thrown when a resolver extracted a tenant identifier from the request but the provider
 * could not find an active matching tenant (e.g. "host=unknown.example.com" extracts slug
 * "unknown" but no Tenant entity with slug='unknown' exists).
 *
 * NOT thrown by ResolverChain when no resolver matches at all — that case returns null
 * from ResolverChain::resolve() and the orchestrator leaves the request untouched.
 * See Phase 15 (v0.2) for the narrowing rationale.
 *
 * Single live thrower after v0.2: Tenancy\Bundle\Provider\DoctrineTenantProvider::findBySlug().
 * HTTP semantics (404) remain correct: the client asked for a tenant that does not exist.
 */
final class TenantNotFoundException extends \RuntimeException implements HttpExceptionInterface
{
    public function __construct(string $message = 'Tenant not found.', ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    public function getStatusCode(): int
    {
        return 404;
    }

    /** @return array<string, string> */
    public function getHeaders(): array
    {
        return [];
    }
}
