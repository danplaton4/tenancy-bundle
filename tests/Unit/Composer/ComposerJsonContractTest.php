<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Unit\Composer;

use PHPUnit\Framework\TestCase;

/**
 * Composer manifest contract test (Phase 22 D-09/D-10/D-13; revised v0.4.1).
 *
 * v0.3.3 reversed Phase 18 DEC-INST-02: `nikic/php-parser` is a hard runtime
 * dependency declared in `composer.json#require` so that `tenancy:install` works
 * one-command for end users, and it is removed from `suggest` (it is required,
 * not suggested).
 *
 * v0.4.1 removes the redundant `require-dev` copy: a `require` dependency is
 * always installed in the dev environment too, so the duplicate entry added
 * nothing and tripped `composer validate` ("required both in require and
 * require-dev"). This test now guards that nikic lives in `require` only.
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

    public function testNikicPhpParserIsNotDuplicatedInRequireDev(): void
    {
        $manifest = $this->manifest();
        $requireDev = \is_array($manifest['require-dev'] ?? null) ? $manifest['require-dev'] : [];
        self::assertArrayNotHasKey(
            'nikic/php-parser',
            $requireDev,
            'nikic/php-parser must NOT be duplicated in composer.json `require-dev` (v0.4.1) — it is a hard `require`, which Composer always installs in the dev environment too, and the duplicate entry trips `composer validate`.'
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
