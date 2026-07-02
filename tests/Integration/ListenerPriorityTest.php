<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\HttpKernel\KernelEvents;
use Tenancy\Bundle\EventListener\TenantContextOrchestrator;
use Tenancy\Bundle\EventListener\TenantMaintenanceModeListener;
use Tenancy\Bundle\TenancyBundle;
use Tenancy\Bundle\Tests\Integration\Support\ReplaceTenancyProviderPass;

final class ListenerPriorityTest extends TestCase
{
    private static TestKernel $kernel;

    /** Kernel with tenancy.maintenance.enabled: true — booted lazily by maintenance tests. */
    private static ?MaintenanceEnabledTestKernel $maintenanceKernel = null;

    public static function setUpBeforeClass(): void
    {
        static::$kernel = new TestKernel('test', false);
        static::$kernel->boot();
    }

    public static function tearDownAfterClass(): void
    {
        static::$kernel->shutdown();

        if (null !== static::$maintenanceKernel) {
            $cacheDir = static::$maintenanceKernel->getCacheDir();
            try {
                static::$maintenanceKernel->shutdown();
            } catch (\Throwable) {
                // best-effort
            }
            $fs = new Filesystem();
            $parent = \dirname($cacheDir);
            if ($fs->exists($parent)) {
                $fs->remove($parent);
            }
            static::$maintenanceKernel = null;
        }
    }

    private static function maintenanceKernel(): MaintenanceEnabledTestKernel
    {
        if (null === static::$maintenanceKernel) {
            static::$maintenanceKernel = new MaintenanceEnabledTestKernel('test', false);
            static::$maintenanceKernel->boot();
        }

        return static::$maintenanceKernel;
    }

    public function testOrchestratorRegisteredAtPriority20OnKernelRequest(): void
    {
        /** @var \Symfony\Component\EventDispatcher\EventDispatcherInterface $dispatcher */
        $dispatcher = static::$kernel->getContainer()->get('event_dispatcher');

        $found = false;
        $foundPriority = null;

        foreach ($dispatcher->getListeners(KernelEvents::REQUEST) as $listener) {
            if (is_array($listener) && $listener[0] instanceof TenantContextOrchestrator) {
                $found = true;
                $foundPriority = $dispatcher->getListenerPriority(KernelEvents::REQUEST, $listener);
                break;
            }
        }

        $this->assertTrue($found, 'TenantContextOrchestrator must be registered as a kernel.request listener');
        $this->assertSame(
            TenantContextOrchestrator::PRIORITY,
            $foundPriority,
            'TenantContextOrchestrator must be registered at priority '.TenantContextOrchestrator::PRIORITY.' on kernel.request',
        );
    }

    public function testOrchestratorRegisteredOnKernelTerminate(): void
    {
        /** @var \Symfony\Component\EventDispatcher\EventDispatcherInterface $dispatcher */
        $dispatcher = static::$kernel->getContainer()->get('event_dispatcher');

        $found = false;

        foreach ($dispatcher->getListeners(KernelEvents::TERMINATE) as $listener) {
            if (is_array($listener) && $listener[0] instanceof TenantContextOrchestrator) {
                $found = true;
                break;
            }
        }

        $this->assertTrue($found, 'TenantContextOrchestrator must be registered as a kernel.terminate listener');
    }

    public function testPriorityConstantMatchesRegisteredPriority(): void
    {
        // Double-check that the PRIORITY constant value matches what is actually registered.
        $this->assertSame(20, TenantContextOrchestrator::PRIORITY);
    }

    /**
     * Success Criterion 3 — end-to-end: the maintenance listener is registered at priority 16,
     * which is numerically less than TenantContextOrchestrator::PRIORITY (20), so the tenant
     * is guaranteed to be resolved before the maintenance gate runs.
     */
    public function testMaintenanceListenerRegisteredAtPriority16AfterOrchestrator(): void
    {
        $kernel = self::maintenanceKernel();

        /** @var \Symfony\Component\EventDispatcher\EventDispatcherInterface $dispatcher */
        $dispatcher = $kernel->getContainer()->get('event_dispatcher');

        $found = false;
        $foundPriority = null;

        foreach ($dispatcher->getListeners(KernelEvents::REQUEST) as $listener) {
            if (is_array($listener) && $listener[0] instanceof TenantMaintenanceModeListener) {
                $found = true;
                $foundPriority = $dispatcher->getListenerPriority(KernelEvents::REQUEST, $listener);
                break;
            }
        }

        $this->assertTrue($found, 'TenantMaintenanceModeListener must be registered as a kernel.request listener when maintenance.enabled: true');

        $this->assertSame(
            TenantMaintenanceModeListener::PRIORITY,
            $foundPriority,
            'TenantMaintenanceModeListener must be registered at priority '.TenantMaintenanceModeListener::PRIORITY.' on kernel.request',
        );

        // Core invariant: maintenance listener fires AFTER orchestrator (lower priority = later).
        $this->assertLessThan(
            TenantContextOrchestrator::PRIORITY,
            TenantMaintenanceModeListener::PRIORITY,
            sprintf(
                'TenantMaintenanceModeListener::PRIORITY (%d) must be < TenantContextOrchestrator::PRIORITY (%d) — tenant must be resolved before maintenance gate runs (Success Criterion 3)',
                TenantMaintenanceModeListener::PRIORITY,
                TenantContextOrchestrator::PRIORITY,
            ),
        );
    }

    /**
     * Sanity check: the PRIORITY constant is exactly 16, after orchestrator at 20.
     * Mirrors testPriorityConstantMatchesRegisteredPriority() for the maintenance listener.
     */
    public function testMaintenancePriorityConstantIs16(): void
    {
        $this->assertSame(16, TenantMaintenanceModeListener::PRIORITY);
    }
}

/**
 * Test kernel that boots TenancyBundle with tenancy.maintenance.enabled: true,
 * so the TenantMaintenanceModeListener is registered in the container.
 *
 * Uses ReplaceTenancyProviderPass to avoid requiring a Doctrine EM in the test environment.
 */
final class MaintenanceEnabledTestKernel extends Kernel
{
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
        $container->addCompilerPass(new ReplaceTenancyProviderPass());
        $container->addCompilerPass(new MakeMaintenanceListenerPublicPass());
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
                'cache' => ['app' => 'cache.adapter.array'],
            ]);

            $container->loadFromExtension('tenancy', [
                'maintenance' => [
                    'enabled' => true,
                    'retry_after' => 3600,
                ],
            ]);
        });
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir().'/tenancy_bundle_test_'.md5(static::class.getmypid()).'/cache';
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir().'/tenancy_bundle_test_'.md5(static::class.getmypid()).'/logs';
    }
}

/**
 * Exposes the maintenance listener service as public so we can fetch the
 * event_dispatcher and inspect its registered listeners in the test.
 * The event_dispatcher is already public in test kernels (framework.test: true).
 */
final class MakeMaintenanceListenerPublicPass implements \Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if ($container->hasDefinition('tenancy.maintenance.listener')) {
            $container->getDefinition('tenancy.maintenance.listener')->setPublic(true);
        }
    }
}
