<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Unit\Composer;

use PHPUnit\Framework\TestCase;

/**
 * Composer manifest contract test (Phase 22 D-09/D-10/D-13).
 *
 * v0.3.3 reverses Phase 18 DEC-INST-02: `nikic/php-parser` is now a hard runtime
 * dependency declared in `composer.json#require` so that `tenancy:install` works
 * one-command for end users. The library is also kept in `require-dev` (so the
 * bundle's own test suite continues to resolve it explicitly) and removed from
 * `suggest` (since it's no longer a suggestion — it's required). This test
 * guards the new contract.
 */
final class ComposerJsonContractTest extends TestCase
{
    /** @return array<string, mixed> */
    private function manifest(): array
    {
        $path = __DIR__.'/../../../composer.json';
        self::assertFileExists($path);
        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    public function testNikicPhpParserIsPresentInRuntimeRequire(): void
    {
        $manifest = $this->manifest();
        $require = \is_array($manifest['require'] ?? null) ? $manifest['require'] : [];
        self::assertArrayHasKey(
            'nikic/php-parser',
            $require,
            'nikic/php-parser must appear in composer.json `require` (Phase 22 D-09 — promoted from suggest so tenancy:install works one-command).'
        );
        $version = $require['nikic/php-parser'];
        self::assertIsString($version);
        self::assertMatchesRegularExpression(
            '/^\^5\./',
            $version,
            'nikic/php-parser must be pinned to ^5.x (the v5 namespace shape is what BundlesPhpInstaller targets).'
        );
    }

    public function testNikicPhpParserIsPresentInRequireDev(): void
    {
        $manifest = $this->manifest();
        $requireDev = \is_array($manifest['require-dev'] ?? null) ? $manifest['require-dev'] : [];
        self::assertArrayHasKey('nikic/php-parser', $requireDev);
        $version = $requireDev['nikic/php-parser'];
        self::assertIsString($version);
        self::assertMatchesRegularExpression(
            '/^\^5\./',
            $version,
            'nikic/php-parser must be pinned to ^5.x (the v5 namespace shape is what BundlesPhpInstaller targets).'
        );
    }

    public function testNikicPhpParserIsAbsentFromSuggest(): void
    {
        $manifest = $this->manifest();
        $suggest = \is_array($manifest['suggest'] ?? null) ? $manifest['suggest'] : [];
        self::assertArrayNotHasKey(
            'nikic/php-parser',
            $suggest,
            'nikic/php-parser must NOT appear in composer.json `suggest` (Phase 22 D-10 — it is required, not suggested).'
        );
    }
}
