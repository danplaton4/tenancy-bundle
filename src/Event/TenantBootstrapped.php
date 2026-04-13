<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Event;

use Tenancy\Bundle\TenantInterface;

final class TenantBootstrapped
{
    /**
     * @param string[] $bootstrappers FQCNs of bootstrappers that ran (in order)
     */
    public function __construct(
        public readonly TenantInterface $tenant,
        public readonly array $bootstrappers,
    ) {
    }
}
