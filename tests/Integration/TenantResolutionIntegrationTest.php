<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Kernel;
use Tenancy\Bundle\EventListener\TenantContextOrchestrator;
use Tenancy\Bundle\Resolver\ConsoleResolver;
use Tenancy\Bundle\Resolver\HeaderResolver;
use Tenancy\Bundle\Resolver\HostResolver;
use Tenancy\Bundle\Resolver\QueryParamResolver;
use Tenancy\Bundle\Resolver\ResolverChain;
use Tenancy\Bundle\Resolver\TenantResolverInterface;
use Tenancy\Bundle\TenancyBundle;
use Tenancy\Bundle\TenantInterface;
use Tenancy\Bundle\Tests\Integration\Support\ReplaceTenancyProviderPass;

/**
 * Compiler pass that exposes tenancy.resolver_chain as public for test inspection.
 * Analogous to MakeBootstrapperChainPublicPass used in AutoconfigurationTest.
 */
final class MakeResolverChainPublicPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if ($container->hasDefinition('tenancy.resolver_chain')) {
            $container->getDefinition('tenancy.resolver_chain')->setPublic(true);
        }
        // Also make the ResolverChain alias public so $container->get(ResolverChain::class) works
        if ($container->hasAlias(ResolverChain::class)) {
            $container->getAlias(ResolverChain::class)->setPublic(true);
        }
        // Also make TenantContextOrchestrator public for reflection inspection
        if ($container->has(TenantContextOrchestrator::class)) {
            $container->findDefinition(TenantContextOrchestrator::class)->setPublic(true);
        }
    }
}

/**
 * Kernel for resolver chain integration tests.
 * Exposes ResolverChain and TenantContextOrchestrator as public services.
 */
final class ResolverTestKernel extends Kernel
{
    public function __construct()
    {
        parent::__construct('test_resolver', false);
    }

    public function registerBundles(): iterable
    {
        return [new FrameworkBundle(), new TenancyBundle()];
    }

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->addCompilerPass(new ReplaceTenancyProviderPass());
        $container->addCompilerPass(new MakeResolverChainPublicPass());
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
        });
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir().'/tenancy_resolver_test_'.md5(self::class).'/cache';
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir().'/tenancy_resolver_test_'.md5(self::class).'/logs';
    }
}

/**
 * Dummy resolver for autoconfiguration testing.
 * Implements TenantResolverInterface — should be auto-tagged as tenancy.resolver.
 */
final class DummyTenantResolver implements TenantResolverInterface
{
    public function resolve(Request $request): ?TenantInterface
    {
        return null; // no-op test implementation
    }
}

/**
 * Kernel that also registers a custom DummyTenantResolver to verify autoconfiguration.
 */
final class ResolverWithCustomResolverKernel extends Kernel
{
    public function __construct()
    {
        parent::__construct('test_resolver_custom', false);
    }

    public function registerBundles(): iterable
    {
        return [new FrameworkBundle(), new TenancyBundle()];
    }

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->addCompilerPass(new ReplaceTenancyProviderPass());
        $container->addCompilerPass(new MakeResolverChainPublicPass());
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
            // Register the custom resolver with autoconfiguration so it gets the tenancy.resolver tag
            $container->register(DummyTenantResolver::class, DummyTenantResolver::class)
                ->setAutoconfigured(true)
                ->setPublic(true);
        });
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir().'/tenancy_resolver_custom_'.md5(self::class).'/cache';
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir().'/tenancy_resolver_custom_'.md5(self::class).'/logs';
    }
}

final class TenantResolutionIntegrationTest extends TestCase
{
    private static ResolverTestKernel $kernel;
    private static ResolverWithCustomResolverKernel $customKernel;

    public static function setUpBeforeClass(): void
    {
        static::$kernel = new ResolverTestKernel();
        static::$kernel->boot();

        static::$customKernel = new ResolverWithCustomResolverKernel();
        static::$customKernel->boot();
    }

    public static function tearDownAfterClass(): void
    {
        static::$kernel->shutdown();
        static::$customKernel->shutdown();
    }

    // -------------------------------------------------------------------------
    // ResolverChain service existence
    // -------------------------------------------------------------------------

    public function testResolverChainServiceExists(): void
    {
        $container = static::$kernel->getContainer();

        // ResolverChain is made public via MakeResolverChainPublicPass
        $this->assertTrue(
            $container->has(ResolverChain::class),
            'ResolverChain service must exist in compiled container',
        );

        $chain = $container->get(ResolverChain::class);
        $this->assertInstanceOf(ResolverChain::class, $chain);
    }

    // -------------------------------------------------------------------------
    // Built-in resolvers are wired into ResolverChain
    // -------------------------------------------------------------------------

    public function testResolverChainHasBuiltInResolvers(): void
    {
        $container = static::$kernel->getContainer();
        $chain = $container->get(ResolverChain::class);

        $reflection = new \ReflectionClass(ResolverChain::class);
        $resolversProperty = $reflection->getProperty('resolvers');
        $resolvers = $resolversProperty->getValue($chain);

        $this->assertIsArray($resolvers);

        $classes = array_map(fn (object $r) => $r::class, $resolvers);

        $this->assertContains(
            HostResolver::class,
            $classes,
            'ResolverChain must contain HostResolver',
        );
        $this->assertContains(
            HeaderResolver::class,
            $classes,
            'ResolverChain must contain HeaderResolver',
        );
        $this->assertContains(
            QueryParamResolver::class,
            $classes,
            'ResolverChain must contain QueryParamResolver',
        );

        // ConsoleResolver is NOT in the chain — it listens on ConsoleCommandEvent, not tagged as tenancy.resolver
        $this->assertNotContains(
            ConsoleResolver::class,
            $classes,
            'ConsoleResolver must NOT be in the HTTP ResolverChain (it is an event listener)',
        );
    }

    public function testResolverChainHasThreeBuiltInResolvers(): void
    {
        $container = static::$kernel->getContainer();
        $chain = $container->get(ResolverChain::class);

        $reflection = new \ReflectionClass(ResolverChain::class);
        $resolversProperty = $reflection->getProperty('resolvers');
        $resolvers = $resolversProperty->getValue($chain);

        $this->assertCount(
            3,
            $resolvers,
            'ResolverChain must contain exactly 3 built-in resolvers: HostResolver, HeaderResolver, QueryParamResolver',
        );
    }

    public function testResolverChainResolverPriorityOrder(): void
    {
        // Priority: HostResolver(30) > HeaderResolver(20) > QueryParamResolver(10)
        // ResolverChainPass uses PriorityTaggedServiceTrait which sorts DESC by priority,
        // so the chain array is [HostResolver, HeaderResolver, QueryParamResolver].
        $container = static::$kernel->getContainer();
        $chain = $container->get(ResolverChain::class);

        $reflection = new \ReflectionClass(ResolverChain::class);
        $resolversProperty = $reflection->getProperty('resolvers');
        $resolvers = $resolversProperty->getValue($chain);

        $this->assertInstanceOf(HostResolver::class, $resolvers[0], 'First resolver must be HostResolver (priority 30)');
        $this->assertInstanceOf(HeaderResolver::class, $resolvers[1], 'Second resolver must be HeaderResolver (priority 20)');
        $this->assertInstanceOf(QueryParamResolver::class, $resolvers[2], 'Third resolver must be QueryParamResolver (priority 10)');
    }

    // -------------------------------------------------------------------------
    // Custom resolver autoconfiguration
    // -------------------------------------------------------------------------

    public function testCustomResolverIsAutoTagged(): void
    {
        $container = static::$customKernel->getContainer();
        $chain = $container->get(ResolverChain::class);

        $reflection = new \ReflectionClass(ResolverChain::class);
        $resolversProperty = $reflection->getProperty('resolvers');
        $resolvers = $resolversProperty->getValue($chain);

        $classes = array_map(fn (object $r) => $r::class, $resolvers);

        $this->assertContains(
            DummyTenantResolver::class,
            $classes,
            'Custom resolver implementing TenantResolverInterface must be auto-tagged as tenancy.resolver and injected into ResolverChain',
        );
    }

    // -------------------------------------------------------------------------
    // ConsoleResolver is registered as event listener
    // -------------------------------------------------------------------------

    public function testConsoleResolverIsRegisteredAsEventListener(): void
    {
        // ConsoleResolver should exist in container (registered in services.php)
        // We verify its registration is in the event dispatcher for console.command event
        // by checking the class exists and is autoconfigured as an event listener.
        // Direct container access to event_dispatcher listeners is the reliable approach.
        $dispatcher = static::$kernel->getContainer()->get('event_dispatcher');

        $found = false;
        foreach ($dispatcher->getListeners() as $eventListeners) {
            foreach ($eventListeners as $listener) {
                if (is_array($listener) && $listener[0] instanceof ConsoleResolver) {
                    $found = true;
                    break 2;
                }
            }
        }

        $this->assertTrue($found, 'ConsoleResolver must be registered as a console.command event listener');
    }

    // -------------------------------------------------------------------------
    // TenantContextOrchestrator has ResolverChain injected
    // -------------------------------------------------------------------------

    public function testTenantContextOrchestratorHasResolverChainDependency(): void
    {
        $container = static::$kernel->getContainer();
        $orchestrator = $container->get(TenantContextOrchestrator::class);

        $this->assertInstanceOf(TenantContextOrchestrator::class, $orchestrator);

        $reflection = new \ReflectionClass(TenantContextOrchestrator::class);
        $resolverChainProperty = $reflection->getProperty('resolverChain');

        $resolverChain = $resolverChainProperty->getValue($orchestrator);

        $this->assertInstanceOf(
            ResolverChain::class,
            $resolverChain,
            'TenantContextOrchestrator must have a ResolverChain instance injected via constructor',
        );
    }

    // -------------------------------------------------------------------------
    // Bundle config defaults
    // -------------------------------------------------------------------------

    public function testBundleConfigResolversDefault(): void
    {
        $container = static::$kernel->getContainer();

        $this->assertTrue($container->hasParameter('tenancy.resolvers'), 'tenancy.resolvers parameter must exist');

        $resolvers = $container->getParameter('tenancy.resolvers');

        $this->assertSame(
            ['host', 'header', 'query_param', 'console'],
            $resolvers,
            'Default tenancy.resolvers must be [host, header, query_param, console]',
        );
    }

    public function testBundleConfigHostAppDomainDefault(): void
    {
        $container = static::$kernel->getContainer();

        $this->assertTrue($container->hasParameter('tenancy.host.app_domain'), 'tenancy.host.app_domain parameter must exist');

        $appDomain = $container->getParameter('tenancy.host.app_domain');

        $this->assertNull(
            $appDomain,
            'Default tenancy.host.app_domain must be null',
        );
    }
}
