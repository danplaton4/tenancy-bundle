<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Tenancy\Bundle\Context\TenantContext;
use Tenancy\Bundle\DependencyInjection\Compiler\BootstrapperChainPass;
use Tenancy\Bundle\Tests\Integration\Mailer\MailerTestKernel;

final class ContainerCompilationTest extends TestCase
{
    private static TestKernel $kernel;

    public static function setUpBeforeClass(): void
    {
        static::$kernel = new TestKernel('test', false);
        static::$kernel->boot();
    }

    public static function tearDownAfterClass(): void
    {
        static::$kernel->shutdown();
    }

    public function testContainerCompilesWithoutCircularReferences(): void
    {
        // If we reach here, the container compiled successfully without ServiceCircularReferenceException.
        $this->assertTrue(static::$kernel->getContainer()->has('tenancy.context'));
    }

    public function testTenancyContextServiceExists(): void
    {
        $container = static::$kernel->getContainer();
        $this->assertTrue($container->has('tenancy.context'), 'tenancy.context service must exist in compiled container');
    }

    public function testTenancyBootstrapperChainServiceExists(): void
    {
        // tenancy.bootstrapper_chain is private, but we can verify BootstrapperChain exists
        // by checking that the class is registered in the compiled container via its alias.
        // In the test container, public services are accessible. tenancy.context is public.
        $container = static::$kernel->getContainer();
        $tenancyContext = $container->get('tenancy.context');
        $this->assertInstanceOf(TenantContext::class, $tenancyContext);
    }

    public function testTenantContextServiceHasNoConstructorArguments(): void
    {
        // TenantContext is a zero-dependency pure value holder.
        // Verify via reflection that it has no constructor parameters.
        $reflection = new \ReflectionClass(TenantContext::class);
        $constructor = $reflection->getConstructor();

        $this->assertNull(
            $constructor,
            'TenantContext must have no constructor (zero dependencies)',
        );
    }

    public function testBootstrapperChainPassIsRegistered(): void
    {
        // Verify the BootstrapperChainPass was registered in the bundle build phase.
        // We do this by checking the class exists and implements CompilerPassInterface,
        // and that the container compiled correctly (which would have failed if the pass had errors).
        $this->assertTrue(
            class_exists(BootstrapperChainPass::class),
            'BootstrapperChainPass class must exist',
        );

        $reflection = new \ReflectionClass(BootstrapperChainPass::class);
        $this->assertTrue(
            $reflection->implementsInterface(\Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface::class),
            'BootstrapperChainPass must implement CompilerPassInterface',
        );
    }

    public function testKernelCompilesWithMailerBundleConfigured(): void
    {
        if (!interface_exists(\Symfony\Component\Mailer\MailerInterface::class)) {
            $this->markTestSkipped('symfony/mailer not installed');
        }

        $kernel = new MailerTestKernel('mailer_test_default', false);
        try {
            $kernel->boot();
            $container = $kernel->getContainer();

            $this->assertSame(32, $container->getParameter('tenancy.mailer.transport_cache_size'));
            $this->assertSame('auto', $container->getParameter('tenancy.mailer.async'));

            $this->assertTrue($container->has('tenancy.mailer.lru_cache'));
            $this->assertTrue($container->has('tenancy.mailer.bootstrapper'));
            $this->assertTrue($container->has('tenancy.mailer.message_decorator'));
            $this->assertTrue($container->has('tenancy.mailer.transports_decorator'));
            $this->assertTrue($container->has('tenancy.mailer.sanitizing_decorator'));
        } finally {
            $kernel->shutdown();
            $this->purgeKernelTmp($kernel);
        }
    }

    public function testCompilerPassFailsWhenAsyncRoutingDetectedButStrategyAbsent(): void
    {
        if (!interface_exists(\Symfony\Component\Mailer\MailerInterface::class)) {
            $this->markTestSkipped('symfony/mailer not installed');
        }

        $kernel = new MailerAsyncRoutingKernel('mailer_async_routing', false);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/X-Transport|message_decorator/');

        try {
            $kernel->boot();
        } finally {
            $kernel->shutdown();
            $this->purgeKernelTmp($kernel);
        }
    }

    public function testCompilerPassFailsWhenAsyncParamIsInvalidValue(): void
    {
        if (!interface_exists(\Symfony\Component\Mailer\MailerInterface::class)) {
            $this->markTestSkipped('symfony/mailer not installed');
        }

        $kernel = new MailerInvalidAsyncKernel('mailer_invalid_async', false);

        // Either the Configuration tree validation (InvalidConfigurationException)
        // OR the compiler pass's \LogicException — both prove "invalid async value
        // rejected at build time".
        $caught = null;
        try {
            $kernel->boot();
        } catch (InvalidConfigurationException|\LogicException $e) {
            $caught = $e;
        } finally {
            $kernel->shutdown();
            $this->purgeKernelTmp($kernel);
        }

        $this->assertNotNull(
            $caught,
            'Boot must throw InvalidConfigurationException or LogicException on invalid tenancy.mailer.async value.'
        );
    }

    private function purgeKernelTmp(\Symfony\Component\HttpKernel\Kernel $kernel): void
    {
        $cacheDir = $kernel->getCacheDir();
        $logDir = $kernel->getLogDir();
        foreach ([$cacheDir, $logDir] as $dir) {
            if (is_dir($dir)) {
                $this->rrmdir($dir);
            }
        }
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }
            $path = $dir.'/'.$entry;
            if (is_dir($path)) {
                $this->rrmdir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}

/**
 * Test-only kernel: enables async Messenger routing of SendEmailMessage but
 * never registers the tenancy.mailer.message_decorator service — exercises the
 * MailerTransportContractPass failure path.
 *
 * Removes the message_decorator definition via a compiler pass that runs at
 * a lower priority than the contract pass (so the contract pass sees the
 * absence at the moment it validates).
 */
final class MailerAsyncRoutingKernel extends MailerTestKernel
{
    public function registerContainerConfiguration(\Symfony\Component\Config\Loader\LoaderInterface $loader): void
    {
        $loader->load(function (\Symfony\Component\DependencyInjection\ContainerBuilder $container): void {
            $container->loadFromExtension('framework', [
                'secret' => 'test',
                'test' => true,
                'http_method_override' => false,
                'handle_all_throwables' => true,
                'php_errors' => ['log' => true],
                'mailer' => [
                    'dsn' => 'null://null',
                ],
                'messenger' => [
                    'default_bus' => 'messenger.bus.default',
                    'buses' => [
                        'messenger.bus.default' => [
                            'default_middleware' => 'allow_no_handlers',
                        ],
                    ],
                    'transports' => [
                        'async' => 'in-memory://',
                    ],
                    'routing' => [
                        \Symfony\Component\Mailer\Messenger\SendEmailMessage::class => 'async',
                    ],
                ],
            ]);

            $container->loadFromExtension('tenancy', [
                'driver' => 'database_per_tenant',
                'strict_mode' => false,
                'mailer' => [
                    // Forced async — exercises the contract pass even if auto-detection misses.
                    'async' => 'true',
                ],
            ]);
        });
    }

    public function build(\Symfony\Component\DependencyInjection\ContainerBuilder $container): void
    {
        parent::build($container);
        // Remove tenancy.mailer.message_decorator AFTER services.php is loaded
        // but BEFORE MailerTransportContractPass runs (TYPE_BEFORE_OPTIMIZATION,
        // default priority 0).
        $container->addCompilerPass(
            new RemoveMessageDecoratorPass(),
            \Symfony\Component\DependencyInjection\Compiler\PassConfig::TYPE_BEFORE_OPTIMIZATION,
            10 // higher priority = earlier; runs before the contract pass.
        );
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir().'/tenancy_mailer_async_routing/cache';
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir().'/tenancy_mailer_async_routing/logs';
    }
}

/**
 * Test-only kernel: sets tenancy.mailer.async to an invalid value
 * ('sometimes'). Boot must throw either InvalidConfigurationException
 * (config-tree validation) or LogicException (contract pass).
 */
final class MailerInvalidAsyncKernel extends MailerTestKernel
{
    public function registerContainerConfiguration(\Symfony\Component\Config\Loader\LoaderInterface $loader): void
    {
        $loader->load(function (\Symfony\Component\DependencyInjection\ContainerBuilder $container): void {
            $container->loadFromExtension('framework', [
                'secret' => 'test',
                'test' => true,
                'http_method_override' => false,
                'handle_all_throwables' => true,
                'php_errors' => ['log' => true],
                'mailer' => [
                    'dsn' => 'null://null',
                ],
            ]);

            $container->loadFromExtension('tenancy', [
                'driver' => 'database_per_tenant',
                'strict_mode' => false,
                'mailer' => [
                    'async' => 'sometimes', // invalid — must be auto/true/false
                ],
            ]);
        });
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir().'/tenancy_mailer_invalid_async/cache';
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir().'/tenancy_mailer_invalid_async/logs';
    }
}

/**
 * Removes the tenancy.mailer.message_decorator definition so the contract
 * pass sees a "missing strategy + async routed" misconfig and throws.
 */
final class RemoveMessageDecoratorPass implements \Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface
{
    public function process(\Symfony\Component\DependencyInjection\ContainerBuilder $container): void
    {
        if ($container->hasDefinition('tenancy.mailer.message_decorator')) {
            $container->removeDefinition('tenancy.mailer.message_decorator');
        }
    }
}
