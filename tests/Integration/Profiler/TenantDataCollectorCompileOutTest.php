<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Integration\Profiler;

use PHPUnit\Framework\TestCase;
use Tenancy\Bundle\Profiler\TenantDataCollector;
use Tenancy\Bundle\Profiler\TenantProfilerStash;
use Tenancy\Bundle\Tests\Integration\TestKernel;

/**
 * Verifies the kernel.debug guard in TenancyBundle::loadExtension() actually compiles out
 * the Profiler services from the production container.
 *
 * Phase 19 — DX-02 acceptance line 6: "service registered ONLY when kernel.debug = true".
 *
 * Two TestKernel instances are booted (one per test class lifetime, in setUpBeforeClass) with
 * different (env, debug) pairs. TestKernel::getCacheDir() keys cache by env, so they don't
 * collide. Class-level boot avoids PHPUnit's risky-test warning for the Symfony ErrorHandler
 * which registers a process-global exception handler at kernel.boot.
 */
final class TenantDataCollectorCompileOutTest extends TestCase
{
    private static ?TestKernel $debugKernel = null;
    private static ?TestKernel $prodKernel = null;

    public static function setUpBeforeClass(): void
    {
        self::$debugKernel = new TestKernel('test', true);
        self::$debugKernel->boot();

        self::$prodKernel = new TestKernel('prod', false);
        self::$prodKernel->boot();
    }

    public static function tearDownAfterClass(): void
    {
        self::$debugKernel?->shutdown();
        self::$prodKernel?->shutdown();
        self::$debugKernel = null;
        self::$prodKernel = null;
    }

    public function testCollectorIsRegisteredWhenDebugTrue(): void
    {
        self::assertNotNull(self::$debugKernel);
        $container = self::$debugKernel->getContainer();

        self::assertTrue(
            $container->has(TenantDataCollector::class),
            'TenantDataCollector MUST be registered when kernel.debug=true'
        );
        self::assertTrue(
            $container->has(TenantProfilerStash::class),
            'TenantProfilerStash MUST be registered when kernel.debug=true'
        );
    }

    public function testCollectorIsAbsentWhenDebugFalse(): void
    {
        self::assertNotNull(self::$prodKernel);
        $container = self::$prodKernel->getContainer();

        self::assertFalse(
            $container->has(TenantDataCollector::class),
            'TenantDataCollector MUST NOT be registered when kernel.debug=false (compile-out failed)'
        );
        self::assertFalse(
            $container->has(TenantProfilerStash::class),
            'TenantProfilerStash MUST NOT be registered when kernel.debug=false (compile-out failed)'
        );
    }

    public function testDataCollectorTagIsPresentWhenDebugTrue(): void
    {
        self::assertNotNull(self::$debugKernel);
        $container = self::$debugKernel->getContainer();

        self::assertTrue($container->has(TenantDataCollector::class));
        $collector = $container->get(TenantDataCollector::class);
        self::assertInstanceOf(TenantDataCollector::class, $collector);
        self::assertSame('tenancy', $collector->getName());
        self::assertSame('@Tenancy/Collector/tenant.html.twig', $collector::getTemplate());
    }
}
