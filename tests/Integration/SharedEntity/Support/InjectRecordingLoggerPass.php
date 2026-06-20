<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Integration\SharedEntity\Support;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Tenancy\Bundle\Tests\Support\RecordingLogger;

/**
 * Compiler pass that registers a RecordingLogger service and wires it as the
 * logger argument (index 3) of tenancy.shared_entity_sync_subscriber.
 *
 * This allows integration tests to assert structured PSR-3 log output from the
 * sync subscriber without depending on a real monolog channel or log file.
 *
 * The RecordingLogger service is made public so tests can retrieve it from the
 * compiled container via $container->get('tenancy.test.recording_logger').
 *
 * Only applies when tenancy.shared_entity_sync_subscriber is defined (i.e.,
 * when database.enabled: true is in the kernel config).
 */
final class InjectRecordingLoggerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition('tenancy.shared_entity_sync_subscriber')) {
            return;
        }

        $loggerDef = new Definition(RecordingLogger::class);
        $loggerDef->setPublic(true);
        $container->setDefinition('tenancy.test.recording_logger', $loggerDef);

        // Replace argument index 3 (LoggerInterface $logger) with the recording logger
        $subscriberDef = $container->getDefinition('tenancy.shared_entity_sync_subscriber');
        $subscriberDef->setArgument(3, new Reference('tenancy.test.recording_logger'));
    }
}
