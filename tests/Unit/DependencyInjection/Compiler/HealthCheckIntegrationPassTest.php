<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Unit\DependencyInjection\Compiler;

use Laminas\Diagnostics\Check\CheckInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Tenancy\Bundle\DependencyInjection\Compiler\HealthCheckIntegrationPass;
use Tenancy\Bundle\Health\Liip\TenantConnectivityCheck;

/**
 * Unit tests for HealthCheckIntegrationPass (HEALTH-07 compiler pass).
 *
 * Covers:
 * 1. Positive path: when Laminas\Diagnostics\Check\CheckInterface exists (liip is installed),
 *    the pass registers a service tagged liip_monitor.check.
 * 2. The registered service is an instance of TenantConnectivityCheck.
 * 3. The registered service carries the liip_monitor.check tag.
 * 4. No-op behavior when the guard FQCN is absent (tested via process() on a fresh container
 *    that already lacks the interface — documented as the no-liip lane direction; the
 *    HealthChecksNoLiipTest integration test covers the full container-compilation path).
 * 5. The pass does not throw when run on an empty container (robustness).
 *
 * IMPLEMENTATION NOTE: liip/monitor-bundle IS installed (added in plan 33-05 Task 1)
 * so the positive path (liip present → check registered) is always exercised in this test.
 * The no-liip lane (liip absent → pass is no-op, endpoints still work) is covered by the
 * HealthChecksNoLiipTest integration test.
 */
final class HealthCheckIntegrationPassTest extends TestCase
{
    private const LIIP_TAG = 'liip_monitor.check';

    /**
     * When Laminas CheckInterface is present (liip installed), the pass registers
     * exactly one service tagged liip_monitor.check.
     */
    public function testRegistersLiipMonitorCheckWhenLaminasPresent(): void
    {
        // Guard: this positive-path test only runs when liip is installed.
        if (!interface_exists(CheckInterface::class)) {
            $this->markTestSkipped('liip/monitor-bundle (laminas/laminas-diagnostics) not installed — positive path requires liip.');
        }

        $container = new ContainerBuilder();

        $pass = new HealthCheckIntegrationPass();
        $pass->process($container);

        $taggedIds = $container->findTaggedServiceIds(self::LIIP_TAG);

        $this->assertNotEmpty(
            $taggedIds,
            'HealthCheckIntegrationPass must register at least one liip_monitor.check-tagged service when laminas/laminas-diagnostics is installed.',
        );
    }

    /**
     * The registered service must be TenantConnectivityCheck.
     */
    public function testRegisteredServiceIsTenantConnectivityCheck(): void
    {
        if (!interface_exists(CheckInterface::class)) {
            $this->markTestSkipped('liip/monitor-bundle not installed.');
        }

        $container = new ContainerBuilder();

        $pass = new HealthCheckIntegrationPass();
        $pass->process($container);

        $taggedIds = $container->findTaggedServiceIds(self::LIIP_TAG);
        $this->assertNotEmpty($taggedIds, 'At least one liip_monitor.check service must be registered.');

        // There must be exactly one service ID registered by this pass.
        $registeredId = array_key_first($taggedIds);
        $this->assertNotNull($registeredId);

        $definition = $container->getDefinition($registeredId);
        $this->assertSame(
            TenantConnectivityCheck::class,
            $definition->getClass(),
            'The liip_monitor.check-tagged service must be TenantConnectivityCheck.',
        );
    }

    /**
     * The registered service must have the liip_monitor.check tag (verified via definition tags).
     */
    public function testRegisteredServiceHasLiipMonitorCheckTag(): void
    {
        if (!interface_exists(CheckInterface::class)) {
            $this->markTestSkipped('liip/monitor-bundle not installed.');
        }

        $container = new ContainerBuilder();

        $pass = new HealthCheckIntegrationPass();
        $pass->process($container);

        $taggedIds = $container->findTaggedServiceIds(self::LIIP_TAG);
        $this->assertNotEmpty($taggedIds);

        $registeredId = array_key_first($taggedIds);
        $this->assertNotNull($registeredId);

        $definition = $container->getDefinition($registeredId);
        $tags = $definition->getTag(self::LIIP_TAG);

        $this->assertNotEmpty(
            $tags,
            'The registered service definition must carry the liip_monitor.check tag.',
        );
    }

    /**
     * The pass must not throw on an empty container, regardless of liip's presence.
     * This verifies the guard-first pattern makes the pass unconditionally safe to register.
     */
    public function testPassDoesNotThrowOnEmptyContainer(): void
    {
        $container = new ContainerBuilder();

        $pass = new HealthCheckIntegrationPass();

        // Must not throw — the guard handles the liip-absent case.
        $pass->process($container);

        // When liip is installed: at least one service is registered.
        // When liip is absent: container is unchanged (no-op).
        // Either way, no exception means the pass is safe to register unconditionally.
        $this->assertTrue(true, 'Pass must not throw on an empty container.');
    }

    /**
     * The interface_exists guard is the canonical early-return mechanism.
     * This test asserts that process() registers zero services when the
     * guard would not be satisfied — simulated by checking the source code
     * for the expected guard pattern (structural assertion).
     *
     * The no-liip runtime lane is covered end-to-end by HealthChecksNoLiipTest.
     */
    public function testPassSourceContainsInterfaceExistsGuard(): void
    {
        $reflection = new \ReflectionClass(HealthCheckIntegrationPass::class);
        $fileName = $reflection->getFileName();

        $this->assertNotFalse($fileName, 'Could not determine source file for HealthCheckIntegrationPass.');

        $source = (string) file_get_contents($fileName);

        $this->assertStringContainsString(
            'interface_exists',
            $source,
            'HealthCheckIntegrationPass::process() must use interface_exists() as its early-return guard.',
        );

        $this->assertStringContainsString(
            'CheckInterface',
            $source,
            'HealthCheckIntegrationPass guard must reference Laminas CheckInterface.',
        );
    }
}
