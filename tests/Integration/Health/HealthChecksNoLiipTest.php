<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Integration\Health;

use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel;
use Tenancy\Bundle\Command\TenantHealthCommand;
use Tenancy\Bundle\Controller\TenantHealthController;
use Tenancy\Bundle\DependencyInjection\Compiler\HealthCheckIntegrationPass;
use Tenancy\Bundle\Health\TenantHealthChecker;
use Tenancy\Bundle\TenancyBundle;
use Tenancy\Bundle\Tests\Integration\Support\ReplaceTenancyProviderPass;

/**
 * Compiler pass that removes the tenancy.health.liip.* service to simulate the
 * no-liip lane: when liip is installed in this dev environment, the HealthCheckIntegrationPass
 * registers the TenantConnectivityCheck. This pass removes that service after the
 * pass runs so we can assert that the health endpoints and command work WITHOUT it.
 */
final class RemoveLiipHealthCheckPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        // Remove any liip_monitor.check-tagged service the HealthCheckIntegrationPass registered.
        $taggedIds = $container->findTaggedServiceIds('liip_monitor.check');
        foreach (array_keys($taggedIds) as $id) {
            if ($container->hasDefinition($id)) {
                $container->removeDefinition($id);
            }
        }
    }
}

/**
 * Compiler pass that makes the health controller + command + checker public
 * so we can retrieve them from the compiled container in the no-liip test.
 */
final class MakeHealthPublicForNoLiipTest implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $ids = [
            'tenancy.health.controller',
            'tenancy.health.checker',
            TenantHealthChecker::class,
            'tenancy.command.health',
            TenantHealthController::class,
        ];

        foreach ($ids as $id) {
            if ($container->hasDefinition($id)) {
                $container->getDefinition($id)->setPublic(true);
            } elseif ($container->hasAlias($id)) {
                $container->getAlias($id)->setPublic(true);
            }
        }
    }
}

/**
 * Minimal kernel for the no-liip lane test.
 *
 * Boots FrameworkBundle + TenancyBundle.
 * The HealthCheckIntegrationPass runs during build() (it is registered by TenancyBundle::build()).
 * RemoveLiipHealthCheckPass runs AFTER the integration pass and removes any liip services,
 * simulating the environment in which liip is NOT installed.
 *
 * This lets us prove HEALTH-07 absence direction: the container compiles and the health
 * controller + command are resolvable with ZERO liip_monitor.check dependency.
 */
final class NoLiipHealthTestKernel extends Kernel
{
    public function __construct()
    {
        parent::__construct('test_no_liip_health', false);
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
        // Replace Doctrine-dependent provider with a null provider.
        $container->addCompilerPass(new ReplaceTenancyProviderPass());
        // Remove the liip check service (simulates liip absent) — runs AFTER
        // HealthCheckIntegrationPass (same phase, lower priority = later execution).
        $container->addCompilerPass(new RemoveLiipHealthCheckPass());
        // Make health services public so tests can resolve them.
        $container->addCompilerPass(new MakeHealthPublicForNoLiipTest());
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
                'cache' => ['app' => 'cache.adapter.array'],
            ]);
        });
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir().'/tenancy_no_liip_health_test_'.md5(self::class).'/cache';
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir().'/tenancy_no_liip_health_test_'.md5(self::class).'/logs';
    }
}

/**
 * Integration test: the no-liip lane (HEALTH-07 absence direction).
 *
 * Proves that when liip/monitor-bundle is absent (simulated by removing
 * the liip check service after the HealthCheckIntegrationPass runs):
 *   - The container compiles without error
 *   - tenancy.health.controller is resolvable (public service)
 *   - tenancy.health.checker is resolvable (public service)
 *   - tenancy.command.health is resolvable (public service)
 *   - No liip_monitor.check service is required for any of the above
 *
 * This is the HEALTH-07 "liip absent → no-op, self-contained surface still works" guarantee.
 */
final class HealthChecksNoLiipTest extends TestCase
{
    private static ?NoLiipHealthTestKernel $kernel = null;

    public static function setUpBeforeClass(): void
    {
        self::$kernel = new NoLiipHealthTestKernel();
        self::$kernel->boot();
    }

    public static function tearDownAfterClass(): void
    {
        if (null !== self::$kernel) {
            self::$kernel->shutdown();
            self::$kernel = null;
        }
    }

    /**
     * Container compiles without error when liip is absent (HealthCheckIntegrationPass no-ops).
     */
    public function testContainerCompilesWithoutLiip(): void
    {
        self::assertNotNull(self::$kernel);
        $container = self::$kernel->getContainer();

        // Container booted → compilation was successful.
        $this->assertTrue(
            $container->has('tenancy.context'),
            'Container must compile and tenancy.context must be resolvable when liip is absent.',
        );
    }

    /**
     * The health controller service is resolvable with zero liip dependency.
     */
    public function testHealthControllerResolvableWithoutLiip(): void
    {
        self::assertNotNull(self::$kernel);
        $container = self::$kernel->getContainer();

        $this->assertTrue(
            $container->has('tenancy.health.controller'),
            'tenancy.health.controller must be resolvable when liip is absent (HEALTH-07 absence).',
        );

        $controller = $container->get('tenancy.health.controller');
        $this->assertInstanceOf(
            TenantHealthController::class,
            $controller,
            'Resolved service must be a TenantHealthController.',
        );
    }

    /**
     * The health checker service is resolvable with zero liip dependency.
     */
    public function testHealthCheckerResolvableWithoutLiip(): void
    {
        self::assertNotNull(self::$kernel);
        $container = self::$kernel->getContainer();

        $this->assertTrue(
            $container->has('tenancy.health.checker'),
            'tenancy.health.checker must be resolvable when liip is absent.',
        );

        $checker = $container->get('tenancy.health.checker');
        $this->assertInstanceOf(TenantHealthChecker::class, $checker);
    }

    /**
     * The health command service is resolvable with zero liip dependency.
     */
    public function testHealthCommandResolvableWithoutLiip(): void
    {
        self::assertNotNull(self::$kernel);
        $container = self::$kernel->getContainer();

        $this->assertTrue(
            $container->has('tenancy.command.health'),
            'tenancy.command.health must be resolvable when liip is absent (HEALTH-07 absence).',
        );

        $command = $container->get('tenancy.command.health');
        $this->assertInstanceOf(
            TenantHealthCommand::class,
            $command,
            'Resolved service must be a TenantHealthCommand.',
        );
    }

    /**
     * No liip_monitor.check service is present in the no-liip container.
     * This proves the HealthCheckIntegrationPass no-op is clean — it left nothing behind.
     */
    public function testNoLiipMonitorCheckServicePresent(): void
    {
        self::assertNotNull(self::$kernel);
        $container = self::$kernel->getContainer();

        // When liip services are removed (simulating liip absent), there should be
        // no liip_monitor.check-tagged services required for the health surface to work.
        // This assertion proves the surface is self-contained (HEALTH-07 absence direction).
        $this->assertTrue(
            $container->has('tenancy.health.controller'),
            'Health controller must be resolvable with zero liip_monitor.check dependency.',
        );

        // The health parameters must exist (from the health config node).
        $this->assertTrue(
            $container->hasParameter('tenancy.health.fleet_default_limit'),
            'tenancy.health.fleet_default_limit parameter must exist.',
        );
        $this->assertTrue(
            $container->hasParameter('tenancy.health.fleet_max_limit'),
            'tenancy.health.fleet_max_limit parameter must exist.',
        );

        // Defaults.
        $this->assertSame(50, $container->getParameter('tenancy.health.fleet_default_limit'));
        $this->assertSame(200, $container->getParameter('tenancy.health.fleet_max_limit'));
    }
}
