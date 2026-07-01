<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Unit\Entity;

use Doctrine\ORM\Mapping as ORM;
use PHPUnit\Framework\TestCase;
use Tenancy\Bundle\Entity\AbstractTenant;
use Tenancy\Bundle\Entity\Tenant;
use Tenancy\Bundle\Maintenance\TenantMaintenanceConfigTrait;
use Tenancy\Bundle\TenantInterface;

/**
 * Unit tests for TenantMaintenanceConfigTrait and the AbstractTenant $inMaintenance column.
 *
 * Covers MAINT-05 acceptance criteria:
 * - TenantMaintenanceConfigTrait provides private bool $inMaintenance = false
 * - isInMaintenance() returns false by default
 * - setInMaintenance(true) sets flag; returns static (fluent)
 * - AbstractTenant inlines the same column with self-return accessors
 * - Trait docblock contains the AbstractTenant duplicate-column warning
 */
final class TenantMaintenanceConfigTraitTest extends TestCase
{
    // -----------------------------------------------------------------------
    // Tests against a class that uses the trait directly
    // -----------------------------------------------------------------------

    public function testTraitDefaultIsFalse(): void
    {
        $entity = $this->newTraitUser();

        $this->assertFalse($entity->isInMaintenance());
    }

    public function testTraitSetterSetsFlag(): void
    {
        $entity = $this->newTraitUser();
        $entity->setInMaintenance(true);

        $this->assertTrue($entity->isInMaintenance());
    }

    public function testTraitSetterIsFluent(): void
    {
        $entity = $this->newTraitUser();
        $result = $entity->setInMaintenance(true);

        // Fluent — returns same instance
        $this->assertSame($entity, $result);
    }

    public function testTraitSetterReturnTypeIsStatic(): void
    {
        $reflection = new \ReflectionMethod(TenantMaintenanceConfigTrait::class, 'setInMaintenance');
        $returnType = $reflection->getReturnType();

        $this->assertInstanceOf(\ReflectionNamedType::class, $returnType);
        $this->assertSame('static', $returnType->getName());
    }

    public function testTraitSetterCanSetFalse(): void
    {
        $entity = $this->newTraitUser();
        $entity->setInMaintenance(true);
        $entity->setInMaintenance(false);

        $this->assertFalse($entity->isInMaintenance());
    }

    public function testTraitPropertyIsNonNullableBool(): void
    {
        $reflection = new \ReflectionProperty(TenantMaintenanceConfigTrait::class, 'inMaintenance');
        $type = $reflection->getType();

        $this->assertInstanceOf(\ReflectionNamedType::class, $type);
        $this->assertSame('bool', $type->getName());
        $this->assertFalse($type->allowsNull(), 'inMaintenance must be non-nullable bool');
    }

    public function testTraitHasOrmColumnAttribute(): void
    {
        $reflection = new \ReflectionProperty(TenantMaintenanceConfigTrait::class, 'inMaintenance');
        $columnAttrs = $reflection->getAttributes(ORM\Column::class);

        $this->assertNotEmpty($columnAttrs, 'TenantMaintenanceConfigTrait::$inMaintenance must have #[ORM\Column]');
        $args = $columnAttrs[0]->getArguments();
        $this->assertSame('boolean', $args['type'] ?? null);
    }

    public function testTraitDocblockContainsDuplicateColumnWarning(): void
    {
        $reflection = new \ReflectionClass(TenantMaintenanceConfigTrait::class);
        $docComment = (string) $reflection->getDocComment();

        $this->assertStringContainsString(
            'Do NOT use with',
            $docComment,
            'Trait docblock must warn against combining with AbstractTenant (Pitfall 6)'
        );
    }

    // -----------------------------------------------------------------------
    // Tests against AbstractTenant / Tenant (inlined column)
    // -----------------------------------------------------------------------

    public function testAbstractTenantDefaultIsInMaintenanceFalse(): void
    {
        $tenant = new Tenant('acme', 'Acme Corp');

        $this->assertFalse($tenant->isInMaintenance());
    }

    public function testAbstractTenantSetInMaintenanceTrue(): void
    {
        $tenant = new Tenant('acme', 'Acme Corp');
        $tenant->setInMaintenance(true);

        $this->assertTrue($tenant->isInMaintenance());
    }

    public function testAbstractTenantSetInMaintenanceReturnsSelf(): void
    {
        $tenant = new Tenant('acme', 'Acme Corp');
        $result = $tenant->setInMaintenance(true);

        // AbstractTenant setters return self (not static)
        $this->assertSame($tenant, $result);
    }

    public function testAbstractTenantInMaintenanceColumnExists(): void
    {
        $reflection = new \ReflectionProperty(AbstractTenant::class, 'inMaintenance');
        $columnAttrs = $reflection->getAttributes(ORM\Column::class);

        $this->assertNotEmpty($columnAttrs, 'AbstractTenant::$inMaintenance must have #[ORM\Column]');
        $args = $columnAttrs[0]->getArguments();
        $this->assertSame('boolean', $args['type'] ?? null);
    }

    public function testAbstractTenantIsInMaintenanceImplementsTenantInterface(): void
    {
        $reflection = new \ReflectionClass(TenantInterface::class);
        $this->assertTrue($reflection->hasMethod('isInMaintenance'), 'TenantInterface must declare isInMaintenance(): bool');

        $method = $reflection->getMethod('isInMaintenance');
        $returnType = $method->getReturnType();
        $this->assertInstanceOf(\ReflectionNamedType::class, $returnType);
        $this->assertSame('bool', $returnType->getName());
    }

    // -----------------------------------------------------------------------
    // Helper
    // -----------------------------------------------------------------------

    /**
     * Returns an anonymous class that uses the trait — simulates a custom Tenant entity
     * that does not extend AbstractTenant.
     */
    private function newTraitUser(): object
    {
        return new class {
            use TenantMaintenanceConfigTrait;
        };
    }
}
