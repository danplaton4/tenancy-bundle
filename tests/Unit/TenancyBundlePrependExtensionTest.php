<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Unit;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Tenancy\Bundle\TenancyBundle;

final class TenancyBundlePrependExtensionTest extends TestCase
{
    private TenancyBundle $bundle;
    private ContainerConfigurator&MockObject $containerConfigurator;
    private ContainerBuilder&MockObject $containerBuilder;

    protected function setUp(): void
    {
        $this->bundle = new TenancyBundle();
        $this->containerConfigurator = $this->createMock(ContainerConfigurator::class);
        $this->containerBuilder = $this->createMock(ContainerBuilder::class);
    }

    public function testPrependExtensionTargetsLandlordEmWhenDatabaseEnabled(): void
    {
        $this->containerBuilder
            ->expects($this->once())
            ->method('getExtensionConfig')
            ->with('tenancy')
            ->willReturn([['database' => ['enabled' => true]]]);

        $capturedConfig = null;
        $this->containerBuilder
            ->expects($this->once())
            ->method('prependExtensionConfig')
            ->with('doctrine', $this->callback(function (array $config) use (&$capturedConfig): bool {
                $capturedConfig = $config;

                return true;
            }));

        $this->bundle->prependExtension($this->containerConfigurator, $this->containerBuilder);

        $this->assertNotNull($capturedConfig, 'prependExtensionConfig must be called with doctrine config');
        $this->assertArrayHasKey('orm', $capturedConfig);
        $this->assertArrayHasKey('entity_managers', $capturedConfig['orm']);
        $this->assertArrayHasKey('landlord', $capturedConfig['orm']['entity_managers']);
        $this->assertArrayHasKey('mappings', $capturedConfig['orm']['entity_managers']['landlord']);
        $this->assertArrayHasKey('TenancyBundle', $capturedConfig['orm']['entity_managers']['landlord']['mappings']);

        $mapping = $capturedConfig['orm']['entity_managers']['landlord']['mappings']['TenancyBundle'];
        $this->assertFalse($mapping['is_bundle']);
        $this->assertSame('attribute', $mapping['type']);
        $this->assertStringEndsWith('/src/Entity', $mapping['dir']);
        $this->assertSame('Tenancy\\Bundle\\Entity', $mapping['prefix']);
        $this->assertSame('TenancyBundle', $mapping['alias']);

        // Ensure it does NOT write to the top-level orm.mappings
        $this->assertArrayNotHasKey('mappings', $capturedConfig['orm']);
    }

    public function testPrependExtensionTargetsTopLevelMappingsWhenDatabaseDisabled(): void
    {
        $this->containerBuilder
            ->expects($this->once())
            ->method('getExtensionConfig')
            ->with('tenancy')
            ->willReturn([['database' => ['enabled' => false]]]);

        $capturedConfig = null;
        $this->containerBuilder
            ->expects($this->once())
            ->method('prependExtensionConfig')
            ->with('doctrine', $this->callback(function (array $config) use (&$capturedConfig): bool {
                $capturedConfig = $config;

                return true;
            }));

        $this->bundle->prependExtension($this->containerConfigurator, $this->containerBuilder);

        $this->assertNotNull($capturedConfig, 'prependExtensionConfig must be called with doctrine config');
        $this->assertArrayHasKey('orm', $capturedConfig);
        $this->assertArrayHasKey('mappings', $capturedConfig['orm']);
        $this->assertArrayHasKey('TenancyBundle', $capturedConfig['orm']['mappings']);

        $mapping = $capturedConfig['orm']['mappings']['TenancyBundle'];
        $this->assertFalse($mapping['is_bundle']);
        $this->assertSame('attribute', $mapping['type']);
        $this->assertStringEndsWith('/src/Entity', $mapping['dir']);
        $this->assertSame('Tenancy\\Bundle\\Entity', $mapping['prefix']);
        $this->assertSame('TenancyBundle', $mapping['alias']);

        // Ensure it does NOT write to entity_managers.landlord
        $this->assertArrayNotHasKey('entity_managers', $capturedConfig['orm']);
    }

    public function testPrependExtensionTargetsTopLevelMappingsWhenNoConfig(): void
    {
        $this->containerBuilder
            ->expects($this->once())
            ->method('getExtensionConfig')
            ->with('tenancy')
            ->willReturn([]);

        $capturedConfig = null;
        $this->containerBuilder
            ->expects($this->once())
            ->method('prependExtensionConfig')
            ->with('doctrine', $this->callback(function (array $config) use (&$capturedConfig): bool {
                $capturedConfig = $config;

                return true;
            }));

        $this->bundle->prependExtension($this->containerConfigurator, $this->containerBuilder);

        $this->assertNotNull($capturedConfig, 'prependExtensionConfig must be called with doctrine config');
        $this->assertArrayHasKey('orm', $capturedConfig);
        $this->assertArrayHasKey('mappings', $capturedConfig['orm']);
        $this->assertArrayHasKey('TenancyBundle', $capturedConfig['orm']['mappings']);

        $mapping = $capturedConfig['orm']['mappings']['TenancyBundle'];
        $this->assertFalse($mapping['is_bundle']);
        $this->assertSame('attribute', $mapping['type']);
        $this->assertStringEndsWith('/src/Entity', $mapping['dir']);
        $this->assertSame('Tenancy\\Bundle\\Entity', $mapping['prefix']);
        $this->assertSame('TenancyBundle', $mapping['alias']);

        // Ensure it does NOT write to entity_managers.landlord
        $this->assertArrayNotHasKey('entity_managers', $capturedConfig['orm']);
    }
}
