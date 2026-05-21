<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Exception;

/**
 * Thrown when a tenant-scoped write-path service is invoked but the
 * `tenancy.provider` service is not configured in the container.
 *
 * Extends \LogicException (not \RuntimeException) so Symfony Messenger's
 * default retry strategy does NOT re-queue the misconfigured worker:
 * misconfiguration is a programmer/operator error, not a transient fault.
 */
final class MissingTenantProviderException extends \LogicException
{
    public function __construct(string $callerContext, ?\Throwable $previous = null)
    {
        parent::__construct(
            sprintf(
                '%s requires the tenancy bundle to be configured. Run `bin/console tenancy:install` or configure `tenancy.provider` in config/packages/tenancy.yaml.',
                $callerContext
            ),
            0,
            $previous
        );
    }
}
