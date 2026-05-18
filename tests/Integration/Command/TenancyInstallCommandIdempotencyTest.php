<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Integration\Command;

use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Tenancy\Bundle\Tests\Integration\Command\Support\InstallCommandTestKernel;

/**
 * Idempotency proof for tenancy:install (DX-06 acceptance criterion 2,
 * CONTEXT.md D-21, must-have #11).
 *
 * Three consecutive invocations against the skeleton fixture:
 *   Run 1 — writes; one .bak created; bundles.php matches expected baseline.
 *   Run 2 — detects ALREADY_REGISTERED; no write; .bak count still 1.
 *   Run 3 — same as Run 2.
 *
 * Each run uses a fresh kernel boot (simulating three independent invocations).
 */
final class TenancyInstallCommandIdempotencyTest extends TestCase
{
    private string $tmpDir = '';

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir().'/tenancy_install_idempotency_'.uniqid('', true);
        mkdir($this->tmpDir.'/config/packages', 0755, true);
        // Resolve symlinks (macOS: /var/... → /private/var/...) so path comparisons are stable.
        $this->tmpDir = (string) realpath($this->tmpDir);

        $src = __DIR__.'/../../Fixtures/BundlesPhpCorpus/skeleton/bundles.php';
        copy($src, $this->tmpDir.'/config/bundles.php');
    }

    protected function tearDown(): void
    {
        $this->cleanUp($this->tmpDir);
    }

    public function testThreeConsecutiveRunsLeaveBytesIdenticalAfterFirstWrite(): void
    {
        $expectedBaseline = __DIR__.'/../../Fixtures/BundlesPhpCorpus/.expected/skeleton/bundles.php';
        $expectedBytes = (string) file_get_contents($expectedBaseline);

        // ----- Run 1 -----
        $exit1 = $this->runInstall();
        self::assertSame(Command::SUCCESS, $exit1, 'Run 1 must succeed');

        $afterRun1 = (string) file_get_contents($this->tmpDir.'/config/bundles.php');
        self::assertSame($expectedBytes, $afterRun1, 'Run 1: bundles.php must match expected baseline');

        $bakFilesAfter1 = glob($this->tmpDir.'/config/bundles.php.bak.*') ?: [];
        self::assertCount(1, $bakFilesAfter1, 'Run 1: exactly one .bak should exist');
        $firstBakPath = $bakFilesAfter1[0];

        // ----- Run 2 -----
        $exit2 = $this->runInstall();
        self::assertSame(Command::SUCCESS, $exit2, 'Run 2 must succeed (already registered)');

        $afterRun2 = (string) file_get_contents($this->tmpDir.'/config/bundles.php');
        self::assertSame($afterRun1, $afterRun2, 'Run 2: bundles.php must be byte-identical to after run 1 (idempotent)');

        $bakFilesAfter2 = glob($this->tmpDir.'/config/bundles.php.bak.*') ?: [];
        self::assertCount(1, $bakFilesAfter2, 'Run 2: .bak count must STILL be 1 (no new backup created when no write happens)');
        self::assertSame($firstBakPath, $bakFilesAfter2[0], 'Run 2: the existing .bak filename must be unchanged');

        // ----- Run 3 -----
        $exit3 = $this->runInstall();
        self::assertSame(Command::SUCCESS, $exit3, 'Run 3 must succeed (already registered)');

        $afterRun3 = (string) file_get_contents($this->tmpDir.'/config/bundles.php');
        self::assertSame($afterRun1, $afterRun3, 'Run 3: bundles.php must be byte-identical to after run 1');

        $bakFilesAfter3 = glob($this->tmpDir.'/config/bundles.php.bak.*') ?: [];
        self::assertCount(1, $bakFilesAfter3, 'Run 3: .bak count must STILL be 1');
    }

    private function runInstall(): int
    {
        $kernel = new InstallCommandTestKernel($this->tmpDir);
        $kernel->boot();
        try {
            $app = new Application($kernel);
            $app->setAutoExit(false);
            $tester = new CommandTester($app->find('tenancy:install'));

            // Pass --force so tenancy:init does not fail on the second/third run when tenancy.yaml already exists.
            // The bundles.php idempotency check is what this test asserts; the delegation surface is exercised in
            // the main integration test.
            return $tester->execute(['--force' => true], ['interactive' => false]);
        } finally {
            $kernel->shutdown();
        }
    }

    private function cleanUp(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        /** @var \RecursiveIteratorIterator<\RecursiveDirectoryIterator> $iterator */
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo) {
                $realPath = $file->getRealPath();
                if (false !== $realPath) {
                    $file->isDir() ? rmdir($realPath) : unlink($realPath);
                }
            }
        }
        rmdir($dir);
    }
}
