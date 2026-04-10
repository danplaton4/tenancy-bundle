<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\Kernel;
use Tenancy\Bundle\Bootstrapper\BootstrapperChain;
use Tenancy\Bundle\Bootstrapper\TenantBootstrapperInterface;
use Tenancy\Bundle\TenancyBundle;
use Tenancy\Bundle\TenantInterface;
use Tenancy\Bundle\Tests\Integration\Support\ReplaceTenancyProviderPass;

/**
 * Dummy bootstrapper for autoconfiguration testing.
 * Implements TenantBootstrapperInterface — should be auto-tagged as tenancy.bootstrapper.
 */
final class DummyBootstrapper implements TenantBootstrapperInterface
{
    public function boot(TenantInterface $tenant): void
    {
        // no-op test implementation
    }

    public function clear(): void
    {
        // no-op test implementation
    }
}

/**
 * Second dummy bootstrapper for multiple-bootstrapper test.
 */
final class AnotherDummyBootstrapper implements TenantBootstrapperInterface
{
    public function boot(TenantInterface $tenant): void
    {
        // no-op test implementation
    }

    public function clear(): void
    {
        // no-op test implementation
    }
}

/**
 * Compiler pass that exposes tenancy.bootstrapper_chain as public for test inspection.
 * Must run after BootstrapperChainPass so the service already has method calls wired.
 */
final class MakeBootstrapperChainPublicPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if ($container->has('tenancy.bootstrapper_chain')) {
            $container->getDefinition('tenancy.bootstrapper_chain')->setPublic(true);
        }
    }
}

/**
 * Test kernel that registers one DummyBootstrapper so autoconfiguration can tag it.
 */
final class SingleBootstrapperKernel extends Kernel
{
    public function __construct()
    {
        parent::__construct('test_autoconfig_single', false);
    }

    public function registerBundles(): iterable
    {
        return [new FrameworkBundle(), new TenancyBundle()];
    }

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->addCompilerPass(new ReplaceTenancyProviderPass());
        $container->addCompilerPass(new MakeBootstrapperChainPublicPass());
    }

    public function registerContainerConfiguration(LoaderInterface $loader): void
    {
        $loader->load(function (ContainerBuilder $container): void {
            $container->loadFromExtension('framework', [
                'secret' => 'test-secret',
                'test' => true,
                'http_method_override' => false,
                'handle_all_throwables' => true,
                'php_errors' => ['log' => true],
            ]);
            $container->register(DummyBootstrapper::class, DummyBootstrapper::class)
                ->setAutoconfigured(true)
                ->setPublic(true);
        });
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir().'/tenancy_autoconfig_single_'.md5(self::class).'/cache';
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir().'/tenancy_autoconfig_single_'.md5(self::class).'/logs';
    }
}

/**
 * Test kernel that registers two DummyBootstrappers.
 */
final class TwoBootstrappersKernel extends Kernel
{
    public function __construct()
    {
        parent::__construct('test_autoconfig_two', false);
    }

    public function registerBundles(): iterable
    {
        return [new FrameworkBundle(), new TenancyBundle()];
    }

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->addCompilerPass(new ReplaceTenancyProviderPass());
        $container->addCompilerPass(new MakeBootstrapperChainPublicPass());
    }

    public function registerContainerConfiguration(LoaderInterface $loader): void
    {
        $loader->load(function (ContainerBuilder $container): void {
            $container->loadFromExtension('framework', [
                'secret' => 'test-secret',
                'test' => true,
                'http_method_override' => false,
                'handle_all_throwables' => true,
                'php_errors' => ['log' => true],
            ]);
            $container->register(DummyBootstrapper::class, DummyBootstrapper::class)
                ->setAutoconfigured(true)
                ->setPublic(true);
            $container->register(AnotherDummyBootstrapper::class, AnotherDummyBootstrapper::class)
                ->setAutoconfigured(true)
                ->setPublic(true);
        });
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir().'/tenancy_autoconfig_two_'.md5(self::class).'/cache';
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir().'/tenancy_autoconfig_two_'.md5(self::class).'/logs';
    }
}

final class AutoconfigurationTest extends TestCase
{
    private static SingleBootstrapperKernel $singleKernel;
    private static TwoBootstrappersKernel $twoKernel;

    public static function setUpBeforeClass(): void
    {
        static::$singleKernel = new SingleBootstrapperKernel();
        static::$singleKernel->boot();

        static::$twoKernel = new TwoBootstrappersKernel();
        static::$twoKernel->boot();
    }

    public static function tearDownAfterClass(): void
    {
        static::$singleKernel->shutdown();
        static::$twoKernel->shutdown();
    }

    public function testBootstrapperInterfaceImplementationIsAutoTagged(): void
    {
        $container = static::$singleKernel->getContainer();

        // Retrieve BootstrapperChain from the container (made public via MakeBootstrapperChainPublicPass)
        $chain = $container->get('tenancy.bootstrapper_chain');
        $this->assertInstanceOf(BootstrapperChain::class, $chain);

        // Verify DummyBootstrapper was injected via the compiler pass + autoconfiguration
        // Access the private $bootstrappers property via reflection
        $reflection = new \ReflectionClass(BootstrapperChain::class);
        $bootstrappersProperty = $reflection->getProperty('bootstrappers');

        $bootstrappers = $bootstrappersProperty->getValue($chain);

        $this->assertIsArray($bootstrappers, 'BootstrapperChain::$bootstrappers must be an array');
        $this->assertCount(1, $bootstrappers, 'Exactly one bootstrapper should be registered');
        $this->assertInstanceOf(
            DummyBootstrapper::class,
            $bootstrappers[0],
            'DummyBootstrapper must be auto-tagged (via TenantBootstrapperInterface) and injected into BootstrapperChain',
        );
    }

    public function testMultipleBootstrappersAreAllCollected(): void
    {
        $container = static::$twoKernel->getContainer();

        $chain = $container->get('tenancy.bootstrapper_chain');
        $this->assertInstanceOf(BootstrapperChain::class, $chain);

        $reflection = new \ReflectionClass(BootstrapperChain::class);
        $bootstrappersProperty = $reflection->getProperty('bootstrappers');

        $bootstrappers = $bootstrappersProperty->getValue($chain);

        $this->assertIsArray($bootstrappers);
        $this->assertCount(2, $bootstrappers, 'Both bootstrappers should be collected by BootstrapperChainPass');

        $classes = array_map(fn (object $b) => $b::class, $bootstrappers);
        $this->assertContains(DummyBootstrapper::class, $classes);
        $this->assertContains(AnotherDummyBootstrapper::class, $classes);
    }

    public function testBootstrapperChainDefinitionHasAddBootstrapperMethodCall(): void
    {
        // Verify at the container-definition level (before compilation) that
        // BootstrapperChainPass wires addBootstrapper method calls for tenancy.bootstrapper-tagged services.
        $container = new ContainerBuilder();

        // Minimal: register a synthetic event_dispatcher (required by BootstrapperChain)
        $container->register('event_dispatcher', \Symfony\Contracts\EventDispatcher\EventDispatcherInterface::class)
            ->setSynthetic(true);

        // Register BootstrapperChain
        $container->register('tenancy.bootstrapper_chain', BootstrapperChain::class)
            ->addArgument(new Reference('event_dispatcher'))
            ->setPublic(true);
        $container->setAlias(BootstrapperChain::class, 'tenancy.bootstrapper_chain')->setPublic(true);

        // Manually register DummyBootstrapper with the tenancy.bootstrapper tag
        // (simulates what registerForAutoconfiguration does in TenancyBundle::loadExtension)
        $container->register(DummyBootstrapper::class, DummyBootstrapper::class)
            ->addTag('tenancy.bootstrapper')
            ->setPublic(true);

        // Add and run the BootstrapperChainPass (as TenancyBundle::build() does)
        $bundle = new TenancyBundle();
        $bundle->build($container);

        $container->compile();

        $definition = $container->findDefinition(BootstrapperChain::class);
        $methodCalls = $definition->getMethodCalls();

        $addBootstrapperCalls = array_filter(
            $methodCalls,
            fn (array $call) => 'addBootstrapper' === $call[0],
        );

        $this->assertNotEmpty(
            $addBootstrapperCalls,
            'BootstrapperChainPass must add addBootstrapper method call for tagged services',
        );
    }
}
