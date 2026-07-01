<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\Console\Tester\ApplicationTester;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpKernel\Kernel;
use Tenancy\Bundle\Provider\TenantProviderInterface;
use Tenancy\Bundle\Resolver\ConsoleResolver;
use Tenancy\Bundle\Resolver\HeaderResolver;
use Tenancy\Bundle\Resolver\HostResolver;
use Tenancy\Bundle\Resolver\QueryParamResolver;
use Tenancy\Bundle\TenancyBundle;

/**
 * Permanent GREEN-bar regression gate: zero-config kernel boot (no `tenancy:`
 * extension block).
 *
 * Registers TenancyBundle with NO `tenancy:` extension config block — exactly
 * the state a fresh `composer require danplaton4/tenancy-bundle` skeleton is in.
 * In that mode the resolver services receive null for their nullable
 * TenantProviderInterface constructor argument (via nullOnInvalid()); a
 * regression dropping the `?` from the type or the `= null` default would
 * re-introduce the TypeError this gate catches.
 */
final class ZeroConfigKernelBootTest extends TestCase
{
    private static ?ZeroConfigTestKernel $kernel = null;

    public static function setUpBeforeClass(): void
    {
        static::$kernel = new ZeroConfigTestKernel('test', false);
    }

    public static function tearDownAfterClass(): void
    {
        if (null === static::$kernel) {
            return;
        }

        $cacheDir = static::$kernel->getCacheDir();

        try {
            static::$kernel->shutdown();
        } catch (\Throwable) {
            // Kernel may not have booted; shutdown is best-effort.
        }

        // IN-04: $cacheDir and $logDir share the same parent dir (see
        // ZeroConfigTestKernel::getCacheDir/getLogDir) — remove the parent
        // exactly once. Removing it twice was a cosmetic no-op that
        // implied per-path semantics that don't exist.
        $fs = new Filesystem();
        $parent = \dirname($cacheDir);
        if ($fs->exists($parent)) {
            $fs->remove($parent);
        }

        static::$kernel = null;
    }

    /**
     * The container must compile and the kernel must boot without exception.
     * In the BROKEN state (before 18-09/18-10), this test FAILS because the
     * container compilation itself or the first service fetch during warmup
     * triggers the TypeError.
     *
     * Note: On some Symfony versions the TypeError is deferred until a service
     * is first fetched (lazy instantiation). In those cases this test may pass
     * on its own, but testHostResolverInstantiatesWithNullProvider() and
     * testConsoleApplicationVersionCommandExitsZero() will catch the regression.
     */
    public function testContainerCompilesAndKernelBoots(): void
    {
        self::assertNotNull(static::$kernel);
        static::$kernel->boot();

        // If we reach here, kernel booted. Verify basic container wiring is present.
        $this->assertTrue(
            static::$kernel->getContainer()->has('tenancy.context'),
            'tenancy.context must be registered even in zero-config mode',
        );
    }

    /**
     * Fetches HostResolver from the container — the first resolver registered with
     * nullOnInvalid() against tenancy.provider. On current master (broken state),
     * fetching this service passes null to the non-nullable TenantProviderInterface
     * constructor argument, throwing TypeError.
     *
     * This is the exact symptom from the 2026-05-21 human UAT:
     *   ConsoleResolver::__construct(): Argument #1 ($tenantProvider) must be of type
     *   TenantProviderInterface, null given
     *
     * Plans 18-09 and 18-10 will make these constructors nullable to fix the crash.
     */
    public function testHostResolverInstantiatesWithNullProvider(): void
    {
        self::assertNotNull(static::$kernel);

        if (!static::$kernel->getContainer()->has(HostResolver::class)) {
            $this->markTestSkipped('HostResolver not registered — zero-config boot context invalid');
        }

        // On current master: this throws TypeError (null given for non-nullable param).
        // After fix: returns HostResolver instance correctly.
        /** @var HostResolver $resolver */
        $resolver = static::$kernel->getContainer()->get(HostResolver::class);

        // If we reach here (after the fix), verify it is the right type.
        $this->assertInstanceOf(HostResolver::class, $resolver);
    }

    /**
     * Boots a console application and runs `list` — exercises the full Symfony
     * console bootstrap path, including ConsoleCommandEvent dispatch which triggers
     * ConsoleResolver instantiation.
     *
     * On current master (broken state): ConsoleResolver::__construct() receives null
     * for its non-nullable TenantProviderInterface argument → TypeError → exit code != 0.
     *
     * After plans 18-09/18-10: exit code 0 (GREEN bar).
     */
    public function testConsoleApplicationVersionCommandExitsZero(): void
    {
        self::assertNotNull(static::$kernel);

        $application = new Application(static::$kernel);
        $application->setAutoExit(false);
        // IN-02: deliberately do NOT disable the Application's default
        // exception-catching here. If a regression makes the resolver throw
        // on instantiation, letting the exception propagate bypasses the
        // assertSame() diagnostic message and leaves the failure explanation
        // in the PHPUnit trace alone. With default exception handling, the
        // ApplicationTester captures the error into getDisplay() and the
        // assertion message surfaces both the status code AND the captured
        // output — making regressions self-explanatory in CI logs.

        $tester = new ApplicationTester($application);
        $tester->run(['command' => 'list']);

        $this->assertSame(
            0,
            $tester->getStatusCode(),
            'bin/console list must exit 0 in zero-config mode. Output: '.$tester->getDisplay(),
        );
    }
}

/**
 * Minimal Symfony kernel that registers TenancyBundle with NO tenancy: extension
 * config block — replicating the exact state of a fresh `composer require` skeleton.
 *
 * Key differences from TestKernel:
 *  - Does NOT load the tenancy extension config block — the tenancy extension runs
 *    with default values only, and tenancy.provider is NOT defined.
 *  - Does NOT substitute a fake provider — tenancy.provider must remain absent
 *    so nullOnInvalid() resolves to literal null at service instantiation time.
 *  - Adds MakeZeroConfigServicesPublicPass to expose resolver/command services for
 *    direct container inspection in testHostResolverInstantiatesWithNullProvider().
 */
final class ZeroConfigTestKernel extends Kernel
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
        // Remove tenancy.provider to simulate a fresh skeleton without doctrine/orm.
        // This replicates the exact null-injection path seen in the 2026-05-21 UAT.
        // CRITICAL: do NOT substitute a fake provider — tenancy.provider must be ABSENT.
        $container->addCompilerPass(new RemoveTenancyProviderPass());
        // Expose resolver and command services so tests can fetch them directly.
        $container->addCompilerPass(new MakeZeroConfigServicesPublicPass());
    }

    public function registerContainerConfiguration(LoaderInterface $loader): void
    {
        $loader->load(function (ContainerBuilder $container): void {
            // Minimal framework config — identical to TestKernel's block.
            // CRITICAL: no tenancy extension config block loaded here.
            // TenancyBundle's loadExtension() is still invoked by Symfony's
            // AbstractBundle/BundleExtension machinery with all-default config
            // values, importing config/services.php and registering resolvers
            // with nullOnInvalid() injection.
            $container->loadFromExtension('framework', [
                'secret' => 'test-secret',
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
        // IN-03: include getmypid() in the hash input so parallel PHPUnit
        // processes (e.g. paratest workers) do not collide on the same
        // cache-dir path. Stable within a single process run.
        return sys_get_temp_dir().'/tenancy_bundle_test_'.md5(static::class.$this->environment.getmypid()).'/cache';
    }

    public function getLogDir(): string
    {
        // IN-03: see getCacheDir() — PID-suffixed hash for parallel safety.
        return sys_get_temp_dir().'/tenancy_bundle_test_'.md5(static::class.$this->environment.getmypid()).'/logs';
    }
}

/**
 * Compiler pass that makes the zero-config resolver and command services public
 * so they can be fetched directly from the container in tests.
 *
 * Only touches services that exist (Messenger is optional; TenantWorkerMiddleware
 * is conditionally registered).
 */
final class MakeZeroConfigServicesPublicPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $ids = [
            HostResolver::class,
            HeaderResolver::class,
            QueryParamResolver::class,
            ConsoleResolver::class,
            'tenancy.command.run',
        ];

        // TenantWorkerMiddleware is only registered when Messenger is present.
        if ($container->hasDefinition('tenancy.messenger.worker_middleware')) {
            $ids[] = 'tenancy.messenger.worker_middleware';
        }

        foreach ($ids as $id) {
            if ($container->hasDefinition($id)) {
                $container->getDefinition($id)->setPublic(true);
            }
        }
    }
}

/**
 * Compiler pass that removes tenancy.provider and its alias from the container,
 * simulating a fresh symfony/skeleton environment where doctrine/orm is not
 * installed.
 *
 * On a fresh `composer require danplaton4/tenancy-bundle` skeleton:
 *  - doctrine/orm is absent → tenancy.provider is never registered
 *  - nullOnInvalid() resolves to null for all 6 defect-site constructor args
 *  - PHP 8.x strict typing throws TypeError on first instantiation
 *
 * In this test environment, doctrine/orm IS present (it's a dev dependency),
 * so without this pass, tenancy.provider would be registered but reference
 * the missing doctrine.orm.default_entity_manager — a different ServiceNotFoundException.
 * Removing tenancy.provider here replicates the actual fresh-skeleton failure mode.
 */
final class RemoveTenancyProviderPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        // Remove tenancy.provider to simulate absence of doctrine/orm on install.
        if ($container->hasDefinition('tenancy.provider')) {
            $container->removeDefinition('tenancy.provider');
        }

        // Also remove the alias — if it exists.
        if ($container->hasAlias(TenantProviderInterface::class)) {
            $container->removeAlias(TenantProviderInterface::class);
        }

        // Remove maintenance commands — they reference doctrine.orm.default_entity_manager
        // which does not exist in a skeleton with no Doctrine ORM configured.
        // Commands are guarded by interface_exists(EntityManagerInterface) in services.php,
        // but the ORM interface IS present in the dev environment; only the EM service is absent.
        foreach (['tenancy.command.maintenance.enable', 'tenancy.command.maintenance.disable', 'tenancy.command.maintenance.status'] as $id) {
            if ($container->hasDefinition($id)) {
                $container->removeDefinition($id);
            }
        }
    }
}
