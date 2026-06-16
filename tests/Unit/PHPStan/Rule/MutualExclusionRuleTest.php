<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Unit\PHPStan\Rule;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use Tenancy\Bundle\PHPStan\Rule\MutualExclusionRule;

/**
 * @extends RuleTestCase<MutualExclusionRule>
 */
final class MutualExclusionRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return new MutualExclusionRule();
    }

    /**
     * Loads extension.neon so parametersSchema is available during tests.
     *
     * Path: 4 levels up from tests/Unit/PHPStan/Rule to package root.
     */
    public static function getAdditionalConfigFiles(): array
    {
        return [__DIR__.'/../../../../extension.neon'];
    }

    /**
     * DX-03-AC1: a class with BOTH #[Shared] and #[TenantAware] fires exactly one error.
     * DX-03-AC5: error message names the class FQCN; identifier is tenancy.mutualExclusion.
     */
    public function testMutualExclusionViolation(): void
    {
        $this->analyse(
            [__DIR__.'/Fixtures/BothAttributesViolating.php'],
            [
                [
                    'Entity Tenancy\Bundle\Tests\Unit\PHPStan\Rule\Fixtures\BothAttributesViolating cannot carry both #[Shared] and #[TenantAware]. A shared entity is a landlord-side master; a TenantAware entity is tenant-scoped. Pick one.',
                    10,
                ],
            ]
        );
    }

    /**
     * DX-03-AC1 hierarchy: fires when #[Shared] is on a parent and #[TenantAware] on the child.
     * Proves the ancestor walk (PHP class attributes are NOT inherited — getParentClass() loop needed).
     */
    public function testFiresOnInheritedAttribute(): void
    {
        // TenantAwareChildViolating (line 9) extends SharedParentViolating (#[Shared]).
        // The rule must walk up to find #[Shared] on the parent.
        // PHPStan reports the line of the first attribute on the class declaration.
        $this->analyse(
            [
                __DIR__.'/Fixtures/SharedParentViolating.php',
                __DIR__.'/Fixtures/TenantAwareChildViolating.php',
            ],
            [
                [
                    'Entity Tenancy\Bundle\Tests\Unit\PHPStan\Rule\Fixtures\TenantAwareChildViolating cannot carry both #[Shared] and #[TenantAware]. A shared entity is a landlord-side master; a TenantAware entity is tenant-scoped. Pick one.',
                    9,
                ],
            ]
        );
    }

    /**
     * DX-03-AC1 clean: a class with only #[Shared] or only #[TenantAware] produces zero errors.
     */
    public function testNoViolationWhenOnlyOneAttribute(): void
    {
        $this->analyse(
            [
                __DIR__.'/Fixtures/OnlySharedClean.php',
                __DIR__.'/Fixtures/OnlyTenantAwareClean.php',
            ],
            []
        );
    }
}
