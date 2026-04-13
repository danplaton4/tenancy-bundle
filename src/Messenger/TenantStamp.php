<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Messenger;

use Symfony\Component\Messenger\Stamp\StampInterface;

final class TenantStamp implements StampInterface
{
    public function __construct(public readonly string $tenantSlug)
    {
    }

    public function getTenantSlug(): string
    {
        return $this->tenantSlug;
    }
}
