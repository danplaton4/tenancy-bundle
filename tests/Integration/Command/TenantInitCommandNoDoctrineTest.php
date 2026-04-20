<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Integration\Command;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Tenancy\Bundle\Command\TenantInitCommand;

/**
 * Exercises the non-Doctrine onboarding branch of `tenancy:init`.
 *
 * Phase 12 shipped with a human-verification gap: the command's Doctrine-absent
 * path could not be tested because tests run in an environment where doctrine/orm
 * is always installed. Phase 15 (v0.2) extracted `detectDoctrine()` into a
 * protected seam so this test can override it and cover the branch.
 */
final class TenantInitCommandNoDoctrineTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir().'/tenancy_init_no_doctrine_'.uniqid('', true);
        mkdir($this->projectDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $configDir = $this->projectDir.'/config/packages';
        if (is_dir($configDir)) {
            @unlink($configDir.'/tenancy.yaml');
            @rmdir($configDir);
            @rmdir($this->projectDir.'/config');
        }
        @rmdir($this->projectDir);
    }

    public function testEmitsSharedDbRecommendationWhenDoctrineAbsent(): void
    {
        $command = new class($this->projectDir) extends TenantInitCommand {
            protected function detectDoctrine(): bool
            {
                return false;
            }
        };

        $tester = new CommandTester($command);
        $status = $tester->execute([]);

        $this->assertSame(0, $status);
        $output = $tester->getDisplay();

        $this->assertStringContainsString('Doctrine ORM not detected', $output);
        $this->assertStringContainsString('recommended driver: shared_db', $output);
        $this->assertStringContainsString('Install doctrine/orm', $output);

        $yaml = file_get_contents($this->projectDir.'/config/packages/tenancy.yaml');
        $this->assertIsString($yaml);
        // Doctrine-absent YAML leaves database-per-tenant driver commented out.
        $this->assertStringContainsString('# driver: database_per_tenant', $yaml);
    }

    public function testEmitsDatabasePerTenantRecommendationWhenDoctrinePresent(): void
    {
        $command = new class($this->projectDir) extends TenantInitCommand {
            protected function detectDoctrine(): bool
            {
                return true;
            }
        };

        $tester = new CommandTester($command);
        $status = $tester->execute([]);

        $this->assertSame(0, $status);
        $output = $tester->getDisplay();

        $this->assertStringContainsString('Doctrine ORM detected', $output);
        $this->assertStringContainsString('recommended driver: database_per_tenant', $output);

        $yaml = file_get_contents($this->projectDir.'/config/packages/tenancy.yaml');
        $this->assertIsString($yaml);
        $this->assertStringContainsString('driver: database_per_tenant', $yaml);
        $this->assertStringNotContainsString('# driver: database_per_tenant', $yaml);
    }
}
