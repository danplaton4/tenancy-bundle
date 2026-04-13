<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Unit\Filter;

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\ORMSetup;
use PHPUnit\Framework\TestCase;
use Tenancy\Bundle\Attribute\TenantAware;
use Tenancy\Bundle\Context\TenantContext;
use Tenancy\Bundle\Exception\TenantMissingException;
use Tenancy\Bundle\Filter\TenantAwareFilter;
use Tenancy\Bundle\TenantInterface;

// Inline dummy entities for reflection
#[TenantAware]
class TenantAwareEntity
{
}

class NonTenantAwareEntity
{
}

final class TenantAwareFilterTest extends TestCase
{
    private TenantAwareFilter $filter;
    private EntityManager $em;

    protected function setUp(): void
    {
        $config = ORMSetup::createAttributeMetadataConfiguration(paths: [], isDevMode: true);
        if (\PHP_VERSION_ID >= 80400) {
            $config->enableNativeLazyObjects(true);
        }
        $config->addFilter('tenancy_aware', TenantAwareFilter::class);
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config);
        $this->em = new EntityManager($connection, $config);
        $this->filter = $this->em->getFilters()->enable('tenancy_aware');
    }

    private function makeTenantAwareMetadata(): ClassMetadata
    {
        /** @var ClassMetadata<TenantAwareEntity> $metadata */
        $metadata = new ClassMetadata(TenantAwareEntity::class);
        $metadata->reflClass = new \ReflectionClass(TenantAwareEntity::class);

        return $metadata;
    }

    private function makeNonTenantAwareMetadata(): ClassMetadata
    {
        /** @var ClassMetadata<NonTenantAwareEntity> $metadata */
        $metadata = new ClassMetadata(NonTenantAwareEntity::class);
        $metadata->reflClass = new \ReflectionClass(NonTenantAwareEntity::class);

        return $metadata;
    }

    private function makeActiveTenant(string $slug): TenantInterface
    {
        return new class($slug) implements TenantInterface {
            public function __construct(private readonly string $slug)
            {
            }

            public function getSlug(): string
            {
                return $this->slug;
            }

            public function getDomain(): ?string
            {
                return null;
            }

            public function getConnectionConfig(): array
            {
                return [];
            }

            public function getName(): string
            {
                return $this->slug;
            }

            public function isActive(): bool
            {
                return true;
            }
        };
    }

    public function testReturnsEmptyStringForNonTenantAwareEntity(): void
    {
        $metadata = $this->makeNonTenantAwareMetadata();
        $context = new TenantContext();
        $context->setTenant($this->makeActiveTenant('acme'));
        $this->filter->setTenantContext($context, true);

        $result = $this->filter->addFilterConstraint($metadata, 't0');
        $this->assertSame('', $result);
    }

    public function testReturnsEmptyStringWhenReflClassIsNull(): void
    {
        /** @var ClassMetadata<NonTenantAwareEntity> $metadata */
        $metadata = new ClassMetadata(NonTenantAwareEntity::class);
        $metadata->reflClass = null;

        $context = new TenantContext();
        $context->setTenant($this->makeActiveTenant('acme'));
        $this->filter->setTenantContext($context, true);

        $result = $this->filter->addFilterConstraint($metadata, 't0');
        $this->assertSame('', $result);
    }

    public function testReturnsWhereFragmentWhenTenantActiveAndEntityIsTenantAware(): void
    {
        $metadata = $this->makeTenantAwareMetadata();
        $context = new TenantContext();
        $context->setTenant($this->makeActiveTenant('acme'));
        $this->filter->setTenantContext($context, true);

        $result = $this->filter->addFilterConstraint($metadata, 't0');
        $this->assertSame("t0.tenant_id = 'acme'", $result);
    }

    public function testThrowsTenantMissingExceptionInStrictModeWhenNoTenantActive(): void
    {
        $metadata = $this->makeTenantAwareMetadata();
        $context = new TenantContext(); // no tenant set
        $this->filter->setTenantContext($context, true);

        $this->expectException(TenantMissingException::class);
        $this->filter->addFilterConstraint($metadata, 't0');
    }

    public function testReturnsEmptyStringInPermissiveModeWhenNoTenantActive(): void
    {
        $metadata = $this->makeTenantAwareMetadata();
        $context = new TenantContext(); // no tenant set
        $this->filter->setTenantContext($context, false);

        $result = $this->filter->addFilterConstraint($metadata, 't0');
        $this->assertSame('', $result);
    }

    public function testExceptionMessageIncludesEntityClassName(): void
    {
        $metadata = $this->makeTenantAwareMetadata();
        $context = new TenantContext(); // no tenant set
        $this->filter->setTenantContext($context, true);

        try {
            $this->filter->addFilterConstraint($metadata, 't0');
            $this->fail('Expected TenantMissingException to be thrown');
        } catch (TenantMissingException $e) {
            $this->assertStringContainsString(TenantAwareEntity::class, $e->getMessage());
        }
    }

    public function testReturnsEmptyStringWhenSetTenantContextNeverCalled(): void
    {
        $metadata = $this->makeTenantAwareMetadata();

        // Do NOT call setTenantContext — test the uninitialized guard
        $result = $this->filter->addFilterConstraint($metadata, 't0');
        $this->assertSame('', $result);
    }
}
