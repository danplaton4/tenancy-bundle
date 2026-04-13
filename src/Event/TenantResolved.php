<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Event;

use Symfony\Component\HttpFoundation\Request;
use Tenancy\Bundle\TenantInterface;

final class TenantResolved
{
    public function __construct(
        public readonly TenantInterface $tenant,
        public readonly ?Request $request,
        public readonly string $resolvedBy,
    ) {
    }
}
