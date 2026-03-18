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
use Tenancy\Bundle\EventListener\TenantContextOrchestrator;
use Tenancy\Bundle\Resolver\ResolverChain;
use Tenancy\Bundle\TenantInterface;

/**
 * Spy TenantBootstrapperInterface implementation.
 * Tracks clear() calls so we can verify BootstrapperChain::clear() was invoked.
 */
final class SpyBootstrapper implements TenantBootstrapperInterface
{
    public int $clearCallCount = 0;
    /** @var callable|null */
    public $onClear = null;

    public function boot(TenantInterface $tenant): void
    {
        // no-op in these tests
    }

    public function clear(): void
    {
        ++$this->clearCallCount;
        if ($this->onClear !== null) {
            ($this->onClear)();
        }
    }
}

final class TenantContextOrchestratorTest extends TestCase
{
    private TenantContext $tenantContext;
    private BootstrapperChain $bootstrapperChain;
    private SpyBootstrapper $spyBootstrapper;
    private EventDispatcherInterface&MockObject $chainDispatcher;
    private EventDispatcherInterface&MockObject $orchestratorDispatcher;
    private TenantContextOrchestrator $orchestrator;

    protected function setUp(): void
    {
        $this->tenantContext = new TenantContext();

        // BootstrapperChain needs its own EventDispatcher (for TenantBootstrapped event).
        // We keep it separate from the orchestrator's dispatcher.
        $this->chainDispatcher = $this->createMock(EventDispatcherInterface::class);
        $this->chainDispatcher->method('dispatch')->willReturnArgument(0);
        $this->bootstrapperChain = new BootstrapperChain($this->chainDispatcher);

        // Add a spy bootstrapper so we can detect when chain->clear() is called
        $this->spyBootstrapper = new SpyBootstrapper();
        $this->bootstrapperChain->addBootstrapper($this->spyBootstrapper);

        $this->orchestratorDispatcher = $this->createMock(EventDispatcherInterface::class);
        $this->orchestrator = new TenantContextOrchestrator(
            $this->tenantContext,
            $this->bootstrapperChain,
            $this->orchestratorDispatcher,
            new ResolverChain(),
        );
    }

    public function testPriorityConstantIs20(): void
    {
        $this->assertSame(20, TenantContextOrchestrator::PRIORITY);
    }

    public function testOnKernelRequestIgnoresSubRequests(): void
    {
        $kernel = $this->createMock(HttpKernelInterface::class);
        $request = Request::create('/');
        $event = new RequestEvent($kernel, $request, HttpKernelInterface::SUB_REQUEST);

        $this->orchestratorDispatcher->expects($this->never())->method($this->anything());

        $this->orchestrator->onKernelRequest($event);

        $this->assertFalse($this->tenantContext->hasTenant());
        $this->assertSame(0, $this->spyBootstrapper->clearCallCount);
    }

    public function testOnKernelRequestIsNoOpInPhase1ForMainRequest(): void
    {
        $kernel = $this->createMock(HttpKernelInterface::class);
        $request = Request::create('/');
        $event = new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST);

        $this->orchestrator->onKernelRequest($event);

        // Phase 1 skeleton — no resolver, so no tenant should be set
        $this->assertFalse($this->tenantContext->hasTenant());
    }

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
