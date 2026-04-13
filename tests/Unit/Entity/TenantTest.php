<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Unit\Entity;

use Doctrine\ORM\Mapping as ORM;
use PHPUnit\Framework\TestCase;
use Tenancy\Bundle\Entity\Tenant;
use Tenancy\Bundle\TenantInterface;

class TenantTest extends TestCase
{
    public function testImplementsTenantInterface(): void
    {
        $this->assertInstanceOf(TenantInterface::class, new Tenant('test', 'Test'));
    }

    public function testConstructorSetsSlugAndName(): void
    {
        $tenant = new Tenant('acme', 'Acme Corp');

        $this->assertSame('acme', $tenant->getSlug());
        $this->assertSame('Acme Corp', $tenant->getName());
    }

    public function testSlugIsStringPrimaryKey(): void
    {
        $reflection = new \ReflectionClass(Tenant::class);

        $slugProperty = $reflection->getProperty('slug');
        $idAttributes = $slugProperty->getAttributes(ORM\Id::class);
        $this->assertNotEmpty($idAttributes, 'slug property must have #[ORM\Id] attribute');

        // Assert no property has #[ORM\GeneratedValue]
        foreach ($reflection->getProperties() as $property) {
            $generatedValueAttrs = $property->getAttributes(ORM\GeneratedValue::class);
            $this->assertEmpty(
                $generatedValueAttrs,
                sprintf('Property "%s" must not have #[ORM\GeneratedValue] — slug is the natural PK', $property->getName())
            );
        }
    }

    public function testDomainDefaultsToNull(): void
    {
        $tenant = new Tenant('acme', 'Acme Corp');

        $this->assertNull($tenant->getDomain());
    }

    public function testConnectionConfigDefaultsToEmptyArray(): void
    {
        $tenant = new Tenant('acme', 'Acme Corp');

        $this->assertSame([], $tenant->getConnectionConfig());
    }

    public function testIsActiveDefaultsToTrue(): void
    {
        $tenant = new Tenant('acme', 'Acme Corp');

        $this->assertTrue($tenant->isActive());
    }

    public function testSettersReturnSelfForFluency(): void
    {
        $tenant = new Tenant('acme', 'Acme Corp');

        $result = $tenant->setDomain('acme.example.com');

        $this->assertSame($tenant, $result);
    }

    public function testOnPrePersistSetsTimestamps(): void
    {
        $tenant = new Tenant('acme', 'Acme Corp');
        $tenant->onPrePersist();

        $this->assertInstanceOf(\DateTimeImmutable::class, $tenant->getCreatedAt());
        $this->assertInstanceOf(\DateTimeImmutable::class, $tenant->getUpdatedAt());
    }

    public function testOnPreUpdateUpdatesOnlyUpdatedAt(): void
    {
        $tenant = new Tenant('acme', 'Acme Corp');
        $tenant->onPrePersist();

        $createdAt = $tenant->getCreatedAt();

        $tenant->onPreUpdate();

        $this->assertSame($createdAt, $tenant->getCreatedAt(), 'createdAt must not change after onPreUpdate');
        $this->assertInstanceOf(\DateTimeImmutable::class, $tenant->getUpdatedAt());
    }
}
