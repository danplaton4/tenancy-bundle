<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Integration\Command;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Tenancy\Bundle\Command\TenantInitCommand;

/**
 * Integration tests for the doctrine.yaml sample emitted by tenancy:init.
 *
 * These lock in the FIX-04 acceptance criteria (Phase 15-04): the command's
 * printNextSteps() must emit a MySQL-driver sample (pdo_mysql), split entity
 * managers (landlord + tenant), and a driver-family-match callout — and must
 * NOT emit legacy placeholder forms ("sqlite://" URL, wrapper_class).
 */
final class TenantInitCommandYamlContentTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir().'/tenancy_init_yaml_test_'.uniqid('', true);
        mkdir($this->projectDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeRecursive($this->projectDir);
    }

    public function testPrintsNextStepsWithSampleDoctrineYamlUsingPdoMysqlDriver(): void
    {
        $display = $this->runInitCommand();

        self::assertStringContainsString('driver: pdo_mysql', $display, 'Sample must use pdo_mysql driver');
        self::assertStringContainsString('placeholder_tenant', $display, 'Sample must use placeholder_tenant as dbname');
        self::assertStringContainsString('entity_managers', $display, 'Sample must show dual-EM split');
        self::assertStringContainsString('landlord:', $display, 'Sample must define a landlord connection');
        self::assertStringContainsString('tenant:', $display, 'Sample must define a tenant connection');
    }

    public function testPrintsDriverFamilyCallout(): void
    {
        $display = $this->runInitCommand();

        self::assertStringContainsString('driver family', $display, 'Driver-family callout must be present');
        self::assertStringContainsString('TenantDriverMiddleware', $display, 'Callout must reference TenantDriverMiddleware');
    }

    public function testDoesNotEmitLegacyPlaceholders(): void
    {
        $display = $this->runInitCommand();

        self::assertStringNotContainsString('wrapper_class', $display, 'Must not emit v0.1 wrapper_class YAML form');
        self::assertStringNotContainsString('sqlite://', $display, 'Must not emit v0.1 sqlite:// URL form as MySQL placeholder');
    }

    public function testSampleYamlContainsOrmMappingsSection(): void
    {
        $display = $this->runInitCommand();

        self::assertStringContainsString('mappings:', $display, 'Sample must include ORM mappings section');
        self::assertStringContainsString('App\Entity\Landlord', $display, 'Sample must show landlord prefix');
        self::assertStringContainsString('App\Entity\Tenant', $display, 'Sample must show tenant prefix');
    }

    private function runInitCommand(): string
    {
        $command = new TenantInitCommand($this->projectDir);
        $tester = new CommandTester($command);
        $tester->execute([]);

        return $tester->getDisplay();
    }

    private function removeRecursive(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $file) {
            if ($file->isDir()) {
                rmdir($file->getRealPath());
            } else {
                unlink($file->getRealPath());
            }
        }

        rmdir($dir);
    }
}
