<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Unit\PHPStan\Rule;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use PHPUnit\Framework\Attributes\Group;
use Tenancy\Bundle\PHPStan\Rule\SharedEntityLeakRule;
use Tenancy\Bundle\Tests\Unit\PHPStan\Rule\Fixtures\SharedProductViolating;

/**
 * @extends RuleTestCase<SharedEntityLeakRule>
 */
#[Group('phpstan-extension')]
final class SharedEntityLeakRuleTest extends RuleTestCase
{
    /**
     * Whether to construct the rule with checkSharedEntityLeaks=true (default) or false (gate test).
     */
    private bool $checkLeaks = true;

    protected function setUp(): void
    {
        parent::setUp();

        // SharedEntityLeakRule returns [] when Doctrine ORM is absent (optional dependency —
        // the rule guards on interface_exists(EntityManagerInterface)). The "fires" assertion
        // has no premise without Doctrine, so skip the whole class in the no-doctrine CI lane.
        // The dogfood step separately proves the rule loads and degrades gracefully.
        if (!interface_exists(\Doctrine\ORM\EntityManagerInterface::class)) {
            self::markTestSkipped('SharedEntityLeakRule returns [] without Doctrine ORM — optional dependency.');
        }
    }

    protected function getRule(): Rule
    {
        return new SharedEntityLeakRule(
            $this->checkLeaks,
            $this->createReflectionProvider(),
        );
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
     * DX-03-AC2: a concrete EntityManager querying a #[Shared] entity fires tenancy.sharedEntityLeak.
     *
     * The violating fixture file is analysed alongside the entity class file (SharedProductViolating)
     * so that PHPStan's ReflectionProvider can resolve the entity FQCN used in the ::class constant.
     */
    public function testFiresOnConcreteEntityManagerQueryingShared(): void
    {
        $this->analyse(
            [
                __DIR__.'/Fixtures/SharedProductViolating.php',
                __DIR__.'/Fixtures/SharedEntityLeakViolating.php',
            ],
            [
                [
                    'Entity '.SharedProductViolating::class.' is #[Shared] (a landlord-side master). '
                    .'Querying it through the tenant EntityManager '
                    .'risks a cross-tenant data leak. Route the query through the named landlord EntityManager '
                    .'or suppress with @phpstan-ignore tenancy.sharedEntityLeak.',
                    15,
                ],
            ]
        );
    }

    /**
     * D-01 gate: rule constructed with checkSharedEntityLeaks=false produces zero errors
     * on the same violating fixture that normally fires.
     */
    public function testSilentWhenGatedOff(): void
    {
        $this->checkLeaks = false;

        $this->analyse(
            [
                __DIR__.'/Fixtures/SharedProductViolating.php',
                __DIR__.'/Fixtures/SharedEntityLeakViolating.php',
            ],
            []
        );
    }

    /**
     * D-03 conservative: EntityManagerInterface-typed caller produces zero errors even when
     * querying a #[Shared] entity — PHPStan cannot distinguish landlord EM from tenant EM
     * by interface type alone. Also verifies: concrete EM querying a non-#[Shared] entity
     * produces zero errors.
     */
    public function testSilentOnAmbiguousEntityManagerInterface(): void
    {
        $this->analyse(
            [
                __DIR__.'/Fixtures/SharedProductViolating.php',
                __DIR__.'/Fixtures/TenantIdValidClean.php',
                __DIR__.'/Fixtures/SharedEntityLeakClean.php',
            ],
            []
        );
    }
}
