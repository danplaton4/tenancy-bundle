<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Unit\Command\Install;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tenancy\Bundle\Command\Install\BundlesPhpInstaller;
use Tenancy\Bundle\Command\Install\InstallStatus;

final class BundlesPhpInstallerTest extends TestCase
{
    private const FIXTURES_DIR = __DIR__.'/../../../Fixtures/BundlesPhpCorpus';

    /**
     * Maps fixture slug to (expected detect status, expected install status when Tenancy bundle is absent from input).
     *
     * @return iterable<string, array{0: string, 1: 'standard'|'non_standard', 2: InstallStatus|null}>
     */
    public static function fixturesProvider(): iterable
    {
        // Tuple: [slug, expectedDetectStatus, expectedInstallStatusOrNullForThrows]
        yield 'skeleton' => ['skeleton', 'standard', null];                  // null = install() should throw LogicException (Plan 04 stub)
        yield 'api-platform' => ['api-platform', 'standard', null];
        yield 'sulu' => ['sulu', 'standard', null];
        yield 'with-comments' => ['with-comments', 'standard', null];
        yield 'ddd-override' => ['ddd-override', 'non_standard', InstallStatus::REFUSED_NON_STANDARD];
        yield 'env-conditional' => ['env-conditional', 'non_standard', InstallStatus::REFUSED_NON_STANDARD];
        yield 'malformed' => ['malformed', 'non_standard', InstallStatus::REFUSED_NON_STANDARD];
    }

    #[DataProvider('fixturesProvider')]
    public function testDetect(string $slug, string $expectedDetectStatus, ?InstallStatus $unused): void
    {
        $installer = new BundlesPhpInstaller();
        $result = $installer->detect(self::FIXTURES_DIR.'/'.$slug.'/bundles.php');

        self::assertSame($expectedDetectStatus, $result->status, "detect() classified '$slug' wrong");

        if ('standard' === $expectedDetectStatus) {
            self::assertNotNull($result->endPos, "standard fixture '$slug' must carry an endPos");
            self::assertGreaterThan(0, $result->endPos);
            self::assertContains(
                'Symfony\\Bundle\\FrameworkBundle\\FrameworkBundle',
                $result->registeredFqcns,
                "standard fixture '$slug' must contain FrameworkBundle in its FQCN list"
            );
        } else {
            self::assertNull($result->endPos);
            self::assertNotNull($result->reason);
        }
    }

    #[DataProvider('fixturesProvider')]
    public function testInstallTerminalBranches(string $slug, string $expectedDetectStatus, ?InstallStatus $expectedStatus): void
    {
        $tmpPath = $this->copyFixtureToTmp($slug);

        try {
            $installer = new BundlesPhpInstaller();

            if (null === $expectedStatus) {
                // Standard-shape fixture WITHOUT the Tenancy class — write branch should throw LogicException in Plan 03 (filled in by Plan 04).
                $this->expectException(\LogicException::class);
                $this->expectExceptionMessage('not yet implemented (scheduled for plan 18-04)');
                $installer->install($tmpPath);
            } else {
                $result = $installer->install($tmpPath);
                self::assertSame($expectedStatus, $result->status, "install() classified '$slug' wrong");
            }
        } finally {
            $this->cleanUp(dirname($tmpPath));
        }
    }

    public function testAlreadyRegisteredWhenTenancyClassIsInList(): void
    {
        $tmpPath = $this->copyExpectedBaselineToTmp('skeleton'); // .expected/skeleton has Tenancy entry already inserted

        try {
            $installer = new BundlesPhpInstaller();
            $result = $installer->install($tmpPath);
            self::assertSame(InstallStatus::ALREADY_REGISTERED, $result->status);
        } finally {
            $this->cleanUp(dirname($tmpPath));
        }
    }

    public function testMissingFileReturnsRefusedNonStandard(): void
    {
        $installer = new BundlesPhpInstaller();
        $result = $installer->install('/tmp/definitely-does-not-exist-'.uniqid('', true).'/bundles.php');
        self::assertSame(InstallStatus::REFUSED_NON_STANDARD, $result->status);
        self::assertNotNull($result->errorMessage);
        self::assertStringContainsString('not found', $result->errorMessage);
    }

    public function testExtractFqcnsReturnsListShape(): void
    {
        // Whitebox: feed extractFqcns the parsed skeleton fixture and assert it returns list<string>
        $source = (string) file_get_contents(self::FIXTURES_DIR.'/skeleton/bundles.php');
        $parser = (new \PhpParser\ParserFactory())->createForNewestSupportedVersion();
        $stmts = $parser->parse($source);
        self::assertNotNull($stmts);
        $return = $stmts[0];
        self::assertInstanceOf(\PhpParser\Node\Stmt\Return_::class, $return);
        self::assertInstanceOf(\PhpParser\Node\Expr\Array_::class, $return->expr);

        $installer = new BundlesPhpInstaller();
        $fqcns = $installer->extractFqcns($return->expr);
        self::assertNotNull($fqcns);
        self::assertContains('Symfony\\Bundle\\FrameworkBundle\\FrameworkBundle', $fqcns);
    }

    private function copyFixtureToTmp(string $slug): string
    {
        $tmpDir = sys_get_temp_dir().'/bundles_php_corpus_test_'.uniqid('', true);
        mkdir($tmpDir, 0755, true);
        $src = self::FIXTURES_DIR.'/'.$slug.'/bundles.php';
        $dst = $tmpDir.'/bundles.php';
        copy($src, $dst);

        return $dst;
    }

    private function copyExpectedBaselineToTmp(string $slug): string
    {
        $tmpDir = sys_get_temp_dir().'/bundles_php_corpus_test_'.uniqid('', true);
        mkdir($tmpDir, 0755, true);
        $src = self::FIXTURES_DIR.'/.expected/'.$slug.'/bundles.php';
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
