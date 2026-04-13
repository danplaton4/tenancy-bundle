<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Exception;

final class TenantMissingException extends \RuntimeException
{
    public function __construct(string $entityClass, ?\Throwable $previous = null)
    {
        parent::__construct(
            sprintf("No active tenant in context. Cannot query TenantAware entity '%s' in strict mode.", $entityClass),
            0,
            $previous
        );
    }
}
