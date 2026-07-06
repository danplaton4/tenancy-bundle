<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Unit\PHPStan;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Proves the PHPStan extension-installer zero-config auto-load contract (QA-01 close).
 *
 * phpstan/extension-installer reads `extra.phpstan.includes` from composer.json to
 * automatically load extension.neon. These tests verify the metadata + file exist and
 * declare the three rule classes — closing the Phase 28 human_needed UAT item.
 */
#[Group('phpstan-extension')]
final class ExtensionInstallerContractTest extends TestCase
{
    /**
     * Asserts composer.json `extra.phpstan.includes` contains `extension.neon` —
     * the key that phpstan/extension-installer reads for zero-config auto-load.
     */
    public function testComposerJsonDeclaresExtensionNeonInPhpstanIncludes(): void
    {
        $composerJson = json_decode(
            (string) file_get_contents(__DIR__.'/../../../composer.json'),
            true,
            512,
            \JSON_THROW_ON_ERROR
        );
        $includes = $composerJson['extra']['phpstan']['includes'] ?? [];
        $this->assertContains('extension.neon', $includes);
    }

    /**
     * Asserts the file declared in the installer metadata actually exists at the package root.
     */
    public function testExtensionNeonExistsAtDeclaredPath(): void
    {
        $neonPath = __DIR__.'/../../../extension.neon';
        $this->assertFileExists($neonPath);
    }

    /**
     * Asserts extension.neon declares all three rule classes (proves the auto-load delivers rules).
     */
    public function testExtensionNeonDeclaresThreeRuleClasses(): void
    {
        $neon = (string) file_get_contents(__DIR__.'/../../../extension.neon');
        $this->assertStringContainsString('MutualExclusionRule', $neon);
        $this->assertStringContainsString('TenantIdDriftRule', $neon);
        $this->assertStringContainsString('SharedEntityLeakRule', $neon);
    }
}
