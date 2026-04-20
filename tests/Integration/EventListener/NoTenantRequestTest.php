<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Integration\EventListener;

use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\Kernel;
use Tenancy\Bundle\Context\TenantContext;
use Tenancy\Bundle\EventListener\TenantContextOrchestrator;
use Tenancy\Bundle\TenancyBundle;
use Tenancy\Bundle\Tests\Integration\Support\ReplaceTenancyProviderPass;

/**
 * Compiler pass exposing orchestrator + context as public services for test retrieval.
 */
final class MakeOrchestratorPublicPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        foreach ([TenantContextOrchestrator::class, 'tenancy.context'] as $id) {
            if ($container->hasDefinition($id)) {
                $container->getDefinition($id)->setPublic(true);
            }
            if ($container->hasAlias($id)) {
                $container->getAlias($id)->setPublic(true);
            }
        }
    }
}

/**
 * Minimal kernel for FIX-02 no-tenant-request coverage.
 *
 * FrameworkBundle + TenancyBundle. Default driver (database_per_tenant)
 * with database.enabled: false — no Doctrine dependency.
 *
 * host.app_domain defaults to null, so HostResolver short-circuits to null.
 * NullTenantProvider (from ReplaceTenancyProviderPass) ensures Header and
 * QueryParam resolvers return null when no identifier is present on the request.
 */
final class NoTenantRequestKernel extends Kernel
{
    public function __construct()
    {
        parent::__construct('test_no_tenant', false);
    }

    public function registerBundles(): iterable
    {
        return [new FrameworkBundle(), new TenancyBundle()];
    }

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->addCompilerPass(new ReplaceTenancyProviderPass());
        $container->addCompilerPass(new MakeOrchestratorPublicPass());
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
            ]);
            // Default driver + no database — app_domain null ensures HostResolver returns null.
        });
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir().'/tenancy_no_tenant_request_test_'.md5(self::class).'/cache';
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir().'/tenancy_no_tenant_request_test_'.md5(self::class).'/logs';
    }
}

/**
 * FIX-02: Public route with no resolver match returns controller response,
 * TenantContext is empty, no TenantNotFoundException thrown.
 */
final class NoTenantRequestTest extends TestCase
{
    private static ?NoTenantRequestKernel $kernel = null;

    public static function setUpBeforeClass(): void
    {
        self::$kernel = new NoTenantRequestKernel();
        self::$kernel->boot();
    }

    public static function tearDownAfterClass(): void
    {
        if (null !== self::$kernel) {
            self::$kernel->shutdown();
            self::$kernel = null;
        }
    }

    protected function setUp(): void
    {
        // Ensure the shared TenantContext singleton is clean between tests
        $container = self::$kernel->getContainer();
        /** @var TenantContext $context */
        $context = $container->get('tenancy.context');
        $context->clear();
    }

    public function testOnKernelRequestWithNoResolverMatchLeavesContextEmpty(): void
    {
        $container = self::$kernel->getContainer();
        /** @var TenantContext $context */
        $context = $container->get('tenancy.context');
        /** @var TenantContextOrchestrator $orchestrator */
        $orchestrator = $container->get(TenantContextOrchestrator::class);

        $this->assertFalse($context->hasTenant(), 'precondition: context starts empty');

        // A request that cannot be resolved:
        //   - host=localhost does not match app_domain (null)
        //   - no X-Tenant-ID header
        //   - no ?_tenant query param
        $request = Request::create('http://localhost/health');
        $event = new RequestEvent(self::$kernel, $request, HttpKernelInterface::MAIN_REQUEST);

        // MUST NOT throw.
        $orchestrator->onKernelRequest($event);

        $this->assertFalse($context->hasTenant(), 'postcondition: context is still empty');
    }

    public function testOnKernelTerminateWithNoTenantIsNoOp(): void
    {
        $container = self::$kernel->getContainer();
        /** @var TenantContextOrchestrator $orchestrator */
        $orchestrator = $container->get(TenantContextOrchestrator::class);
        /** @var TenantContext $context */
        $context = $container->get('tenancy.context');

        $request = Request::create('/');
        $event = new TerminateEvent(self::$kernel, $request, new Response());

        // Must not throw (no bootstrappers to clear, no event to dispatch)
        $orchestrator->onKernelTerminate($event);

        $this->assertFalse($context->hasTenant());
    }

    public function testSubRequestWithNoResolverMatchIsNoOp(): void
    {
        $container = self::$kernel->getContainer();
        /** @var TenantContext $context */
        $context = $container->get('tenancy.context');
        /** @var TenantContextOrchestrator $orchestrator */
        $orchestrator = $container->get(TenantContextOrchestrator::class);

        $request = Request::create('http://localhost/sub');
        $event = new RequestEvent(self::$kernel, $request, HttpKernelInterface::SUB_REQUEST);

        $orchestrator->onKernelRequest($event);

        $this->assertFalse($context->hasTenant());
    }
}
