<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Resolver;

use Symfony\Component\HttpFoundation\Request;
use Tenancy\Bundle\TenantInterface;

interface TenantResolverInterface
{
    public function resolve(Request $request): ?TenantInterface;
}
