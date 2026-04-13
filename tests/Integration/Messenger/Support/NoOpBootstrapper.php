<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Integration\Messenger\Support;

use Tenancy\Bundle\Bootstrapper\TenantBootstrapperInterface;
use Tenancy\Bundle\TenantInterface;

/**
 * No-op bootstrapper for Messenger integration tests.
 * Replaces DoctrineBootstrapper (which requires a Doctrine EM) so the container
 * compiles in the Messenger test kernel which does not register DoctrineBundle.
 */
final class NoOpBootstrapper implements TenantBootstrapperInterface
{
    public function boot(TenantInterface $tenant): void
    {
        // no-op: no Doctrine EM in Messenger test kernel
    }

    public function clear(): void
    {
        // no-op
    }
}
