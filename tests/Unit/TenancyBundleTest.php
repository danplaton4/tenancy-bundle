<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Tenancy\Bundle\DependencyInjection\Compiler\BootstrapperChainPass;
use Tenancy\Bundle\DependencyInjection\Compiler\MessengerMiddlewarePass;
use Tenancy\Bundle\DependencyInjection\Compiler\ResolverChainPass;
use Tenancy\Bundle\TenancyBundle;

final class TenancyBundleTest extends TestCase
{
    private TenancyBundle $bundle;

    protected function setUp(): void
    {
        $this->bundle = new TenancyBundle();
    }

    private function createDefinitionConfigurator(): DefinitionConfigurator
    {
        $treeBuilder = new TreeBuilder('tenancy');
        $locator = new \Symfony\Component\Config\Definition\Loader\DefinitionFileLoader($treeBuilder, new FileLocator());

        return new DefinitionConfigurator($treeBuilder, $locator, '', '');
    }

    private function processConfig(array $userConfig = []): array
    {
        $configurator = $this->createDefinitionConfigurator();
        $this->bundle->configure($configurator);

        $treeBuilder = (new \ReflectionProperty(DefinitionConfigurator::class, 'treeBuilder'))->getValue($configurator);
        $processor = new Processor();

        return $processor->process($treeBuilder->buildTree(), $userConfig);
    }

    public function testConfigureDefinesDriverNode(): void
    {
        $config = $this->processConfig();
        $this->assertSame('database_per_tenant', $config['driver']);
    }

    public function testConfigureDefinesStrictModeDefaultTrue(): void
    {
        $config = $this->processConfig();
        $this->assertTrue($config['strict_mode']);
    }

    public function testConfigureDefinesDatabaseEnabledDefaultFalse(): void
    {
        $config = $this->processConfig();
        $this->assertFalse($config['database']['enabled']);
    }

    public function testConfigureDefinesResolversDefault(): void
    {
        $config = $this->processConfig();
        $this->assertSame(['host', 'header', 'query_param', 'console'], $config['resolvers']);
    }

    public function testConfigureDefinesHostAppDomainDefaultNull(): void
    {
        $config = $this->processConfig();
        $this->assertNull($config['host']['app_domain']);
    }

    public function testConfigureDefinesLandlordConnectionDefault(): void
    {
        $config = $this->processConfig();
        $this->assertSame('default', $config['landlord_connection']);
    }

    public function testConfigureDefinesTenantEntityClassDefault(): void
    {
        $config = $this->processConfig();
        $this->assertSame('Tenancy\\Bundle\\Entity\\Tenant', $config['tenant_entity_class']);
    }

    public function testConfigureRejectsSharedDbWithDatabaseEnabled(): void
    {
        $this->expectException(\Symfony\Component\Config\Definition\Exception\InvalidConfigurationException::class);
        $this->expectExceptionMessage('shared_db cannot be combined with tenancy.database.enabled');

        $this->processConfig([
            ['driver' => 'shared_db', 'database' => ['enabled' => true]],
        ]);
    }

    public function testConfigureAllowsCustomValues(): void
    {
        $config = $this->processConfig([
            [
                'driver' => 'shared_db',
                'strict_mode' => false,
                'landlord_connection' => 'main',
                'tenant_entity_class' => 'App\\Entity\\Tenant',
                'host' => ['app_domain' => 'example.com'],
                'resolvers' => ['host', 'header'],
            ],
        ]);

        $this->assertSame('shared_db', $config['driver']);
        $this->assertFalse($config['strict_mode']);
        $this->assertSame('main', $config['landlord_connection']);
        $this->assertSame('App\\Entity\\Tenant', $config['tenant_entity_class']);
        $this->assertSame('example.com', $config['host']['app_domain']);
        $this->assertSame(['host', 'header'], $config['resolvers']);
    }

    public function testBuildRegistersBootstrapperChainPass(): void
    {
        $container = new ContainerBuilder();
        $this->bundle->build($container);

        $passes = $container->getCompilerPassConfig()->getBeforeOptimizationPasses();
        $passClasses = array_map(fn ($pass) => $pass::class, $passes);

        $this->assertContains(BootstrapperChainPass::class, $passClasses);
    }

    public function testBuildRegistersResolverChainPass(): void
    {
        $container = new ContainerBuilder();
        $this->bundle->build($container);

        $passes = $container->getCompilerPassConfig()->getBeforeOptimizationPasses();
        $passClasses = array_map(fn ($pass) => $pass::class, $passes);

        $this->assertContains(ResolverChainPass::class, $passClasses);
    }

    public function testBuildRegistersMessengerMiddlewarePassWhenMessengerAvailable(): void
    {
        if (!interface_exists(\Symfony\Component\Messenger\MessageBusInterface::class)) {
            $this->markTestSkipped('symfony/messenger not installed');
        }

        $container = new ContainerBuilder();
        $this->bundle->build($container);

        $passes = $container->getCompilerPassConfig()->getBeforeOptimizationPasses();
        $passClasses = array_map(fn ($pass) => $pass::class, $passes);

        $this->assertContains(MessengerMiddlewarePass::class, $passClasses);
    }

    public function testPrependExtensionRegistersSharedDbFilter(): void
    {
        $containerConfigurator = $this->createMock(ContainerConfigurator::class);
        $containerBuilder = $this->createMock(ContainerBuilder::class);

        $containerBuilder
            ->expects($this->once())
            ->method('getExtensionConfig')
            ->with('tenancy')
            ->willReturn([['driver' => 'shared_db']]);

        $capturedConfigs = [];
        $containerBuilder
            ->method('prependExtensionConfig')
            ->with('doctrine', $this->callback(function (array $config) use (&$capturedConfigs): bool {
                $capturedConfigs[] = $config;

                return true;
            }));

        $this->bundle->prependExtension($containerConfigurator, $containerBuilder);

        $this->assertCount(2, $capturedConfigs);

        $filterConfig = $capturedConfigs[1];
        $this->assertArrayHasKey('orm', $filterConfig);
        $this->assertArrayHasKey('filters', $filterConfig['orm']);
        $this->assertArrayHasKey('tenancy_aware', $filterConfig['orm']['filters']);
        $this->assertTrue($filterConfig['orm']['filters']['tenancy_aware']['enabled']);
    }

    public function testPrependExtensionDoesNotRegisterFilterForDatabasePerTenant(): void
    {
        $containerConfigurator = $this->createMock(ContainerConfigurator::class);
        $containerBuilder = $this->createMock(ContainerBuilder::class);

        $containerBuilder
            ->method('getExtensionConfig')
            ->with('tenancy')
            ->willReturn([['driver' => 'database_per_tenant']]);

        $capturedConfigs = [];
        $containerBuilder
            ->method('prependExtensionConfig')
            ->with('doctrine', $this->callback(function (array $config) use (&$capturedConfigs): bool {
                $capturedConfigs[] = $config;

                return true;
            }));

        $this->bundle->prependExtension($containerConfigurator, $containerBuilder);

        $this->assertCount(1, $capturedConfigs);
        $this->assertArrayNotHasKey('filters', $capturedConfigs[0]['orm'] ?? []);
    }
}
