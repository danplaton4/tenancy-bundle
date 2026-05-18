<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Unit\Command;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Tenancy\Bundle\Command\Install\BundlesPhpInstallerInterface;
use Tenancy\Bundle\Command\Install\InstallResult;
use Tenancy\Bundle\Command\TenancyInstallCommand;

final class TenancyInstallCommandTest extends TestCase
{
    private string $projectDir = '';

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir().'/tenancy_install_test_'.uniqid('', true);
        mkdir($this->projectDir.'/config/packages', 0755, true);
    }

    protected function tearDown(): void
    {
        $this->cleanUp($this->projectDir);
    }

    public function testForceAndDryRunMutuallyExclusiveReturnsInvalid(): void
    {
        // mutual-exclusion check happens BEFORE installer is invoked — installer must never be called
        $installer = new BundlesPhpInstallerStub(InstallResult::alreadyRegistered());
        $installer->expectNeverCalled = true;
        $tester = $this->buildTester($installer);

        $exit = $tester->execute(['--force' => true, '--dry-run' => true]);
        self::assertSame(Command::INVALID, $exit);
        self::assertStringContainsString('mutually exclusive', $tester->getDisplay());
        self::assertFalse($installer->wasCalled, 'installer->install() must NOT be called when mutual-exclusion guard triggers');
    }

    public function testWroteOutcomeDelegatesToTenancyInitWithForceFalse(): void
    {
        $installer = new BundlesPhpInstallerStub(
            InstallResult::wrote($this->projectDir.'/config/bundles.php.bak.20260515-120000')
        );

        $tenancyInit = $this->buildSpyTenancyInit(Command::SUCCESS);
        $tester = $this->buildTester($installer, $tenancyInit);

        $exit = $tester->execute([]);
        self::assertSame(Command::SUCCESS, $exit);
        self::assertSame(['--force' => false], $tenancyInit->lastInputParams);
        self::assertStringContainsString('Registered Tenancy', $tester->getDisplay());
        self::assertStringContainsString('Next steps', $tester->getDisplay());
    }

    public function testWroteWithForceFlagPropagatesToTenancyInit(): void
    {
        $installer = new BundlesPhpInstallerStub(
            InstallResult::wrote('/tmp/bundles.php.bak.20260515-120000')
        );

        $tenancyInit = $this->buildSpyTenancyInit(Command::SUCCESS);
        $tester = $this->buildTester($installer, $tenancyInit);

        $exit = $tester->execute(['--force' => true]);
        self::assertSame(Command::SUCCESS, $exit);
        self::assertSame(['--force' => true], $tenancyInit->lastInputParams);
    }

    public function testAlreadyRegisteredDelegatesToTenancyInit(): void
    {
        $installer = new BundlesPhpInstallerStub(InstallResult::alreadyRegistered());

        $tenancyInit = $this->buildSpyTenancyInit(Command::SUCCESS);
        $tester = $this->buildTester($installer, $tenancyInit);

        $exit = $tester->execute([]);
        self::assertSame(Command::SUCCESS, $exit);
        self::assertStringContainsString('already registered', $tester->getDisplay());
        self::assertNotNull($tenancyInit->lastInputParams, 'tenancy:init must still be called when bundle is already registered');
    }

    public function testRefusedNonStandardExitsSuccessAndPrintsManualSnippet(): void
    {
        $installer = new BundlesPhpInstallerStub(
            InstallResult::refusedNonStandard('top-level statement count > 1')
        );

        $tenancyInit = $this->buildSpyTenancyInit(Command::SUCCESS);
        $tester = $this->buildTester($installer, $tenancyInit);

        $exit = $tester->execute([]);
        self::assertSame(Command::SUCCESS, $exit, 'Refusal is a clean exit, not a tool failure');
        self::assertNull($tenancyInit->lastInputParams, 'tenancy:init MUST NOT be called when bundles.php was not mutated');
        self::assertStringContainsString("Tenancy\\Bundle\\TenancyBundle::class => ['all' => true]", $tester->getDisplay());
        self::assertStringContainsString('non-standard shape', $tester->getDisplay());
    }

    public function testLintFailedRestoredExitsFailureWithBackupPath(): void
    {
        $installer = new BundlesPhpInstallerStub(
            InstallResult::lintFailedRestored(
                '/tmp/bundles.php.bak.20260515-120000',
                'PHP Parse error: syntax error, unexpected end of file'
            )
        );

        $tenancyInit = $this->buildSpyTenancyInit(Command::SUCCESS);
        $tester = $this->buildTester($installer, $tenancyInit);

        $exit = $tester->execute([]);
        self::assertSame(Command::FAILURE, $exit);
        self::assertNull($tenancyInit->lastInputParams, 'tenancy:init MUST NOT be called when bundles.php write was rolled back');
        self::assertStringContainsString('/tmp/bundles.php.bak.20260515-120000', $tester->getDisplay());
        self::assertStringContainsString('PHP Parse error', $tester->getDisplay());
    }

    public function testDevDependencyMissingExitsFailureWithInstallInstructions(): void
    {
        $installer = new BundlesPhpInstallerStub(InstallResult::devDependencyMissing());

        $tenancyInit = $this->buildSpyTenancyInit(Command::SUCCESS);
        $tester = $this->buildTester($installer, $tenancyInit);

        $exit = $tester->execute([]);
        self::assertSame(Command::FAILURE, $exit);
        self::assertNull($tenancyInit->lastInputParams);
        self::assertStringContainsString('composer require --dev nikic/php-parser', $tester->getDisplay());
    }

    public function testDryRunSkipsTenancyInitInvocation(): void
    {
        $dryRunDiff = "--- bundles.php (current)\n+++ bundles.php (proposed)\n+    Tenancy\\Bundle\\TenancyBundle::class => ['all' => true],\n";
        $installer = new BundlesPhpInstallerStub(InstallResult::dryRun($dryRunDiff));
        $installer->expectedDryRun = true;

        $tenancyInit = $this->buildSpyTenancyInit(Command::SUCCESS);
        $tester = $this->buildTester($installer, $tenancyInit);

        $exit = $tester->execute(['--dry-run' => true]);
        self::assertSame(Command::SUCCESS, $exit);
        self::assertNull($tenancyInit->lastInputParams, 'Dry-run must NOT invoke tenancy:init (D-10)');
        self::assertStringContainsString('Tenancy\\Bundle\\TenancyBundle::class', $tester->getDisplay());
        self::assertStringContainsString('Dry-run', $tester->getDisplay());
        self::assertTrue($installer->receivedDryRun, 'installer->install() must receive dryRun=true when --dry-run flag is set');
    }

    public function testTenancyInitYamlExistsFailureIsSwallowedToSuccess(): void
    {
        // Create the file that triggers the swallow path.
        file_put_contents($this->projectDir.'/config/packages/tenancy.yaml', "tenancy: {}\n");

        $installer = new BundlesPhpInstallerStub(
            InstallResult::wrote($this->projectDir.'/config/bundles.php.bak.20260515-120000')
        );

        $tenancyInit = $this->buildSpyTenancyInit(Command::FAILURE);
        $tester = $this->buildTester($installer, $tenancyInit);

        $exit = $tester->execute([]); // --force NOT passed
        self::assertSame(Command::SUCCESS, $exit, 'D-09: tenancy.yaml exists + tenancy:init failed + !--force → swallow to SUCCESS');
        self::assertStringContainsString('tenancy.yaml already exists', $tester->getDisplay());
    }

    /**
     * Build a CommandTester that uses a fake Application carrying a stub tenancy:init command.
     */
    private function buildTester(BundlesPhpInstallerInterface $installer, ?TenancyInitSpyCommand $tenancyInit = null): CommandTester
    {
        $command = new TenancyInstallCommand($this->projectDir, $installer);
        $app = new Application();
        $app->addCommand($command);
        if (null !== $tenancyInit) {
            $app->addCommand($tenancyInit);
        }

        // Resolve through the Application so getApplication() returns it inside execute().
        return new CommandTester($app->find('tenancy:install'));
    }

    private function buildSpyTenancyInit(int $returnExitCode): TenancyInitSpyCommand
    {
        return new TenancyInitSpyCommand($returnExitCode);
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

/**
 * Hand-rolled stub implementing BundlesPhpInstallerInterface (BundlesPhpInstaller is final —
 * cannot be mocked by PHPUnit or extended). Returns a canned InstallResult; tracks call
 * arguments for assertions.
 *
 * Mirrors the TenantConnectionInterface / TenantConnectionStub pattern used in Phase 03
 * for the same reason (final concrete class + PHPUnit ClassIsFinalException).
 *
 * @internal test double
 */
final class BundlesPhpInstallerStub implements BundlesPhpInstallerInterface
{
    public bool $wasCalled = false;
    public bool $receivedDryRun = false;
    public bool $expectNeverCalled = false;
    public bool $expectedDryRun = false;

    public function __construct(private readonly InstallResult $result)
    {
    }

    public function install(string $bundlesPhpPath, bool $dryRun = false): InstallResult
    {
        $this->wasCalled = true;
        $this->receivedDryRun = $dryRun;

        return $this->result;
    }
}

/**
 * Test double that records the input it received and returns a canned exit code.
 * Lives in the same file (test-private collaborator) to keep the spy local.
 *
 * @internal test double
 */
final class TenancyInitSpyCommand extends Command
{
    /** @var array<string, mixed>|null */
    public ?array $lastInputParams = null;

    public function __construct(private readonly int $exitCode)
    {
        parent::__construct('tenancy:init');
    }

    protected function configure(): void
    {
        $this->addOption('force', null, InputOption::VALUE_NONE);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->lastInputParams = ['--force' => (bool) $input->getOption('force')];

        return $this->exitCode;
    }
}
