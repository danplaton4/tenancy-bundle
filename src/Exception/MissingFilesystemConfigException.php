<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Exception;

/**
 * Thrown when `per_tenant_adapter` mode encounters a tenant whose
 * `filesystemConfig.adapter_dsn` is null or missing.
 *
 * Extends \LogicException (not \RuntimeException) so Symfony Messenger's
 * default retry strategy does NOT re-queue the misconfigured worker:
 * misconfiguration is a programmer/operator error, not a transient fault.
 *
 * @see .planning/phases/24-filesystem-bootstrapper/24-CONTEXT.md §DEC-FILE-EXCEPTION
 * @see .planning/v0.3-MILESTONE-AUDIT.md §WR-01 — the LogicException invariant
 *      this class mirrors (MissingTenantProviderException).
 */
final class MissingFilesystemConfigException extends \LogicException
{
    public static function forTenant(string $slug): self
    {
        return new self(sprintf(
            'tenancy: tenant "%s" has no filesystemConfig.adapter_dsn — per_tenant_adapter strategy requires a non-null adapter_dsn. Set it via Tenant::setFilesystemConfig(["adapter_dsn" => "…"]).',
            $slug
        ));
    }
}
