<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Integration\Command;

use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Tenancy\Bundle\Command\TenancyInstallCommand;
use Tenancy\Bundle\Tests\Integration\Command\Support\InstallCommandTestKernel;

/**
 * Integration test for the full tenancy:install pipeline:
 *   DI → BundlesPhpInstaller AST detect → write → .bak → php -l → tenancy:init delegation.
 *
 * Each test boots a fresh kernel rooted at a tmp directory carrying a fresh fixture.
 */
final class TenancyInstallCommandIntegrationTest extends TestCase
{
    private string $tmpDir = '';
    private InstallCommandTestKernel $kernel;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir().'/tenancy_install_integration_'.uniqid('', true);
        mkdir($this->tmpDir.'/config/packages', 0755, true);
        // Resolve symlinks (macOS: /var/... → /private/var/...) so path comparisons are stable.
        $this->tmpDir = (string) realpath($this->tmpDir);
    }

    protected function tearDown(): void
    {
        if (isset($this->kernel)) {
            $this->kernel->shutdown();
        }
        $this->cleanUp($this->tmpDir);
    }

    public function testServiceIsRegistered(): void
    {
        $this->copyFixture('skeleton');
        $this->bootKernel();
        self::assertTrue($this->kernel->getContainer()->has('tenancy.command.install'));
    }

    public function testServiceIsTenancyInstallCommandInstance(): void
    {
        $this->copyFixture('skeleton');
        $this->bootKernel();
        self::assertInstanceOf(
            TenancyInstallCommand::class,
            $this->kernel->getContainer()->get('tenancy.command.install')
        );
    }

    public function testServiceReceivesProjectDirFromKernel(): void
    {
        $this->copyFixture('skeleton');
        $this->bootKernel();

        $command = $this->kernel->getContainer()->get('tenancy.command.install');
        self::assertInstanceOf(TenancyInstallCommand::class, $command);

        $reflection = new \ReflectionProperty(TenancyInstallCommand::class, 'projectDir');
        self::assertSame($this->tmpDir, $reflection->getValue($command));
    }

    public function testEndToEndAgainstSkeletonFixture(): void
    {
        $this->copyFixture('skeleton');
        $this->bootKernel();

        $app = new Application($this->kernel);
        $app->setAutoExit(false);
        $tester = new CommandTester($app->find('tenancy:install'));

        $exit = $tester->execute([], ['interactive' => false]);

        self::assertSame(
            Command::SUCCESS,
            $exit,
            'tenancy:install must exit SUCCESS on the skeleton fixture. Display:'."\n".$tester->getDisplay()
        );

        // (i) bundles.php matches the expected baseline byte-for-byte
        $expected = __DIR__.'/../../Fixtures/BundlesPhpCorpus/.expected/skeleton/bundles.php';
        self::assertStringEqualsFile($expected, (string) file_get_contents($this->tmpDir.'/config/bundles.php'));

        // (ii) a .bak with the timestamp suffix exists in config/
        $bakFiles = glob($this->tmpDir.'/config/bundles.php.bak.*') ?: [];
        self::assertCount(1, $bakFiles, 'exactly one .bak.YYYYMMDD-HHMMSS file must exist after a single run');
        self::assertMatchesRegularExpression('/\.bak\.\d{8}-\d{6}$/', $bakFiles[0]);

        // (iii) tenancy:init was delegated and produced tenancy.yaml
        self::assertFileExists($this->tmpDir.'/config/packages/tenancy.yaml', 'tenancy.yaml must be created by the delegated tenancy:init invocation');

        // (iv) success-transcript indicators
        $display = $tester->getDisplay();
        self::assertStringContainsString('Registered Tenancy', $display);
        self::assertStringContainsString('Next steps', $display);
    }

    public function testDryRunDoesNotWrite(): void
    {
        $this->copyFixture('skeleton');
        $this->bootKernel();
        $originalBytes = (string) file_get_contents($this->tmpDir.'/config/bundles.php');

        $app = new Application($this->kernel);
        $app->setAutoExit(false);
        $tester = new CommandTester($app->find('tenancy:install'));

        $exit = $tester->execute(['--dry-run' => true], ['interactive' => false]);
        self::assertSame(Command::SUCCESS, $exit);

        self::assertSame($originalBytes, (string) file_get_contents($this->tmpDir.'/config/bundles.php'), 'dry-run must NOT touch bundles.php');
        self::assertEmpty(glob($this->tmpDir.'/config/bundles.php.bak.*') ?: [], 'dry-run must NOT create a .bak');
        self::assertFileDoesNotExist($this->tmpDir.'/config/packages/tenancy.yaml', 'dry-run must NOT invoke tenancy:init');

        self::assertStringContainsString('Dry-run', $tester->getDisplay());
    }

    public function testRefusalAgainstDddOverrideFixture(): void
    {
        $this->copyFixture('ddd-override');
        $this->bootKernel();
        $originalBytes = (string) file_get_contents($this->tmpDir.'/config/bundles.php');

        $app = new Application($this->kernel);
        $app->setAutoExit(false);
        $tester = new CommandTester($app->find('tenancy:install'));

        $exit = $tester->execute([], ['interactive' => false]);
        self::assertSame(Command::SUCCESS, $exit, 'Refusal is a clean exit, not a tool failure');

        self::assertSame($originalBytes, (string) file_get_contents($this->tmpDir.'/config/bundles.php'), 'non-standard refusal must NOT touch the file');
        self::assertEmpty(glob($this->tmpDir.'/config/bundles.php.bak.*') ?: [], 'refusal must NOT create a .bak');
        self::assertFileDoesNotExist($this->tmpDir.'/config/packages/tenancy.yaml', 'refusal must NOT invoke tenancy:init');

        $display = $tester->getDisplay();
        self::assertStringContainsString("Tenancy\\Bundle\\TenancyBundle::class => ['all' => true]", $display);
        self::assertStringContainsString('non-standard shape', $display);
    }

    private function copyFixture(string $slug): void
    {
        $src = __DIR__.'/../../Fixtures/BundlesPhpCorpus/'.$slug.'/bundles.php';
        $dst = $this->tmpDir.'/config/bundles.php';
        copy($src, $dst);
    }

    private function bootKernel(): void
    {
        $this->kernel = new InstallCommandTestKernel($this->tmpDir);
        $this->kernel->boot();
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
