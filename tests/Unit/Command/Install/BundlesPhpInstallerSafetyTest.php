<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Unit\Command\Install;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Tenancy\Bundle\Command\Install\BundlesPhpInstaller;
use Tenancy\Bundle\Command\Install\InstallStatus;

/**
 * Safety dimension test — proves the lint-failure restore path keeps the .bak
 * sidecar on disk AND restores bundles.php from .bak byte-for-byte.
 *
 * Mitigates threat T-INSTALL-02: ".bak lost during restore path". A rename-based
 * restore would FAIL the final assertion (the .bak file existence check after
 * restore) because rename consumes the source. Filesystem::copy() preserves it.
 */
final class BundlesPhpInstallerSafetyTest extends TestCase
{
    private const FIXTURES_DIR = __DIR__.'/../../../Fixtures/BundlesPhpCorpus';

    public function testLintFailureRestoresFromBackupAndKeepsBak(): void
    {
        $tmpPath = $this->copyFixtureToTmp('skeleton');
        $originalBytes = (string) file_get_contents($tmpPath);

        try {
            // Forced-failure lint runner — always returns passed: false.
            $forcedFailureLint = static fn (string $php, string $path): array => [
                'passed' => false,
                'error' => 'forced lint failure for test',
            ];

            $installer = new BundlesPhpInstaller(
                filesystem: new Filesystem(),
                lintRunner: $forcedFailureLint,
            );
            $result = $installer->install($tmpPath);

            self::assertSame(InstallStatus::LINT_FAILED_RESTORED, $result->status);
            self::assertNotNull($result->backupPath);
            self::assertFileExists((string) $result->backupPath);
            self::assertNotNull($result->errorMessage);
            self::assertStringContainsString('forced lint failure', (string) $result->errorMessage);

            // (1) bundles.php was restored byte-for-byte
            $restoredBytes = (string) file_get_contents($tmpPath);
            self::assertSame($originalBytes, $restoredBytes, 'bundles.php must be byte-equal to the original after lint-failure restore');
            self::assertStringNotContainsString('Tenancy\\Bundle\\TenancyBundle::class', $restoredBytes, 'the would-be-inserted entry must NOT be in the restored file');

            // (2) THE CRITICAL ASSERTION — .bak still exists on disk (T-INSTALL-02 mitigation)
            self::assertFileExists((string) $result->backupPath, '.bak must outlive the restore path (Filesystem::copy() is REQUIRED, NOT rename)');
            self::assertSame($originalBytes, (string) file_get_contents((string) $result->backupPath), '.bak content must be the pre-mutation bytes');

            // (3) Exactly one .bak sidecar exists
            $bakFiles = glob(dirname($tmpPath).'/*.bak.*') ?: [];
            self::assertCount(1, $bakFiles, 'exactly one .bak sidecar must exist after lint-failure restore');
        } finally {
            $this->cleanUp(dirname($tmpPath));
        }
    }

    public function testLintFailureWhenPhpBinaryAbsent(): void
    {
        // Simulate the "PhpExecutableFinder returned false" branch by injecting a forced runner.
        // The functional outcome (LINT_FAILED_RESTORED) is the same restore path.
        // Note: simulating PhpExecutableFinder returning false directly would require
        // process-environment manipulation, which is out of scope for a unit test;
        // the forced runner with a 'no PHP binary' error string proves the same restore path.
        $tmpPath = $this->copyFixtureToTmp('skeleton');

        try {
            $installer = new BundlesPhpInstaller(
                filesystem: new Filesystem(),
                lintRunner: static fn (string $php, string $path): array => ['passed' => false, 'error' => 'simulated: no PHP binary'],
            );
            $result = $installer->install($tmpPath);

            self::assertSame(InstallStatus::LINT_FAILED_RESTORED, $result->status);
            self::assertFileExists((string) $result->backupPath);
        } finally {
            $this->cleanUp(dirname($tmpPath));
        }
    }

    private function copyFixtureToTmp(string $slug): string
    {
        $tmpDir = sys_get_temp_dir().'/bundles_php_corpus_safety_'.uniqid('', true);
        mkdir($tmpDir, 0755, true);
        $src = self::FIXTURES_DIR.'/'.$slug.'/bundles.php';
        $dst = $tmpDir.'/bundles.php';
        copy($src, $dst);

        return $dst;
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
            if ($file instanceof \SplFileInfo) {
                $file->isDir() ? rmdir((string) $file->getRealPath()) : unlink((string) $file->getRealPath());
            }
        }
        rmdir($dir);
    }
}
