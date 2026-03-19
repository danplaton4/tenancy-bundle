<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Integration\Support\Entity;

use Doctrine\ORM\Mapping as ORM;
use Tenancy\Bundle\Attribute\TenantAware;

/**
 * A #[TenantAware] entity used in shared-DB integration tests.
 *
 * Has an explicit `tenant_id` column (name attribute set to avoid any
 * camelCase-to-underscore naming strategy ambiguity) and a `name` column.
 * Rows are seeded via DBAL (bypassing the filter) and then queried via
 * the ORM (with the filter active) to verify scoping.
 */
#[TenantAware]
#[ORM\Entity]
#[ORM\Table(name: 'test_tenant_products')]
class TestTenantProduct
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(name: 'tenant_id', type: 'string', length: 63)]
    private string $tenantId;

    #[ORM\Column(type: 'string', length: 255)]
    private string $name;

    public function __construct(string $tenantId, string $name)
    {
        $this->tenantId = $tenantId;
        $this->name     = $name;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTenantId(): string
    {
        return $this->tenantId;
    }

    public function getName(): string
    {
        return $this->name;
    }
}
