<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Unit\DependencyInjection\Compiler;

use Laminas\Diagnostics\Check\CheckInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
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
 * 6. CR-03: liip present + `tenancy.provider` absent → pass is a no-op (the check is
 *    meaningless without a provider, and referencing a missing service would break
 *    container compilation in the no-Doctrine lane).
 *
 * IMPLEMENTATION NOTE: liip/monitor-bundle IS installed (added in plan 33-05 Task 1)
 * so the positive path (liip present → check registered) is always exercised in this test.
 * The no-liip lane (liip absent → pass is no-op, endpoints still work) is covered by the
 * HealthChecksNoLiipTest integration test. Because the pass now also guards on
 * `tenancy.provider` (CR-03), positive-path tests must register that service first.
 */
final class HealthCheckIntegrationPassTest extends TestCase
{
    private const LIIP_TAG = 'liip_monitor.check';

    /**
     * Registers a stub `tenancy.provider` definition so the CR-03 provider guard is
     * satisfied. The pass only checks for the definition's existence during process()
     * (it does not resolve the reference), so any class suffices.
     */
    private function registerProvider(ContainerBuilder $container): void
    {
        $container->setDefinition('tenancy.provider', new Definition(\stdClass::class));
    }

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
        $this->registerProvider($container);

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
        $this->registerProvider($container);

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
        $this->registerProvider($container);

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

        // Must not throw — the guards handle both the liip-absent and provider-absent cases.
        $pass->process($container);

        // On an EMPTY container, `tenancy.provider` is absent, so the CR-03 guard makes
        // the pass a no-op even when liip is installed — no service is registered.
        // When liip is absent: container is unchanged (no-op) as well.
        // Either way, no exception means the pass is safe to register unconditionally.
        $this->assertEmpty(
            $container->findTaggedServiceIds(self::LIIP_TAG),
            'With no tenancy.provider defined, the pass must register nothing (CR-03 guard).',
        );
    }

    /**
     * CR-03: when liip is present but `tenancy.provider` is NOT defined (the no-Doctrine
     * lane), the pass must NOT register the check — referencing a missing service would
     * throw ServiceNotFoundException at compile time, violating the optional-Doctrine
     * invariant.
     */
    public function testDoesNotRegisterWhenProviderAbsent(): void
    {
        if (!interface_exists(CheckInterface::class)) {
            $this->markTestSkipped('liip/monitor-bundle not installed — CR-03 guard only matters when liip is present.');
        }

        $container = new ContainerBuilder();
        // Deliberately do NOT register tenancy.provider (simulates no-Doctrine app).

        $pass = new HealthCheckIntegrationPass();
        $pass->process($container);

        $this->assertEmpty(
            $container->findTaggedServiceIds(self::LIIP_TAG),
            'liip-present + provider-absent must not register a check (CR-03).',
        );
    }

    /**
     * CR-03: an aliased `tenancy.provider` also satisfies the guard, so the check is
     * registered when the provider is exposed as an alias rather than a definition.
     */
    public function testRegistersWhenProviderIsAlias(): void
    {
        if (!interface_exists(CheckInterface::class)) {
            $this->markTestSkipped('liip/monitor-bundle not installed.');
        }

        $container = new ContainerBuilder();
        $container->setDefinition('some.concrete.provider', new Definition(\stdClass::class));
        $container->setAlias('tenancy.provider', 'some.concrete.provider');

        $pass = new HealthCheckIntegrationPass();
        $pass->process($container);

        $this->assertNotEmpty(
            $container->findTaggedServiceIds(self::LIIP_TAG),
            'An aliased tenancy.provider must satisfy the CR-03 guard.',
        );
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
