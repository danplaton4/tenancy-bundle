<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Unit\Composer;

use PHPUnit\Framework\TestCase;

/**
 * Composer manifest contract test (DX-06 success criterion 5).
 *
 * Guards the invariant that `nikic/php-parser` is a developer-time dependency
 * only — never present in `require`. The library is used by `tenancy:install`
 * (a one-shot scaffolding command), not by any runtime code path the bundle
 * exposes to consumer applications. A leak into `require` would impose a
 * ~1 MB transitive dependency on every consumer.
 */
final class ComposerJsonContractTest extends TestCase
{
    /**
     * @return array{require?: array<string,string>, require-dev?: array<string,string>, suggest?: array<string,string>}
     */
    private function manifest(): array
    {
        $path = __DIR__.'/../../../composer.json';
        self::assertFileExists($path);
        /** @var array<string,mixed> $decoded */
        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        /** @var array{require?: array<string,string>, require-dev?: array<string,string>, suggest?: array<string,string>} $decoded */
        return $decoded;
    }

    public function testNikicPhpParserIsAbsentFromRuntimeRequire(): void
    {
        $manifest = $this->manifest();
        self::assertArrayNotHasKey(
            'nikic/php-parser',
            $manifest['require'] ?? [],
            'nikic/php-parser must NEVER appear in composer.json `require` — it is a dev-only dependency for tenancy:install.'
        );
    }

    public function testNikicPhpParserIsPresentInRequireDev(): void
    {
        $manifest = $this->manifest();
        self::assertArrayHasKey('nikic/php-parser', $manifest['require-dev'] ?? []);
        self::assertMatchesRegularExpression(
            '/^\^5\./',
            $manifest['require-dev']['nikic/php-parser'],
            'nikic/php-parser must be pinned to ^5.x (the v5 namespace shape is what BundlesPhpInstaller targets).'
        );
    }

    public function testNikicPhpParserIsSuggestedWithRationale(): void
    {
        $manifest = $this->manifest();
        self::assertArrayHasKey('nikic/php-parser', $manifest['suggest'] ?? []);
        self::assertNotEmpty(
            $manifest['suggest']['nikic/php-parser'],
            'nikic/php-parser suggest entry must include a rationale string so `composer suggest` users see why.'
        );
    }
}
