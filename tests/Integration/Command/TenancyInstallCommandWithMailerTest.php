<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Integration\Command;

use PhpParser\ParserFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Mailer\MailerInterface;
use Tenancy\Bundle\Tests\Integration\Command\Support\InstallCommandTestKernel;

/**
 * Integration test for `tenancy:install --with-mailer` (Plan 20-08 / D-09).
 *
 * Boots a fresh kernel rooted at a tmp directory containing:
 *   - config/bundles.php          (skeleton fixture — already-registered baseline)
 *   - config/packages/tenancy.yaml (bare `tenancy:` content)
 *   - src/Entity/Tenant.php       (a minimal class without the trait)
 *   - migrations/                 (empty dir to receive the scaffolded migration)
 *
 * Runs the command via CommandTester and asserts:
 *   - (i)  the entity now contains `use \\Tenancy\\Bundle\\Mailer\\TenantMailerConfigTrait;`
 *   - (ii) a Version*_AddTenantMailerColumns.php migration was written
 *   - (iii) the yaml file ends with the appended commented-out `# mailer:` block
 *
 * Skips cleanly when symfony/mailer or nikic/php-parser is absent.
 */
final class TenancyInstallCommandWithMailerTest extends TestCase
{
    private string $tmpDir = '';
    private ?InstallCommandTestKernel $kernel = null;

    protected function setUp(): void
    {
        if (!interface_exists(MailerInterface::class)) {
            self::markTestSkipped('symfony/mailer is not installed — --with-mailer flow not exercisable');
        }
        if (!class_exists(ParserFactory::class)) {
            self::markTestSkipped('nikic/php-parser is not installed — AST mutation not exercisable');
        }

        $this->tmpDir = sys_get_temp_dir().'/tenancy_install_with_mailer_'.uniqid('', true);
        mkdir($this->tmpDir.'/config/packages', 0755, true);
        mkdir($this->tmpDir.'/src/Entity', 0755, true);
        mkdir($this->tmpDir.'/migrations', 0755, true);
        $this->tmpDir = (string) realpath($this->tmpDir);
    }

    protected function tearDown(): void
    {
        if (null !== $this->kernel) {
            $this->kernel->shutdown();
            $this->kernel = null;
        }
        $this->cleanUp($this->tmpDir);
    }

    public function testWithMailerFlagInsertsTraitScaffoldsMigrationAndUpdatesYaml(): void
    {
        $this->seedFixtures();
        $this->bootKernel();

        $tester = $this->makeTester();
        $exit = $tester->execute(['--with-mailer' => true], ['interactive' => false]);

        self::assertSame(
            Command::SUCCESS,
            $exit,
            'tenancy:install --with-mailer must exit SUCCESS. Display:'."\n".$tester->getDisplay()
        );

        // (i) Trait inserted into the Tenant entity
        $entity = (string) file_get_contents($this->tmpDir.'/src/Entity/Tenant.php');
        self::assertStringContainsString('use \Tenancy\Bundle\Mailer\TenantMailerConfigTrait;', $entity);

        // (ii) Migration file scaffolded (only if doctrine/migrations installed)
        if (class_exists(\Doctrine\Migrations\AbstractMigration::class)) {
            $migFiles = glob($this->tmpDir.'/migrations/Version*_AddTenantMailerColumns.php') ?: [];
            self::assertCount(1, $migFiles, 'exactly one migration file must be scaffolded');
            $migContents = (string) file_get_contents($migFiles[0]);
            self::assertStringContainsString('mailer_dsn', $migContents);
            self::assertStringContainsString('mailer_from', $migContents);
            self::assertStringContainsString('mailer_reply_to', $migContents);
        }

        // (iii) tenancy.yaml received the commented `mailer:` block
        $yaml = (string) file_get_contents($this->tmpDir.'/config/packages/tenancy.yaml');
        self::assertStringContainsString('# mailer:', $yaml);
        self::assertStringContainsString('strategy: x_transport', $yaml);

        // Display surface checks
        $display = $tester->getDisplay();
        self::assertStringContainsString('Mailer setup', $display);
    }

    public function testWithMailerDryRunMutatesNothing(): void
    {
        $this->seedFixtures();
        $entityBefore = (string) file_get_contents($this->tmpDir.'/src/Entity/Tenant.php');
        $yamlBefore = (string) file_get_contents($this->tmpDir.'/config/packages/tenancy.yaml');

        $this->bootKernel();

        $tester = $this->makeTester();
        $exit = $tester->execute(
            ['--with-mailer' => true, '--dry-run' => true],
            ['interactive' => false]
        );
        self::assertSame(Command::SUCCESS, $exit);

        // Entity unchanged
        self::assertSame(
            $entityBefore,
            (string) file_get_contents($this->tmpDir.'/src/Entity/Tenant.php'),
            'dry-run must NOT mutate the Tenant entity'
        );

        // No migration written
        $migFiles = glob($this->tmpDir.'/migrations/Version*_AddTenantMailerColumns.php') ?: [];
        self::assertSame([], $migFiles, 'dry-run must NOT scaffold a migration file');

        // yaml unchanged
        self::assertSame(
            $yamlBefore,
            (string) file_get_contents($this->tmpDir.'/config/packages/tenancy.yaml'),
            'dry-run must NOT mutate tenancy.yaml'
        );

        // No .bak files created in the entity dir
        $bakFiles = glob($this->tmpDir.'/src/Entity/*.bak.*') ?: [];
        self::assertSame([], $bakFiles, 'dry-run must NOT create a .bak sidecar');
    }

    public function testWithMailerIsIdempotent(): void
    {
        $this->seedFixtures();
        $this->bootKernel();

        $tester = $this->makeTester();
        $firstExit = $tester->execute(['--with-mailer' => true], ['interactive' => false]);
        self::assertSame(Command::SUCCESS, $firstExit);

        // Capture state after the first run
        $entityAfterFirst = (string) file_get_contents($this->tmpDir.'/src/Entity/Tenant.php');
        $yamlAfterFirst = (string) file_get_contents($this->tmpDir.'/config/packages/tenancy.yaml');

        // Second run — kernel reused; we get a fresh tester so previous run's input
        // does not bleed in.
        $tester2 = $this->makeTester();
        $secondExit = $tester2->execute(['--with-mailer' => true], ['interactive' => false]);
        self::assertSame(Command::SUCCESS, $secondExit);

        // Entity has exactly ONE trait-use line
        $entity = (string) file_get_contents($this->tmpDir.'/src/Entity/Tenant.php');
        self::assertSame(
            1,
            substr_count($entity, 'TenantMailerConfigTrait'),
            'second run must not duplicate the TraitUse statement'
        );
        self::assertSame($entityAfterFirst, $entity, 'second run must leave the entity byte-identical');

        // yaml has exactly ONE `mailer:` key (commented or active)
        $yaml = (string) file_get_contents($this->tmpDir.'/config/packages/tenancy.yaml');
        self::assertSame(
            1,
            preg_match_all('/^[ \t]*#?[ \t]*mailer[ \t]*:/m', $yaml),
            'second run must not append a duplicate mailer: block'
        );
        self::assertSame($yamlAfterFirst, $yaml, 'second run must leave tenancy.yaml byte-identical');
    }

    // ----- helpers -----

    private function seedFixtures(): void
    {
        // bundles.php — copy the .expected/skeleton baseline so TenancyBundle is "already registered".
        // The skeleton fixture without Tenancy entry would trigger the bundle-registration write path,
        // but we only want to test the --with-mailer post-install hook here.
        $skeletonExpected = __DIR__.'/../../Fixtures/BundlesPhpCorpus/.expected/skeleton/bundles.php';
        copy($skeletonExpected, $this->tmpDir.'/config/bundles.php');

        // Bare tenancy.yaml — tenancy:init would normally produce this; we pre-seed.
        file_put_contents(
            $this->tmpDir.'/config/packages/tenancy.yaml',
            "tenancy:\n    driver: database_per_tenant\n"
        );

        // Minimal Tenant entity — implements TenantInterface stubs but no mailer trait.
        file_put_contents($this->tmpDir.'/src/Entity/Tenant.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Entity;

class Tenant
{
    public function getId(): ?int
    {
        return null;
    }
}
PHP
        );
    }

    private function bootKernel(): void
    {
        $this->kernel = new InstallCommandTestKernel($this->tmpDir);
        $this->kernel->boot();
    }

    private function makeTester(): CommandTester
    {
        \assert(null !== $this->kernel);
        $app = new Application($this->kernel);
        $app->setAutoExit(false);

        return new CommandTester($app->find('tenancy:install'));
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
                $real = $file->getRealPath();
                if (false !== $real) {
                    $file->isDir() ? rmdir($real) : unlink($real);
                }
            }
        }
        rmdir($dir);
    }
}
