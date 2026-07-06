<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Integration\Health;

use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\PhpFileLoader as RoutingPhpFileLoader;
use Symfony\Component\Routing\RouteCollection;
use Tenancy\Bundle\Controller\TenantHealthController;
use Tenancy\Bundle\TenancyBundle;
use Tenancy\Bundle\Tests\Integration\Support\ReplaceTenancyProviderPass;

/**
 * Compiler pass that makes the health controller public so the controller resolver can
 * find it when routing dispatches a request to the TenantHealthController.
 */
final class MakeHealthServicesPublicForEndpointTest implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $ids = [
            'tenancy.health.controller',
            'tenancy.health.checker',
        ];

        foreach ($ids as $id) {
            if ($container->hasDefinition($id)) {
                $container->getDefinition($id)->setPublic(true);
            } elseif ($container->hasAlias($id)) {
                $container->getAlias($id)->setPublic(true);
            }
        }

        // The route file references TenantHealthController by class name.
        // The controller resolver looks up the class name in the container,
        // so we must also create a public alias from the class name to the service ID.
        // This mirrors how Symfony's autoconfigured controllers get resolved.
        if ($container->hasDefinition('tenancy.health.controller')
            && !$container->hasAlias(TenantHealthController::class)
            && !$container->hasDefinition(TenantHealthController::class)
        ) {
            $container->setAlias(TenantHealthController::class, 'tenancy.health.controller')
                ->setPublic(true);
        } elseif ($container->hasAlias(TenantHealthController::class)) {
            $container->getAlias(TenantHealthController::class)->setPublic(true);
        }
    }
}

/**
 * Test kernel for HTTP endpoint integration tests.
 *
 * Boots FrameworkBundle + TenancyBundle with routing enabled.
 * Imports config/routes/health.php (live + ready routes) with prefix /_tenancy/health.
 * Imports config/routes/health_fleet.php (fleet route) with prefix /_tenancy/health.
 *
 * The provider is replaced with NullTenantProvider (no Doctrine EM needed).
 *
 * Route loading uses the MicroKernelTrait pattern: the kernel registers itself as a
 * `routing.route_loader`-tagged synthetic service so its loadRoutes() method is
 * called by the router to import the PHP-DSL route files.
 */
final class HealthEndpointsTestKernel extends Kernel
{
    public function __construct()
    {
        parent::__construct('test_health_endpoints', false);
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
        $container->addCompilerPass(new ReplaceTenancyProviderPass());
        $container->addCompilerPass(new MakeHealthServicesPublicForEndpointTest());
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
                'router' => [
                    'resource' => 'kernel::loadRoutes',
                    'type' => 'service',
                    'utf8' => true,
                ],
            ]);

            // Register the kernel as a synthetic service + route loader.
            // This mirrors what MicroKernelTrait does in its registerContainerConfiguration():
            // the kernel must be in the container and tagged routing.route_loader so the
            // router calls loadRoutes() on it when building the route collection.
            if (!$container->hasDefinition('kernel')) {
                $container->register('kernel', self::class)
                    ->addTag('controller.service_arguments')
                    ->setAutoconfigured(true)
                    ->setSynthetic(true)
                    ->setPublic(true);
            }

            $container->getDefinition('kernel')->addTag('routing.route_loader');
        });
    }

    /**
     * Route loader callback — invoked by the framework router via ObjectLoader.
     *
     * Mirrors the MicroKernelTrait::loadRoutes() signature: takes a LoaderInterface
     * and returns a RouteCollection. Uses PhpFileLoader directly to load the health
     * PHP-DSL route files and merge them with the /_tenancy/health prefix.
     */
    public function loadRoutes(LoaderInterface $loader): RouteCollection
    {
        $routesDir = \dirname(__DIR__, 3).'/config/routes';

        /** @var RoutingPhpFileLoader $phpLoader */
        $phpLoader = $loader->getResolver()->resolve($routesDir.'/health.php', 'php');

        $collection = new RouteCollection();

        // Live + ready routes (HEALTH-01, HEALTH-02) from health.php.
        $healthRoutes = $phpLoader->load($routesDir.'/health.php', 'php');
        $healthRoutes->addPrefix('/_tenancy/health');
        $collection->addCollection($healthRoutes);

        // Fleet route (HEALTH-06, D-02) from health_fleet.php.
        $fleetRoutes = $phpLoader->load($routesDir.'/health_fleet.php', 'php');
        $fleetRoutes->addPrefix('/_tenancy/health');
        $collection->addCollection($fleetRoutes);

        return $collection;
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir().'/tenancy_health_endpoints_test_'.md5(self::class).'/cache';
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir().'/tenancy_health_endpoints_test_'.md5(self::class).'/logs';
    }
}

/**
 * End-to-end HTTP integration tests for the tenancy health endpoints.
 *
 * Proves that the imported routes resolve end-to-end (HEALTH-01, HEALTH-02, HEALTH-06, D-06):
 *   1. GET /_tenancy/health/live returns HTTP 200, Content-Type application/health+json, status ok
 *   2. GET /_tenancy/health/ready/{slug} route resolves (HEALTH-02 route-import proof)
 *   3. GET /_tenancy/health (fleet) route resolves via health_fleet.php (D-02, HEALTH-06)
 *   4. Unknown sub-path returns HTTP 404 from the router (routing sanity)
 *
 * Note on provider: ReplaceTenancyProviderPass installs NullTenantProvider which throws
 * RuntimeException on findBySlug/findAll. The controller handles the null-provider guard
 * (provider is not null but findBySlug throws) — the important thing for these route-import
 * tests is that we get a controller response, not a routing 404. The liveness endpoint
 * has zero provider dependency and always returns HTTP 200.
 */
final class HealthEndpointsIntegrationTest extends TestCase
{
    private static ?HealthEndpointsTestKernel $kernel = null;

    public static function setUpBeforeClass(): void
    {
        self::$kernel = new HealthEndpointsTestKernel();
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
     * GET /_tenancy/health/live returns HTTP 200 with application/health+json (HEALTH-01).
     *
     * Liveness has zero DB I/O — it must respond 200 regardless of Doctrine/liip state.
     * This proves the route resolves, the controller is public, and the live() action runs.
     */
    public function testLivenessReturnsHttp200WithHealthJsonContentType(): void
    {
        self::assertNotNull(self::$kernel);

        $request = Request::create('/_tenancy/health/live', 'GET');
        $response = self::$kernel->handle($request);

        $this->assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
            'GET /_tenancy/health/live must return HTTP 200 (HEALTH-01, D-07). '
            .'Content: '.$response->getContent(),
        );

        $this->assertStringContainsString(
            'application/health+json',
            (string) $response->headers->get('Content-Type'),
            'Liveness response must have Content-Type application/health+json.',
        );
    }

    /**
     * GET /_tenancy/health/live returns body with {"status":"ok"} (HEALTH-01).
     */
    public function testLivenessBodyContainsStatusOk(): void
    {
        self::assertNotNull(self::$kernel);

        $request = Request::create('/_tenancy/health/live', 'GET');
        $response = self::$kernel->handle($request);

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());

        $body = json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        $this->assertIsArray($body);
        $this->assertArrayHasKey('status', $body, 'Liveness body must have a status field.');
        $this->assertSame('ok', $body['status'], 'Liveness body status must be "ok" (HEALTH-01, D-07).');
    }

    /**
     * GET /_tenancy/health/live returns application/health+json (not text/html).
     *
     * Proves the controller overrides the default content type (HEALTH-01 end-to-end).
     */
    public function testLivenessResponseIsNotHtml(): void
    {
        self::assertNotNull(self::$kernel);

        $request = Request::create('/_tenancy/health/live', 'GET');
        $response = self::$kernel->handle($request);

        $contentType = (string) $response->headers->get('Content-Type');
        $this->assertStringNotContainsString(
            'text/html',
            $contentType,
            'Health response must not be HTML — it must be application/health+json.',
        );
    }

    /**
     * GET /_tenancy/health/ready/{slug} route resolves (HEALTH-02 route-import proof).
     *
     * NullTenantProvider exists (not null) but throws a *generic* \RuntimeException on
     * findBySlug. The controller's typed catches (TenantNotFoundException → 404,
     * TenantInactiveException → 503; CR-02) intentionally do NOT match a generic
     * RuntimeException, so the exception propagates and the response is 500. That is
     * expected here — this test only proves the ROUTE resolved (not HTTP 404 from the
     * router). The 404/503/200 readiness contract itself is covered by the controller
     * unit tests (TenantHealthControllerTest), which drive the typed exceptions.
     */
    public function testReadinessRouteResolves(): void
    {
        self::assertNotNull(self::$kernel);

        $request = Request::create('/_tenancy/health/ready/any-slug', 'GET');
        $response = self::$kernel->handle($request);

        // Route resolved — we got a controller response (not routing 404).
        $this->assertNotSame(
            Response::HTTP_NOT_FOUND,
            $response->getStatusCode(),
            'GET /_tenancy/health/ready/{slug} route must resolve (not HTTP 404 from routing). '
            .'Got status '.$response->getStatusCode().' — proves HEALTH-02 route-import works.',
        );
    }

    /**
     * An unknown sub-path under /_tenancy/health returns HTTP 404 from the router.
     * Sanity check that the router is wired and recognises unknown paths.
     */
    public function testUnknownSubPathReturnsHttp404(): void
    {
        self::assertNotNull(self::$kernel);

        // A path that does not match any registered route.
        $request = Request::create('/_tenancy/health/completely-unknown-action/foo/bar', 'GET');
        $response = self::$kernel->handle($request);

        $this->assertSame(
            Response::HTTP_NOT_FOUND,
            $response->getStatusCode(),
            'Unknown sub-path must return HTTP 404 from the router (routing sanity check).',
        );
    }

    /**
     * GET /_tenancy/health (fleet endpoint) route resolves via health_fleet.php (D-02, HEALTH-06).
     *
     * The fleet route is imported from the SEPARATE health_fleet.php route file (D-02).
     * This test proves the second route file was imported correctly.
     *
     * The fleet endpoint always returns HTTP 200 (D-08 — never a probe target).
     * NullTenantProvider::findAll() throws \RuntimeException; the WR-01 try/catch in
     * fleet() degrades that to a sanitized, empty aggregate at HTTP 200 (rather than a
     * propagated 500), so this test asserts the exact 200 contract, not merely "not 404".
     */
    public function testFleetEndpointRouteResolvesFromSeparateRouteFile(): void
    {
        self::assertNotNull(self::$kernel);

        // The fleet route is '/' under the /_tenancy/health prefix, so the canonical
        // path carries a trailing slash. Requesting it directly invokes the controller
        // (a slash-less '/_tenancy/health' only yields a 301 redirect, not the action).
        $request = Request::create('/_tenancy/health/', 'GET');
        $response = self::$kernel->handle($request);

        // Route resolved AND fleet honored its always-200 contract even though the
        // roster fetch threw (WR-01). This proves health_fleet.php imported (D-02) and
        // the always-200 dashboard contract (D-08) end-to-end.
        $this->assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
            'GET /_tenancy/health (fleet) must return 200 even when findAll() throws (D-08 always-200; WR-01).',
        );

        $body = json_decode((string) $response->getContent(), true);
        $this->assertIsArray($body);
        $this->assertSame(0, $body['total'], 'Roster-fetch failure degrades to an empty aggregate.');
        $this->assertSame([], $body['tenants']);
    }
}
