<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Integration\Mailer;

use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Mailer\Messenger\SendEmailMessage;
use Tenancy\Bundle\TenancyBundle;
use Tenancy\Bundle\Tests\Integration\Messenger\Support\ReplaceProviderWithStubPass;

/**
 * Minimal Symfony kernel for Mailer + Messenger integration tests (Phase 20).
 *
 * Registers FrameworkBundle + TenancyBundle with:
 *   - framework.mailer.dsn = null://null
 *     The landlord default. The async canary asserts THIS DSN is NEVER seen
 *     by the SpyTransport — only tenant DSNs should be used when routing
 *     through tenancy.mailer.transports_decorator.
 *   - Messenger enabled with:
 *       transports.sync = 'sync://'
 *       routing[SendEmailMessage::class] = 'sync'
 *     The sync transport STILL runs PhpSerializer encode→decode in-process
 *     (RESEARCH Finding 1) — this exercises the X-Transport-survives-PHP-
 *     serialize path and the worker middleware chain without requiring a
 *     real broker (Doctrine/AMQP/Redis).
 *   - Compiler passes:
 *       - ReplaceProviderWithStubPass: swaps the Doctrine-backed
 *         tenancy.provider with StubTenantProvider (supports addTenant()),
 *         replaces tenancy.doctrine_bootstrapper with a no-op, and removes
 *         the EntityManagerResetListener (no Doctrine bundle here).
 *       - MakeMailerServicesPublicPass: exposes Phase-20 mailer services
 *         and the messenger bus for $container->get() in tests.
 *       - ReplaceTenantTransportFactoryPass: overrides the
 *         TenantAwareTransportsDecorator's 6th positional arg
 *         (transportFactory Closure) with SpyTransportFactory::create() so
 *         tenant transports route to SpyTransport instead of real SMTP.
 *
 * Consumers: AsyncCanaryTest, LongRunningWorkerSimulationTest.
 */
class MailerTestKernel extends Kernel
{
    public function __construct(string $environment = 'mailer_test', bool $debug = false)
    {
        parent::__construct($environment, $debug);
    }

    public function registerBundles(): iterable
    {
        return [
            new FrameworkBundle(),
            new TenancyBundle(),
        ];
    }

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        // The provider-swap pass also adapts the doctrine bootstrapper +
        // EntityManagerResetListener so the kernel boots without Doctrine
        // ORM/DBAL bundles configured. The same StubTenantProvider used by
        // MessengerMiddlewareIntegrationTest is exposed here so the async
        // canary can register tenants via addTenant() and resolve them via
        // findBySlug().
        $container->addCompilerPass(new ReplaceProviderWithStubPass());

        // Expose Phase 20 services + messenger bus + event_dispatcher + mailer
        // alias for direct $container->get() inspection from tests.
        $container->addCompilerPass(new MakeMailerServicesPublicPass());

        // Inject the SpyTransport-producing Closure as the decorator's
        // transportFactory so tests verify ROUTING + DSN selection without
        // opening real SMTP sockets.
        $container->addCompilerPass(new ReplaceTenantTransportFactoryPass());
    }

    public function registerContainerConfiguration(LoaderInterface $loader): void
    {
        $loader->load(function (ContainerBuilder $container): void {
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
                        'sync' => 'sync://',
                    ],
                    'routing' => [
                        SendEmailMessage::class => 'sync',
                    ],
                ],
            ]);

            $container->loadFromExtension('tenancy', [
                'driver' => 'database_per_tenant',
                'strict_mode' => false,
            ]);
        });
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir().'/tenancy_mailer_test/cache';
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir().'/tenancy_mailer_test/logs';
    }
}
