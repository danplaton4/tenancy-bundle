<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Unit\PHPStan\Rule;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use Tenancy\Bundle\PHPStan\Rule\TenantIdDriftRule;
use Tenancy\Bundle\Tests\Unit\PHPStan\Rule\Fixtures\TenantAwareConcreteChild;
use Tenancy\Bundle\Tests\Unit\PHPStan\Rule\Fixtures\TenantAwareParent;
use Tenancy\Bundle\Tests\Unit\PHPStan\Rule\Fixtures\TenantIdMissingChild;
use Tenancy\Bundle\Tests\Unit\PHPStan\Rule\Fixtures\TenantIdMissingViolating;
use Tenancy\Bundle\Tests\Unit\PHPStan\Rule\Fixtures\TenantIdNonStringViolating;
use Tenancy\Bundle\Tests\Unit\PHPStan\Rule\Fixtures\TenantIdNullableViolating;
use Tenancy\Bundle\Tests\Unit\PHPStan\Rule\Fixtures\TenantIdValidClean;

/**
 * @extends RuleTestCase<TenantIdDriftRule>
 */
final class TenantIdDriftRuleTest extends RuleTestCase
{
    /**
     * Per-test injectable resolver (null = reflection path; set in metadata-path tests).
     * D-02: degrade gracefully when phpstan-doctrine is absent — null is the default CI lane.
     */
    private ?object $resolver = null;

    protected function getRule(): Rule
    {
        // Pass $this->resolver (null → reflection fallback PRIMARY path; non-null → checkViaMetadata).
        return new TenantIdDriftRule($this->resolver);
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
     * DX-03-AC3: a #[TenantAware] entity with no tenant_id column fires tenancy.tenantIdDrift.
     */
    public function testFiresWhenTenantIdMissing(): void
    {
        $this->analyse(
            [__DIR__.'/Fixtures/TenantIdMissingViolating.php'],
            [
                [
                    'Class '.TenantIdMissingViolating::class.' is #[TenantAware] but has no column mapped to tenant_id. '
                    .'Add a non-nullable string column named tenant_id (e.g. VARCHAR(63)).',
                    10,
                ],
            ]
        );
    }

    /**
     * DX-03-AC3: a #[TenantAware] entity with tenant_id nullable fires tenancy.tenantIdDrift.
     */
    public function testFiresWhenTenantIdNullable(): void
    {
        $this->analyse(
            [__DIR__.'/Fixtures/TenantIdNullableViolating.php'],
            [
                [
                    'Class '.TenantIdNullableViolating::class.' is #[TenantAware] but its tenant_id column is nullable. '
                    .'The tenant_id column must be non-nullable to prevent cross-tenant data leaks.',
                    10,
                ],
            ]
        );
    }

    /**
     * DX-03-AC3: a #[TenantAware] entity with tenant_id of non-string type fires tenancy.tenantIdDrift.
     */
    public function testFiresWhenTenantIdNonString(): void
    {
        $this->analyse(
            [__DIR__.'/Fixtures/TenantIdNonStringViolating.php'],
            [
                [
                    'Class '.TenantIdNonStringViolating::class.' is #[TenantAware] but its tenant_id column maps to non-string type "integer". '
                    .'The TenantAwareFilter compares tenant_id as a quoted string slug; accepted types: string, ascii_string, guid, uuid.',
                    10,
                ],
            ]
        );
    }

    /**
     * DX-03-AC3 clean: a #[TenantAware] entity with a valid non-nullable string tenant_id produces zero errors.
     */
    public function testNoViolationForValidTenantId(): void
    {
        $this->analyse(
            [__DIR__.'/Fixtures/TenantIdValidClean.php'],
            []
        );
    }

    /**
     * WR-02 fix: a #[TenantAware] #[ORM\MappedSuperclass] parent is SILENT (skipped as non-instantiable
     * base); a concrete #[ORM\Entity] child inheriting #[TenantAware] but lacking tenant_id still FIRES.
     *
     * Proves: (1) MappedSuperclass exemption silences the parent, (2) ancestor walk still catches
     * the concrete child — the exemption does NOT propagate to concrete subclasses.
     *
     * @see TenantAwareParent — #[TenantAware] #[ORM\MappedSuperclass], no tenant_id → SILENT
     * @see TenantIdMissingChild — #[ORM\Entity] extends TenantAwareParent, no tenant_id → FIRES
     */
    public function testMappedSuperclassParentSilentConcreteChildFires(): void
    {
        $this->analyse(
            [
                __DIR__.'/Fixtures/TenantAwareParent.php',
                __DIR__.'/Fixtures/TenantIdMissingChild.php',
            ],
            [
                // TenantAwareParent (#[ORM\MappedSuperclass]) is now SILENT — no false positive.
                // TenantIdMissingChild (#[ORM\Entity]) inherits #[TenantAware] and has no tenant_id → fires.
                [
                    'Class '.TenantIdMissingChild::class.' is #[TenantAware] but has no column mapped to tenant_id. '
                    .'Add a non-nullable string column named tenant_id (e.g. VARCHAR(63)).',
                    9,
                ],
            ]
        );
    }

    /**
     * WR-02 fix: analysing the MappedSuperclass base alone produces ZERO errors.
     *
     * A consumer who puts #[TenantAware] on a MappedSuperclass (common pattern — AbstractTenant
     * split is exactly this) must not receive a spurious false error on the base class.
     */
    public function testSilentOnMappedSuperclassBase(): void
    {
        $this->analyse(
            [__DIR__.'/Fixtures/TenantAwareParent.php'],
            []
        );
    }

    /**
     * WR-02 corollary: a concrete #[ORM\Entity] child of a #[TenantAware] MappedSuperclass,
     * with no own tenant_id, still fires — the exemption applies to the base only.
     *
     * Uses the dedicated TenantAwareConcreteChild fixture (separate from TenantIdMissingChild
     * to make the WR-02 intent explicit: parent silent, child fires).
     */
    public function testConcreteChildOfMappedSuperclassFires(): void
    {
        $this->analyse(
            [
                __DIR__.'/Fixtures/TenantAwareParent.php',
                __DIR__.'/Fixtures/TenantAwareConcreteChild.php',
            ],
            [
                [
                    'Class '.TenantAwareConcreteChild::class.' is #[TenantAware] but has no column mapped to tenant_id. '
                    .'Add a non-nullable string column named tenant_id (e.g. VARCHAR(63)).',
                    16,
                ],
            ]
        );
    }

    /**
     * CR-01 regression guard (metadata path — SILENT on valid entity).
     *
     * Constructs the rule WITH a real ObjectMetadataResolver (objectManagerLoader=null uses
     * the standalone ClassMetadataFactory, which resolves #[ORM\Entity] attribute-mapped classes
     * in CI without an EntityManager). Asserts the resolver produces non-null metadata FIRST
     * (proving checkViaMetadata() was actually entered — not a silent fallthrough to reflection).
     * Then asserts ZERO errors on TenantIdValidClean (non-nullable string tenant_id).
     *
     * This is the EXACT scenario that shipped broken in the original implementation: the
     * is_array() guard on ORM 3.x FieldMapping objects caused every valid entity to produce
     * a false "no tenant_id" error when the metadata path was reachable.
     *
     * Self-skips when phpstan-doctrine is not installed (no-doctrine CI lane).
     */
    public function testMetadataPathSilentOnValidEntity(): void
    {
        if (!class_exists(\PHPStan\Type\Doctrine\ObjectMetadataResolver::class)) {
            self::markTestSkipped('phpstan-doctrine not installed — metadata path skipped');
        }

        $resolver = new \PHPStan\Type\Doctrine\ObjectMetadataResolver(null, sys_get_temp_dir());

        // PROVE the metadata path is actually entered (not a silent reflection fallthrough).
        // If getClassMetadata() returns null, processNode() would fall through to checkViaReflection()
        // and the test would silently pass for the wrong reason (Warning 3 trap).
        self::assertNotNull(
            $resolver->getClassMetadata(TenantIdValidClean::class),
            'ObjectMetadataResolver must resolve TenantIdValidClean to non-null — proves checkViaMetadata() ran',
        );

        $this->resolver = $resolver;

        // CR-01 regression guard: valid entity must produce ZERO errors via the metadata path.
        $this->analyse(
            [__DIR__.'/Fixtures/TenantIdValidClean.php'],
            []
        );
    }

    /**
     * CR-01 regression guard (metadata path — FIRES on missing tenant_id).
     *
     * Same resolver-injected setup as testMetadataPathSilentOnValidEntity(). Asserts non-null
     * metadata FIRST (proves checkViaMetadata() was entered), then asserts one tenancy.tenantIdDrift
     * "no column mapped to tenant_id" error on TenantIdMissingViolating.
     *
     * Together with the silent test, the pair proves the metadata path produces CORRECT results
     * (silent on valid, fires on missing) — not merely silence via the reflection fallthrough.
     *
     * Self-skips when phpstan-doctrine is not installed (no-doctrine CI lane).
     */
    public function testMetadataPathFiresOnMissingTenantId(): void
    {
        if (!class_exists(\PHPStan\Type\Doctrine\ObjectMetadataResolver::class)) {
            self::markTestSkipped('phpstan-doctrine not installed — metadata path skipped');
        }

        $resolver = new \PHPStan\Type\Doctrine\ObjectMetadataResolver(null, sys_get_temp_dir());

        // PROVE the metadata path is actually entered (not a silent reflection fallthrough).
        self::assertNotNull(
            $resolver->getClassMetadata(TenantIdMissingViolating::class),
            'ObjectMetadataResolver must resolve TenantIdMissingViolating to non-null — proves checkViaMetadata() ran',
        );

        $this->resolver = $resolver;

        // Metadata path must produce the correct POSITIVE result (fires, not just silence).
        $this->analyse(
            [__DIR__.'/Fixtures/TenantIdMissingViolating.php'],
            [
                [
                    'Class '.TenantIdMissingViolating::class.' is #[TenantAware] but has no column mapped to tenant_id. '
                    .'Add a non-nullable string column named tenant_id (e.g. VARCHAR(63)).',
                    10,
                ],
            ]
        );
    }
}
