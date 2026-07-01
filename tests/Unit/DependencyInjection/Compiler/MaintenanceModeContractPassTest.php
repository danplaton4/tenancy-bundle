<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Unit\DependencyInjection\Compiler;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\HttpKernel\KernelEvents;
use Tenancy\Bundle\DependencyInjection\Compiler\MaintenanceModeContractPass;
use Tenancy\Bundle\EventListener\TenantContextOrchestrator;

/**
 * Unit tests for MaintenanceModeContractPass — the compile-time priority guard (Success Criterion 3).
 *
 * Covers:
 * 1. No-op when parameter 'tenancy.maintenance.enabled' is absent
 * 2. No-op when parameter is present but false
 * 3. LogicException when maintenance.enabled=true but listener service is absent
 * 4. LogicException when listener is at priority >= TenantContextOrchestrator::PRIORITY (20)
 * 5. LogicException when listener is at priority exactly equal to PRIORITY (20)
 * 6. LogicException when listener is at priority 25 (> 20)
 * 7. No exception when listener is at priority 16 (< 20)
 * 8. Exception message references TenantContextOrchestrator::PRIORITY
 */
final class MaintenanceModeContractPassTest extends TestCase
{
    private const LISTENER_SERVICE_ID = 'tenancy.maintenance.listener';
    private const ENABLED_PARAM = 'tenancy.maintenance.enabled';

    public function testNoOpWhenParameterAbsent(): void
    {
        $container = new ContainerBuilder();
        // No parameter set at all

        $pass = new MaintenanceModeContractPass();
        $pass->process($container);

        // No exception — pass is a no-op when parameter is absent
        $this->assertFalse($container->hasParameter(self::ENABLED_PARAM));
    }

    public function testNoOpWhenParameterIsFalse(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter(self::ENABLED_PARAM, false);

        $pass = new MaintenanceModeContractPass();
        $pass->process($container);

        // No exception — pass is a no-op when maintenance is disabled
        $this->assertTrue($container->hasParameter(self::ENABLED_PARAM));
        $this->assertFalse($container->getParameter(self::ENABLED_PARAM));
    }

    public function testThrowsWhenEnabledButListenerServiceAbsent(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter(self::ENABLED_PARAM, true);
        // No listener service registered

        $pass = new MaintenanceModeContractPass();

        $this->expectException(\LogicException::class);
        $pass->process($container);
    }

    public function testThrowsWhenListenerPriorityEqualsOrchestratorPriority(): void
    {
        $container = $this->makeContainerWithListenerAtPriority(TenantContextOrchestrator::PRIORITY);

        $pass = new MaintenanceModeContractPass();

        $this->expectException(\LogicException::class);
        $pass->process($container);
    }

    public function testThrowsWhenListenerPriorityIsAboveOrchestratorPriority(): void
    {
        $container = $this->makeContainerWithListenerAtPriority(25);

        $pass = new MaintenanceModeContractPass();

        $this->expectException(\LogicException::class);
        $pass->process($container);
    }

    public function testNoExceptionWhenListenerPriorityIsBelowOrchestratorPriority(): void
    {
        $container = $this->makeContainerWithListenerAtPriority(16);

        $pass = new MaintenanceModeContractPass();
        $pass->process($container);

        // No exception — priority 16 < 20 (TenantContextOrchestrator::PRIORITY)
        $this->assertTrue($container->hasDefinition(self::LISTENER_SERVICE_ID));
    }

    public function testExceptionMessageMentionsPriority(): void
    {
        $container = $this->makeContainerWithListenerAtPriority(TenantContextOrchestrator::PRIORITY);

        $pass = new MaintenanceModeContractPass();

        try {
            $pass->process($container);
            $this->fail('Expected LogicException was not thrown');
        } catch (\LogicException $e) {
            $this->assertStringContainsString(
                (string) TenantContextOrchestrator::PRIORITY,
                $e->getMessage(),
                'Exception message must mention TenantContextOrchestrator::PRIORITY value'
            );
        }
    }

    public function testNoExceptionWhenListenerPriorityIsZero(): void
    {
        // Priority 0 (default when tag attribute absent) is also < 20
        $container = $this->makeContainerWithListenerAtPriority(0);

        $pass = new MaintenanceModeContractPass();
        $pass->process($container);

        $this->assertTrue($container->hasDefinition(self::LISTENER_SERVICE_ID));
    }

    // -----------------------------------------------------------------------
    // Helper
    // -----------------------------------------------------------------------

    private function makeContainerWithListenerAtPriority(int $priority): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter(self::ENABLED_PARAM, true);

        $def = new Definition(\stdClass::class);
        $def->addTag('kernel.event_listener', [
            'event' => KernelEvents::REQUEST,
            'priority' => $priority,
        ]);
        $container->setDefinition(self::LISTENER_SERVICE_ID, $def);

        return $container;
    }
}
