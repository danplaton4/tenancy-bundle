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
    /** @return array<string, mixed> */
    private function manifest(): array
    {
        $path = __DIR__.'/../../../composer.json';
        self::assertFileExists($path);
        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    public function testNikicPhpParserIsAbsentFromRuntimeRequire(): void
    {
        $manifest = $this->manifest();
        $require = \is_array($manifest['require'] ?? null) ? $manifest['require'] : [];
        self::assertArrayNotHasKey(
            'nikic/php-parser',
            $require,
            'nikic/php-parser must NEVER appear in composer.json `require` — it is a dev-only dependency for tenancy:install.'
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

    public function testNikicPhpParserIsSuggestedWithRationale(): void
    {
        $manifest = $this->manifest();
        $suggest = \is_array($manifest['suggest'] ?? null) ? $manifest['suggest'] : [];
        self::assertArrayHasKey('nikic/php-parser', $suggest);
        self::assertNotEmpty(
            $suggest['nikic/php-parser'],
            'nikic/php-parser suggest entry must include a rationale string so `composer suggest` users see why.'
        );
    }
}
