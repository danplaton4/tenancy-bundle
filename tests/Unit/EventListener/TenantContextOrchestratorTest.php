<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Unit\EventListener;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Tenancy\Bundle\Bootstrapper\BootstrapperChain;
use Tenancy\Bundle\Bootstrapper\TenantBootstrapperInterface;
use Tenancy\Bundle\Context\TenantContext;
use Tenancy\Bundle\Event\TenantContextCleared;
use Tenancy\Bundle\Event\TenantResolved;
use Tenancy\Bundle\EventListener\TenantContextOrchestrator;
use Tenancy\Bundle\Resolver\ResolverChain;
use Tenancy\Bundle\Resolver\TenantResolverInterface;
use Tenancy\Bundle\TenantInterface;

/**
 * Spy TenantBootstrapperInterface implementation.
 * Tracks boot()/clear() calls so we can verify BootstrapperChain flow.
 */
final class SpyBootstrapper implements TenantBootstrapperInterface
{
    public int $bootCallCount = 0;
    public int $clearCallCount = 0;
    /** @var callable|null */
    public $onClear;

    public function boot(TenantInterface $tenant): void
    {
        ++$this->bootCallCount;
    }

    public function clear(): void
    {
        ++$this->clearCallCount;
        if (null !== $this->onClear) {
            ($this->onClear)();
        }
    }
}

/**
 * Stub TenantResolverInterface that always returns a fixed tenant (or null).
 * Used to make ResolverChain (final) return a predictable result in unit tests.
 */
final class StubResolver implements TenantResolverInterface
{
    public function __construct(private readonly ?TenantInterface $tenant)
    {
    }

    public function resolve(Request $request): ?TenantInterface
    {
        return $this->tenant;
    }
}

final class TenantContextOrchestratorTest extends TestCase
{
    private TenantContext $tenantContext;
    private BootstrapperChain $bootstrapperChain;
    private SpyBootstrapper $spyBootstrapper;
    private EventDispatcherInterface&MockObject $chainDispatcher;
    private EventDispatcherInterface&MockObject $orchestratorDispatcher;
    private TenantInterface&MockObject $tenant;
    private ResolverChain $resolverChain;
    private TenantContextOrchestrator $orchestrator;

    protected function setUp(): void
    {
        $this->tenantContext = new TenantContext();

        // BootstrapperChain needs its own EventDispatcher (for TenantBootstrapped event).
        // We keep it separate from the orchestrator's dispatcher.
        $this->chainDispatcher = $this->createMock(EventDispatcherInterface::class);
        $this->chainDispatcher->method('dispatch')->willReturnArgument(0);
        $this->bootstrapperChain = new BootstrapperChain($this->chainDispatcher);

        // Add a spy bootstrapper so we can detect when chain->boot()/clear() is called
        $this->spyBootstrapper = new SpyBootstrapper();
        $this->bootstrapperChain->addBootstrapper($this->spyBootstrapper);

        $this->orchestratorDispatcher = $this->createMock(EventDispatcherInterface::class);
        $this->orchestratorDispatcher->method('dispatch')->willReturnArgument(0);

        $this->tenant = $this->createMock(TenantInterface::class);

        // Build a ResolverChain with a stub resolver that returns the mock tenant
        $this->resolverChain = new ResolverChain();
        $this->resolverChain->addResolver(new StubResolver($this->tenant));

        $this->orchestrator = new TenantContextOrchestrator(
            $this->tenantContext,
            $this->bootstrapperChain,
            $this->orchestratorDispatcher,
            $this->resolverChain,
        );
    }

    public function testPriorityConstantIs20(): void
    {
        $this->assertSame(20, TenantContextOrchestrator::PRIORITY);
    }

    // -------------------------------------------------------------------------
    // onKernelRequest — sub-request handling
    // -------------------------------------------------------------------------

    public function testOnKernelRequestIgnoresSubRequests(): void
    {
        $kernel = $this->createMock(HttpKernelInterface::class);
        $request = Request::create('/');
        $event = new RequestEvent($kernel, $request, HttpKernelInterface::SUB_REQUEST);

        $this->orchestratorDispatcher->expects($this->never())->method($this->anything());

        $this->orchestrator->onKernelRequest($event);

        $this->assertFalse($this->tenantContext->hasTenant());
        $this->assertSame(0, $this->spyBootstrapper->bootCallCount);
        $this->assertSame(0, $this->spyBootstrapper->clearCallCount);
    }

    // -------------------------------------------------------------------------
    // onKernelRequest — main request flow (tenant resolved)
    // -------------------------------------------------------------------------

    public function testOnKernelRequestCallsResolverChain(): void
    {
        $kernel = $this->createMock(HttpKernelInterface::class);
        $request = Request::create('/');
        $event = new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST);

        $this->orchestrator->onKernelRequest($event);

        // Tenant was set means resolve() was called (StubResolver returned it)
        $this->assertTrue($this->tenantContext->hasTenant());
    }

    public function testOnKernelRequestSetsTenantFromResolverResult(): void
    {
        $kernel = $this->createMock(HttpKernelInterface::class);
        $request = Request::create('/');
        $event = new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST);

        $this->orchestrator->onKernelRequest($event);

        $this->assertSame($this->tenant, $this->tenantContext->getTenant());
    }

    public function testOnKernelRequestBootsBootstrapperChain(): void
    {
        $kernel = $this->createMock(HttpKernelInterface::class);
        $request = Request::create('/');
        $event = new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST);

        // Verify chainDispatcher receives TenantBootstrapped (meaning BootstrapperChain::boot() ran)
        $this->chainDispatcher->expects($this->once())
            ->method('dispatch');

        $this->orchestrator->onKernelRequest($event);

        $this->assertSame(1, $this->spyBootstrapper->bootCallCount);
    }

    public function testOnKernelRequestDispatchesTenantResolved(): void
    {
        $kernel = $this->createMock(HttpKernelInterface::class);
        $request = Request::create('/');
        $event = new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST);

        $dispatchedEvent = null;
        $this->orchestratorDispatcher
            ->expects($this->once())
            ->method('dispatch')
            ->willReturnCallback(function (object $e) use (&$dispatchedEvent): object {
                $dispatchedEvent = $e;

                return $e;
            });

        $this->orchestrator->onKernelRequest($event);

        $this->assertInstanceOf(TenantResolved::class, $dispatchedEvent);
        /* @var TenantResolved $dispatchedEvent */
        $this->assertSame($this->tenant, $dispatchedEvent->tenant);
        $this->assertSame($request, $dispatchedEvent->request);
        $this->assertSame(StubResolver::class, $dispatchedEvent->resolvedBy);
    }

    // -------------------------------------------------------------------------
    // onKernelRequest — main request flow (null resolution — FIX-02)
    // -------------------------------------------------------------------------

    public function testOnKernelRequestIsNoOpWhenNoResolverMatches(): void
    {
        $kernel = $this->createMock(HttpKernelInterface::class);
        $request = Request::create('/');
        $event = new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST);

        // Construct an orchestrator backed by a chain whose only resolver returns null
        $emptyChain = new ResolverChain();
        $emptyChain->addResolver(new StubResolver(null));

        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects($this->never())->method('dispatch');

        $chainDispatcher = $this->createMock(EventDispatcherInterface::class);
        $chainDispatcher->expects($this->never())->method('dispatch');

        $context = new TenantContext();
        $chain = new BootstrapperChain($chainDispatcher);
        $spy = new SpyBootstrapper();
        $chain->addBootstrapper($spy);

        $orchestrator = new TenantContextOrchestrator(
            $context,
            $chain,
            $dispatcher,
            $emptyChain,
        );

        $orchestrator->onKernelRequest($event);

        $this->assertFalse($context->hasTenant(), 'TenantContext must remain empty when no resolver matches');
        $this->assertSame(0, $spy->bootCallCount, 'BootstrapperChain::boot() must NOT run on null resolution');
    }

    public function testOnKernelRequestDoesNotDispatchTenantResolvedWhenChainReturnsNull(): void
    {
        // Rebuild orchestrator with a chain that returns null
        $emptyChain = new ResolverChain();
        $emptyChain->addResolver(new StubResolver(null));

        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects($this->never())
            ->method('dispatch')
            ->with($this->isInstanceOf(TenantResolved::class));

        $orchestrator = new TenantContextOrchestrator(
            new TenantContext(),
            new BootstrapperChain($this->createMock(EventDispatcherInterface::class)),
            $dispatcher,
            $emptyChain,
        );

        $kernel = $this->createMock(HttpKernelInterface::class);
        $request = Request::create('/');
        $event = new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST);

        $orchestrator->onKernelRequest($event);
    }

    public function testOnKernelRequestSkipsSubRequests(): void
    {
        // Orchestrator built with an empty ResolverChain — confirms sub-request path is truly skipped.
        $emptyChain = new ResolverChain();
        $orchestratorWithEmptyChain = new TenantContextOrchestrator(
            new TenantContext(),
            $this->bootstrapperChain,
            $this->orchestratorDispatcher,
            $emptyChain,
        );

        $kernel = $this->createMock(HttpKernelInterface::class);
        $request = Request::create('/');
        $event = new RequestEvent($kernel, $request, HttpKernelInterface::SUB_REQUEST);

        // Must not throw and must not dispatch anything
        $this->orchestratorDispatcher->expects($this->never())->method('dispatch');

        $orchestratorWithEmptyChain->onKernelRequest($event);
    }

    // -------------------------------------------------------------------------
    // onKernelTerminate
    // -------------------------------------------------------------------------

    public function testOnKernelTerminateDoesNothingWithNoActiveTenant(): void
    {
        $kernel = $this->createMock(HttpKernelInterface::class);
        $request = Request::create('/');
        $response = new Response();
        $event = new TerminateEvent($kernel, $request, $response);

        $this->orchestratorDispatcher->expects($this->never())->method('dispatch');

        $this->orchestrator->onKernelTerminate($event);

        $this->assertSame(0, $this->spyBootstrapper->clearCallCount);
    }

    public function testOnKernelTerminateClearsContextWhenTenantActive(): void
    {
        $tenant = $this->createMock(TenantInterface::class);
        $this->tenantContext->setTenant($tenant);

        $kernel = $this->createMock(HttpKernelInterface::class);
        $request = Request::create('/');
        $response = new Response();
        $event = new TerminateEvent($kernel, $request, $response);

        $this->orchestratorDispatcher->expects($this->once())
            ->method('dispatch')
            ->with($this->isInstanceOf(TenantContextCleared::class));

        $this->orchestrator->onKernelTerminate($event);

        // BootstrapperChain::clear() was invoked (detected via spy bootstrapper)
        $this->assertSame(1, $this->spyBootstrapper->clearCallCount);
        $this->assertFalse($this->tenantContext->hasTenant());
    }

    public function testTeardownOrderIsBootstrappersThenContextThenEvent(): void
    {
        $tenant = $this->createMock(TenantInterface::class);
        $this->tenantContext->setTenant($tenant);

        $kernel = $this->createMock(HttpKernelInterface::class);
        $request = Request::create('/');
        $response = new Response();
        $event = new TerminateEvent($kernel, $request, $response);

        $callOrder = [];
        $tenantContextRef = $this->tenantContext;

        // Track when bootstrapperChain.clear() runs (via spy bootstrapper)
        $this->spyBootstrapper->onClear = function () use (&$callOrder): void {
            $callOrder[] = 'bootstrapperChain.clear';
        };

        $this->orchestratorDispatcher
            ->expects($this->once())
            ->method('dispatch')
            ->willReturnCallback(function () use (&$callOrder): object {
                $callOrder[] = 'eventDispatcher.dispatch';

                return new TenantContextCleared();
            });

        $this->orchestrator->onKernelTerminate($event);

        // Order: bootstrapperChain.clear -> tenantContext.clear -> eventDispatcher.dispatch
        $this->assertSame(['bootstrapperChain.clear', 'eventDispatcher.dispatch'], $callOrder);
        $this->assertFalse($tenantContextRef->hasTenant(), 'TenantContext must be cleared before dispatch');
    }
}
