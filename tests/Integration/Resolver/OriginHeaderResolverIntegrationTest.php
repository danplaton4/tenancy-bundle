<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Integration\Resolver;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Kernel;
use Tenancy\Bundle\Provider\TenantProviderInterface;
use Tenancy\Bundle\Resolver\OriginHeaderResolver;
use Tenancy\Bundle\Resolver\ResolverChain;
use Tenancy\Bundle\TenancyBundle;
use Tenancy\Bundle\Tests\Integration\Resolver\Support\RecordingLogger;
use Tenancy\Bundle\Tests\Integration\Resolver\Support\StubTenant;
use Tenancy\Bundle\Tests\Integration\Resolver\Support\StubTenantProvider;

/**
 * Compiler pass exposing tenancy.resolver_chain (+ ResolverChain alias) as public for test access.
 * Mirrors the pattern in tests/Integration/TenantResolutionIntegrationTest.php.
 */
final class MakeOriginResolverChainPublicPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if ($container->hasDefinition('tenancy.resolver_chain')) {
            $container->getDefinition('tenancy.resolver_chain')->setPublic(true);
        }
        if ($container->hasAlias(ResolverChain::class)) {
            $container->getAlias(ResolverChain::class)->setPublic(true);
        }
    }
}

/**
 * Replaces the tenancy.provider service definition with a StubTenantProvider.
 * Tenants are seeded after kernel boot via addTenant() on the service instance.
 */
final class SeedStubProviderPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $def = new Definition(StubTenantProvider::class);
        $def->setPublic(true);

        $container->setDefinition('tenancy.provider', $def);
        $container->setAlias(TenantProviderInterface::class, 'tenancy.provider')->setPublic(true);
    }
}

/**
 * Replaces the `logger` alias / service so OriginHeaderResolver's nullOnInvalid logger
 * arg resolves to our RecordingLogger instance, letting tests inspect warning records.
 */
final class ReplaceLoggerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $def = new Definition(RecordingLogger::class);
        $def->setPublic(true);
        $container->setDefinition('logger', $def);
        $container->setAlias(LoggerInterface::class, 'logger')->setPublic(true);
    }
}

final class OriginResolverTestKernel extends Kernel
{
    public function __construct()
    {
        parent::__construct('test_origin_resolver', false);
    }

    public function registerBundles(): iterable
    {
        return [new FrameworkBundle(), new TenancyBundle()];
    }

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->addCompilerPass(new SeedStubProviderPass());
        $container->addCompilerPass(new ReplaceLoggerPass());
        $container->addCompilerPass(new MakeOriginResolverChainPublicPass());
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
            $container->loadFromExtension('tenancy', [
                'resolvers' => ['header', 'origin'],
                'origin' => [
                    'allow_list' => [
                        ['origin' => 'https://acme.app.example.com', 'slug' => 'acme'],
                        'https://*.app.example.com',
                    ],
                ],
            ]);
        });
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir().'/tenancy_origin_test_'.md5(static::class).'/cache';
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir().'/tenancy_origin_test_'.md5(static::class).'/logs';
    }
}

final class EmptyOriginAllowListTestKernel extends Kernel
{
    public function __construct()
    {
        parent::__construct('test_origin_empty', false);
    }

    public function registerBundles(): iterable
    {
        return [new FrameworkBundle(), new TenancyBundle()];
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
            $container->loadFromExtension('tenancy', [
                'resolvers' => ['header', 'origin'],
                'origin' => [
                    'allow_list' => [],
                ],
            ]);
        });
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir().'/tenancy_origin_empty_test_'.md5(static::class).'/cache';
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir().'/tenancy_origin_empty_test_'.md5(static::class).'/logs';
    }
}

final class OriginHeaderResolverIntegrationTest extends TestCase
{
    private static OriginResolverTestKernel $kernel;

    public static function setUpBeforeClass(): void
    {
        self::$kernel = new OriginResolverTestKernel();
        self::$kernel->boot();

        // Seed the StubTenantProvider after boot — Definition::setFactory() does not
        // accept Closures, so we populate tenants here using addTenant() directly.
        $provider = self::$kernel->getContainer()->get(TenantProviderInterface::class);
        self::assertInstanceOf(StubTenantProvider::class, $provider);
        $provider->addTenant(new StubTenant('acme'));
        $provider->addTenant(new StubTenant('beta'));
    }

    public static function tearDownAfterClass(): void
    {
        self::$kernel->shutdown();
    }

    protected function setUp(): void
    {
        // Reset the RecordingLogger between tests so warning assertions are isolated.
        $logger = self::$kernel->getContainer()->get('logger');
        if ($logger instanceof RecordingLogger) {
            $logger->records = [];
        }
    }

    public function testOriginMatchResolvesTenant(): void
    {
        $chain = self::$kernel->getContainer()->get(ResolverChain::class);
        $this->assertInstanceOf(ResolverChain::class, $chain);

        $request = Request::create('/', 'GET', [], [], [], ['HTTP_ORIGIN' => 'https://acme.app.example.com']);
        $resolution = $chain->resolve($request);

        $this->assertNotNull($resolution);
        // NOTE: TenantResolution exposes promoted public readonly PROPERTIES — no getters.
        $this->assertSame('acme', $resolution->tenant->getSlug());
        $this->assertSame(OriginHeaderResolver::class, $resolution->resolvedBy);
    }

    public function testWildcardOriginMatchResolvesTenant(): void
    {
        $chain = self::$kernel->getContainer()->get(ResolverChain::class);
        $this->assertInstanceOf(ResolverChain::class, $chain);

        $request = Request::create('/', 'GET', [], [], [], ['HTTP_ORIGIN' => 'https://beta.app.example.com']);
        $resolution = $chain->resolve($request);

        $this->assertNotNull($resolution);
        $this->assertSame('beta', $resolution->tenant->getSlug());
        $this->assertSame(OriginHeaderResolver::class, $resolution->resolvedBy);
    }

    public function testOptionsPreflightReturnsNull(): void
    {
        $chain = self::$kernel->getContainer()->get(ResolverChain::class);
        $this->assertInstanceOf(ResolverChain::class, $chain);

        $request = Request::create('/', 'OPTIONS', [], [], [], ['HTTP_ORIGIN' => 'https://acme.app.example.com']);
        $resolution = $chain->resolve($request);

        $this->assertNull($resolution);
    }

    public function testMismatchWithXTenantIdLogsWarning(): void
    {
        $chain = self::$kernel->getContainer()->get(ResolverChain::class);
        $this->assertInstanceOf(ResolverChain::class, $chain);

        $request = Request::create('/', 'GET', [], [], [], [
            'HTTP_ORIGIN' => 'https://acme.app.example.com',
            'HTTP_X_TENANT_ID' => 'beta',
        ]);
        $resolution = $chain->resolve($request);

        $this->assertNotNull($resolution);
        $this->assertSame('acme', $resolution->tenant->getSlug(), 'Origin wins over X-Tenant-ID');

        $logger = self::$kernel->getContainer()->get('logger');
        $this->assertInstanceOf(RecordingLogger::class, $logger);
        $warnings = $logger->warnings();
        $this->assertCount(1, $warnings);
        $this->assertSame([
            'origin' => 'https://acme.app.example.com',
            'origin_slug' => 'acme',
            'header_slug' => 'beta',
            'winner' => 'origin',
        ], $warnings[0]['context']);
    }

    public function testEmptyAllowListFailsAtBoot(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('tenancy.origin.allow_list is empty but "origin" is configured in tenancy.resolvers');

        $kernel = new EmptyOriginAllowListTestKernel();
        $kernel->boot();
    }
}
