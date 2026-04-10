<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Exception;

use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class TenantInactiveException extends \RuntimeException implements HttpExceptionInterface
{
    public function __construct(string $slug = '', ?\Throwable $previous = null)
    {
        $message = $slug !== ''
            ? sprintf('Tenant "%s" is inactive.', $slug)
            : 'Tenant is inactive.';

        parent::__construct($message, 0, $previous);
    }

    public function getStatusCode(): int
    {
        return 403;
    }

    /** @return array<string, string> */
    public function getHeaders(): array
    {
        return [];
    }
}
