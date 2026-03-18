<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Exception;

use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

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

    public function getHeaders(): array
    {
        return [];
    }
}
