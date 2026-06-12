<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Integration\SharedEntity\Support;

use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Kernel variant that injects a RecordingLogger into the sync subscriber.
 *
 * Extends SharedEntitySyncTestKernel so it inherits the landlord + tenant SQLite
 * connections and all entity mappings. Adds InjectRecordingLoggerPass on top so
 * the test can assert PSR-3 error log output from the fan-out failure path (D-07).
 *
 * Used exclusively by SharedEntityFailureLoggingTest (SHARE-01-k).
 */
final class SharedEntityFailureLoggingTestKernel extends SharedEntitySyncTestKernel
{
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->addCompilerPass(new InjectRecordingLoggerPass());
    }
}
