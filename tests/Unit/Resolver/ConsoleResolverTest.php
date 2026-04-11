<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Unit\Resolver;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Tenancy\Bundle\Bootstrapper\BootstrapperChain;
use Tenancy\Bundle\Bootstrapper\TenantBootstrapperInterface;
use Tenancy\Bundle\Context\TenantContext;
use Tenancy\Bundle\Event\TenantResolved;
use Tenancy\Bundle\Exception\TenantNotFoundException;
use Tenancy\Bundle\Provider\TenantProviderInterface;
use Tenancy\Bundle\Resolver\ConsoleResolver;
use Tenancy\Bundle\TenantInterface;

/**
 * Spy TenantBootstrapperInterface to track boot() calls on the real BootstrapperChain.
 */
final class ConsoleSpyBootstrapper implements TenantBootstrapperInterface
{
    public int $bootCallCount = 0;
    public ?TenantInterface $lastBootedTenant = null;

    public function boot(TenantInterface $tenant): void
    {
        ++$this->bootCallCount;
        $this->lastBootedTenant = $tenant;
    }

    public function clear(): void
    {
        // no-op for these tests
    }
}

final class ConsoleResolverTest extends TestCase
{
    private TenantProviderInterface&MockObject $provider;
    private TenantContext $tenantContext;
    private BootstrapperChain $bootstrapperChain;
    private ConsoleSpyBootstrapper $spyBootstrapper;
    private EventDispatcherInterface&MockObject $chainDispatcher;
    private EventDispatcherInterface&MockObject $eventDispatcher;
    private ConsoleResolver $resolver;

    protected function setUp(): void
    {
        $this->provider = $this->createMock(TenantProviderInterface::class);
        $this->tenantContext = new TenantContext();

        // BootstrapperChain is final — use real instance with spy bootstrapper
        $this->chainDispatcher = $this->createMock(EventDispatcherInterface::class);
        $this->chainDispatcher->method('dispatch')->willReturnArgument(0);
        $this->bootstrapperChain = new BootstrapperChain($this->chainDispatcher);
        $this->spyBootstrapper = new ConsoleSpyBootstrapper();
        $this->bootstrapperChain->addBootstrapper($this->spyBootstrapper);

        $this->eventDispatcher = $this->createMock(EventDispatcherInterface::class);

        $this->resolver = new ConsoleResolver(
            $this->provider,
            $this->tenantContext,
            $this->bootstrapperChain,
            $this->eventDispatcher,
        );
    }

    private function createEventWithoutTenantOption(): ConsoleCommandEvent
    {
        $application = new Application();
        $command = new Command('test:command');
        $application->addCommand($command);

        $input = new ArrayInput([]);
        $output = new NullOutput();

        return new ConsoleCommandEvent($command, $input, $output);
    }

    private function createEventWithTenantOption(string $slug): ConsoleCommandEvent
    {
        $application = new Application();

        // Add --tenant to Application definition before creating ArrayInput so it can accept it
        $application->getDefinition()->addOption(
            new InputOption('tenant', null, InputOption::VALUE_OPTIONAL, 'Tenant slug to resolve')
        );

        $command = new Command('test:command');
        $application->addCommand($command);

        $input = new ArrayInput(['--tenant' => $slug]);
        $output = new NullOutput();

        return new ConsoleCommandEvent($command, $input, $output);
    }

    public function testDoesNothingWhenTenantOptionAbsent(): void
    {
        $this->provider->expects($this->never())->method('findBySlug');
        $this->eventDispatcher->expects($this->never())->method('dispatch');

        $event = $this->createEventWithoutTenantOption();
        $this->resolver->onConsoleCommand($event);

        $this->assertFalse($this->tenantContext->hasTenant());
        $this->assertSame(0, $this->spyBootstrapper->bootCallCount);
    }

    public function testDoesNothingWhenTenantOptionEmpty(): void
    {
        $this->provider->expects($this->never())->method('findBySlug');
        $this->eventDispatcher->expects($this->never())->method('dispatch');

        $event = $this->createEventWithTenantOption('');
        $this->resolver->onConsoleCommand($event);

        $this->assertFalse($this->tenantContext->hasTenant());
        $this->assertSame(0, $this->spyBootstrapper->bootCallCount);
    }

    public function testResolvesTenantAndBootsContext(): void
    {
        $tenant = $this->createMock(TenantInterface::class);

        $this->provider->expects($this->once())
            ->method('findBySlug')
            ->with('acme')
            ->willReturn($tenant);

        $dispatchedEvent = null;
        $this->eventDispatcher->expects($this->once())
            ->method('dispatch')
            ->willReturnCallback(function (object $event) use (&$dispatchedEvent) {
                $dispatchedEvent = $event;

                return $event;
            });

        $event = $this->createEventWithTenantOption('acme');
        $this->resolver->onConsoleCommand($event);

        // TenantContext was set
        $this->assertTrue($this->tenantContext->hasTenant());
        $this->assertSame($tenant, $this->tenantContext->getTenant());

        // BootstrapperChain.boot() was called via spy
        $this->assertSame(1, $this->spyBootstrapper->bootCallCount);
        $this->assertSame($tenant, $this->spyBootstrapper->lastBootedTenant);

        // TenantResolved event dispatched with correct payload
        $this->assertInstanceOf(TenantResolved::class, $dispatchedEvent);
        $this->assertSame($tenant, $dispatchedEvent->tenant);
        $this->assertNull($dispatchedEvent->request);
        $this->assertSame(ConsoleResolver::class, $dispatchedEvent->resolvedBy);
    }

    public function testPropagatesProviderExceptions(): void
    {
        $this->provider->expects($this->once())
            ->method('findBySlug')
            ->with('unknown')
            ->willThrowException(new TenantNotFoundException('Tenant "unknown" not found.'));

        $this->eventDispatcher->expects($this->never())->method('dispatch');

        $event = $this->createEventWithTenantOption('unknown');

        $this->expectException(TenantNotFoundException::class);
        $this->resolver->onConsoleCommand($event);

        // Verify context and bootstrapper were never touched
        $this->assertFalse($this->tenantContext->hasTenant());
        $this->assertSame(0, $this->spyBootstrapper->bootCallCount);
    }

    public function testAddsOptionToApplicationDefinitionIdempotently(): void
    {
        $tenant = $this->createMock(TenantInterface::class);

        $this->provider->expects($this->exactly(2))
            ->method('findBySlug')
            ->with('acme')
            ->willReturn($tenant);

        $this->eventDispatcher->expects($this->exactly(2))
            ->method('dispatch')
            ->willReturnArgument(0);

        // First call adds --tenant to Application definition
        $event1 = $this->createEventWithTenantOption('acme');
        $this->resolver->onConsoleCommand($event1);

        // Second call with same application should not throw (hasOption guard)
        $event2 = $this->createEventWithTenantOption('acme');
        $this->resolver->onConsoleCommand($event2);

        $this->assertSame(2, $this->spyBootstrapper->bootCallCount);
    }
}
