<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Integration\Support;

use Tenancy\Bundle\Provider\TenantProviderInterface;
use Tenancy\Bundle\TenantInterface;

/**
 * Null TenantProvider for integration tests.
 * Replaces DoctrineTenantProvider (which requires Doctrine EM + cache) in test kernels.
 * Throws if actually called — only DI wiring is tested, not actual lookups.
 */
final class NullTenantProvider implements TenantProviderInterface
{
    public function findBySlug(string $slug): TenantInterface
    {
        throw new \RuntimeException('NullTenantProvider::findBySlug must not be called in DI integration tests.');
    }
}
