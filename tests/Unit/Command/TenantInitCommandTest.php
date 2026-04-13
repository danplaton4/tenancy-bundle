<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Unit\Command;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Tenancy\Bundle\Command\TenantInitCommand;

final class TenantInitCommandTest extends TestCase
{
    public function testCreatesConfigFile(): void
    {
        $projectDir = $this->createTempDir();

        try {
            $command = new TenantInitCommand($projectDir);
            $tester = new CommandTester($command);
            $exitCode = $tester->execute([]);

            $this->assertSame(Command::SUCCESS, $exitCode);

            $filePath = $projectDir.'/config/packages/tenancy.yaml';
            $this->assertTrue(file_exists($filePath));

            $content = (string) file_get_contents($filePath);
            $this->assertStringContainsString('tenancy:', $content);
            $this->assertStringContainsString('# Tenancy Bundle Configuration', $content);
            $this->assertStringContainsString('strict_mode', $content);
            $this->assertStringContainsString('database_per_tenant', $content);

            $this->assertStringContainsString('Created config/packages/tenancy.yaml', $tester->getDisplay());
        } finally {
            $this->cleanUp($projectDir);
        }
    }

    public function testExistingFileWithoutForceReturnsFAILURE(): void
    {
        $projectDir = $this->createTempDir();

        try {
            $configDir = $projectDir.'/config/packages';
            mkdir($configDir, 0755, true);
            $filePath = $configDir.'/tenancy.yaml';
            file_put_contents($filePath, 'existing content');

            $command = new TenantInitCommand($projectDir);
            $tester = new CommandTester($command);
            $exitCode = $tester->execute([]);

            $this->assertSame(Command::FAILURE, $exitCode);
            $this->assertStringContainsString('already exists', $tester->getDisplay());
            $this->assertSame('existing content', file_get_contents($filePath));
        } finally {
            $this->cleanUp($projectDir);
        }
    }

    public function testExistingFileWithForceOverwrites(): void
    {
        $projectDir = $this->createTempDir();

        try {
            $configDir = $projectDir.'/config/packages';
            mkdir($configDir, 0755, true);
            $filePath = $configDir.'/tenancy.yaml';
            file_put_contents($filePath, 'old content');

            $command = new TenantInitCommand($projectDir);
            $tester = new CommandTester($command);
            $exitCode = $tester->execute(['--force' => true]);

            $this->assertSame(Command::SUCCESS, $exitCode);

            $content = (string) file_get_contents($filePath);
            $this->assertNotSame('old content', $content);
            $this->assertStringContainsString('tenancy:', $content);
            $this->assertStringContainsString('Overwriting', $tester->getDisplay());
        } finally {
            $this->cleanUp($projectDir);
        }
    }

    public function testDoctrineDetectionOutputsRecommendation(): void
    {
        $projectDir = $this->createTempDir();

        try {
            $command = new TenantInitCommand($projectDir);
            $tester = new CommandTester($command);
            $tester->execute([]);

            // doctrine/orm IS installed in dev dependencies so class_exists returns true
            $this->assertStringContainsString('Doctrine ORM detected', $tester->getDisplay());
            $this->assertStringContainsString('database_per_tenant', $tester->getDisplay());
        } finally {
            $this->cleanUp($projectDir);
        }
    }

    public function testNextStepsArePrinted(): void
    {
        $projectDir = $this->createTempDir();

        try {
            $command = new TenantInitCommand($projectDir);
            $tester = new CommandTester($command);
            $tester->execute([]);

            $display = $tester->getDisplay();
            $this->assertStringContainsString('Next Steps', $display);
            $this->assertStringContainsString('TenantInterface', $display);
            $this->assertStringContainsString('doctrine:schema:update', $display);
        } finally {
            $this->cleanUp($projectDir);
        }
    }

    public function testCreatesDirectoryIfMissing(): void
    {
        $projectDir = $this->createTempDir();

        try {
            // config/packages does NOT exist yet — only projectDir is created
            $command = new TenantInitCommand($projectDir);
            $tester = new CommandTester($command);
            $tester->execute([]);

            $this->assertTrue(is_dir($projectDir.'/config/packages'));
            $this->assertTrue(file_exists($projectDir.'/config/packages/tenancy.yaml'));
        } finally {
            $this->cleanUp($projectDir);
        }
    }

    private function createTempDir(): string
    {
        $dir = sys_get_temp_dir().'/tenancy_init_test_'.uniqid('', true);
        mkdir($dir, 0755, true);

        return $dir;
    }

    private function cleanUp(string $dir): void
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
