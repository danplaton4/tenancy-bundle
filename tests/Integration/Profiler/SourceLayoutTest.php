<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Integration\Profiler;

use PHPUnit\Framework\TestCase;

/**
 * Source-layout invariant — defends against accidental "fix" where someone collapses
 * config/services_dev.php into config/services.php "to centralize service registration".
 *
 * Doing so would defeat the kernel.debug compile-out (RESEARCH Pitfall 8), because the
 * profiler service references would then be parsed unconditionally at every container build,
 * regardless of debug mode. The runtime test (TenantDataCollectorCompileOutTest) catches the
 * outcome; this static test catches the cause.
 *
 * No kernel boot — pure file content inspection.
 *
 * Phase 19 — T-19-10 mitigation, complements DX-02 acceptance line 6.
 */
final class SourceLayoutTest extends TestCase
{
    public function testProfilerClassesAreNotReferencedInProductionServicesFile(): void
    {
        $path = __DIR__.'/../../../config/services.php';
        self::assertFileExists($path);
        $contents = file_get_contents($path);
        self::assertIsString($contents);

        self::assertStringNotContainsString(
            'TenantDataCollector',
            $contents,
            'config/services.php MUST NOT reference TenantDataCollector — profiler services live in config/services_dev.php'
        );
        self::assertStringNotContainsString(
            'TenantProfilerStash',
            $contents,
            'config/services.php MUST NOT reference TenantProfilerStash — profiler services live in config/services_dev.php'
        );
        self::assertStringNotContainsString(
            'Tenancy\\Bundle\\Profiler\\',
            $contents,
            'config/services.php MUST NOT reference the Tenancy\\Bundle\\Profiler namespace'
        );
        self::assertStringNotContainsString(
            'services_dev',
            $contents,
            'config/services.php MUST NOT import services_dev — the import happens in TenancyBundle::loadExtension() under a kernel.debug guard'
        );
    }

    public function testProfilerClassesAreReferencedInDevServicesFile(): void
    {
        $path = __DIR__.'/../../../config/services_dev.php';
        self::assertFileExists($path, 'config/services_dev.php MUST exist (created by Plan 04)');
        $contents = file_get_contents($path);
        self::assertIsString($contents);

        self::assertStringContainsString('TenantDataCollector', $contents);
        self::assertStringContainsString('TenantProfilerStash', $contents);
        self::assertStringContainsString("'data_collector'", $contents);
        self::assertStringContainsString("'id' => 'tenancy'", $contents);
        self::assertStringContainsString("'template' => '@Tenancy/Collector/tenant.html.twig'", $contents);
    }

    public function testTenancyBundleGuardsServicesDevImportWithKernelDebug(): void
    {
        $path = __DIR__.'/../../../src/TenancyBundle.php';
        self::assertFileExists($path);
        $contents = file_get_contents($path);
        self::assertIsString($contents);

        self::assertStringContainsString(
            "\$builder->getParameter('kernel.debug')",
            $contents,
            'TenancyBundle MUST guard the services_dev.php import with $builder->getParameter(kernel.debug)'
        );
        self::assertStringContainsString(
            "import('../config/services_dev.php')",
            $contents,
            'TenancyBundle MUST conditionally import config/services_dev.php'
        );
    }
}
