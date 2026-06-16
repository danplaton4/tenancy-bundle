<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Unit\PHPStan\Rule;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use Tenancy\Bundle\PHPStan\Rule\TenantIdDriftRule;
use Tenancy\Bundle\Tests\Unit\PHPStan\Rule\Fixtures\TenantAwareParent;
use Tenancy\Bundle\Tests\Unit\PHPStan\Rule\Fixtures\TenantIdMissingChild;
use Tenancy\Bundle\Tests\Unit\PHPStan\Rule\Fixtures\TenantIdMissingViolating;
use Tenancy\Bundle\Tests\Unit\PHPStan\Rule\Fixtures\TenantIdNonStringViolating;
use Tenancy\Bundle\Tests\Unit\PHPStan\Rule\Fixtures\TenantIdNullableViolating;

/**
 * @extends RuleTestCase<TenantIdDriftRule>
 */
final class TenantIdDriftRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        // No ObjectMetadataResolver — exercises the reflection fallback PRIMARY path,
        // which is what CI runs (D-02: degrade gracefully when phpstan-doctrine absent).
        return new TenantIdDriftRule();
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
     * DX-03-AC3 hierarchy: fires when #[TenantAware] is on a parent and tenant_id is missing.
     * Proves the ancestor walk (PHP class attributes are NOT inherited — getParentClass() loop needed).
     */
    public function testFiresOnInheritedTenantAware(): void
    {
        $this->analyse(
            [
                __DIR__.'/Fixtures/TenantAwareParent.php',
                __DIR__.'/Fixtures/TenantIdMissingChild.php',
            ],
            [
                // TenantAwareParent itself has no tenant_id column — fires for the parent too
                [
                    'Class '.TenantAwareParent::class.' is #[TenantAware] but has no column mapped to tenant_id. '
                    .'Add a non-nullable string column named tenant_id (e.g. VARCHAR(63)).',
                    10,
                ],
                // TenantIdMissingChild inherits #[TenantAware] via parent, also has no tenant_id
                [
                    'Class '.TenantIdMissingChild::class.' is #[TenantAware] but has no column mapped to tenant_id. '
                    .'Add a non-nullable string column named tenant_id (e.g. VARCHAR(63)).',
                    9,
                ],
            ]
        );
    }
}
