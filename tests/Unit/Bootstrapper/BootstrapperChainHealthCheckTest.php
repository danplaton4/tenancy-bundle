<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Unit\Bootstrapper;

use PHPUnit\Framework\TestCase;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Tenancy\Bundle\Bootstrapper\BootstrapperChain;
use Tenancy\Bundle\Bootstrapper\TenantBootstrapperInterface;
use Tenancy\Bundle\Health\BootstrapperHealthResult;
use Tenancy\Bundle\Health\HealthCheckBootstrapperInterface;
use Tenancy\Bundle\Health\HealthStatus;
use Tenancy\Bundle\TenantInterface;

/**
 * Unit tests for the additive BootstrapperChain::healthCheck() method.
 *
 * Guards the three behavioral invariants (HEALTH-03):
 *   1. Only bootstrappers implementing HealthCheckBootstrapperInterface are probed.
 *   2. A throwing probe is caught and returned as a BootstrapperHealthResult::fromException entry.
 *   3. Zero events are dispatched (no TenantBootstrapped, no TenantResolved).
 */
final class BootstrapperChainHealthCheckTest extends TestCase
{
    private TenantInterface $tenant;
    private EventDispatcherInterface $spyDispatcher;

    protected function setUp(): void
    {
        $this->tenant = $this->createMock(TenantInterface::class);

        // Spy dispatcher: records all dispatched events so we can assert zero dispatches.
        $this->spyDispatcher = $this->createMock(EventDispatcherInterface::class);
    }

    /**
     * healthCheck() returns results only for bootstrappers implementing
     * HealthCheckBootstrapperInterface, skipping plain TenantBootstrapperInterface ones.
     */
    public function testHealthCheckSkipsNonImplementors(): void
    {
        // Spy dispatcher must receive zero dispatch calls during healthCheck.
        $this->spyDispatcher->expects($this->never())->method('dispatch');

        $chain = new BootstrapperChain($this->spyDispatcher);

        // A non-implementing bootstrapper — healthCheck() must skip it.
        $plainBootstrapper = $this->createMock(TenantBootstrapperInterface::class);
        $plainBootstrapper->expects($this->never())->method('boot');
        $plainBootstrapper->expects($this->never())->method('clear');

        // An implementing bootstrapper returning Pass.
        $implementorA = $this->createImplementorReturning(BootstrapperHealthResult::pass('ComponentA'));
        // Another implementing bootstrapper returning Fail.
        $implementorB = $this->createImplementorReturning(
            BootstrapperHealthResult::fail('ComponentB', 'DB unreachable'),
        );

        $chain->addBootstrapper($plainBootstrapper);
        $chain->addBootstrapper($implementorA);
        $chain->addBootstrapper($implementorB);

        $results = $chain->healthCheck($this->tenant);

        // Exactly 2 results — the non-implementor is skipped.
        $this->assertCount(2, $results);
        $this->assertSame(HealthStatus::Pass, $results[0]->status);
        $this->assertSame(HealthStatus::Fail, $results[1]->status);
    }

    /**
     * When a bootstrapper's check() throws, healthCheck() catches it and appends a
     * BootstrapperHealthResult::fromException entry rather than propagating the exception.
     */
    public function testHealthCheckCatchesThrowingProbeAndAppendsFailResult(): void
    {
        $this->spyDispatcher->expects($this->never())->method('dispatch');

        $chain = new BootstrapperChain($this->spyDispatcher);

        $exception = new \RuntimeException('Connection refused');
        $throwingImplementor = $this->createThrowingImplementor($exception);

        $chain->addBootstrapper($throwingImplementor);

        $results = $chain->healthCheck($this->tenant);

        $this->assertCount(1, $results);
        $this->assertSame(HealthStatus::Fail, $results[0]->status);
        $this->assertSame('Connection refused', $results[0]->output);
        $this->assertSame($exception, $results[0]->exception);
    }

    /**
     * healthCheck() dispatches ZERO events — no TenantBootstrapped, no TenantResolved.
     * Uses the spy EventDispatcher to assert dispatch() is never called.
     */
    public function testHealthCheckDispatchesZeroEvents(): void
    {
        // Strict: assert dispatch() is NEVER called.
        $this->spyDispatcher->expects($this->never())->method('dispatch');

        $chain = new BootstrapperChain($this->spyDispatcher);

        $implementor = $this->createImplementorReturning(BootstrapperHealthResult::pass('ComponentX'));
        $chain->addBootstrapper($implementor);

        $results = $chain->healthCheck($this->tenant);

        $this->assertCount(1, $results);
    }

    /**
     * healthCheck() on an empty chain returns an empty array without error.
     */
    public function testHealthCheckOnEmptyChainReturnsEmptyArray(): void
    {
        $this->spyDispatcher->expects($this->never())->method('dispatch');

        $chain = new BootstrapperChain($this->spyDispatcher);

        $results = $chain->healthCheck($this->tenant);

        $this->assertSame([], $results);
    }

    /**
     * boot() and clear() remain untouched — calling them still works as before.
     * This guards the additive-only invariant: healthCheck() must not break existing behavior.
     */
    public function testBootAndClearAreUntouchedByHealthCheckAddition(): void
    {
        $bootCallCount = 0;
        $clearCallCount = 0;

        $bootstrapper = $this->createMock(TenantBootstrapperInterface::class);
        $bootstrapper->method('boot')->willReturnCallback(function () use (&$bootCallCount): void {
            ++$bootCallCount;
        });
        $bootstrapper->method('clear')->willReturnCallback(function () use (&$clearCallCount): void {
            ++$clearCallCount;
        });

        $dispatchCount = 0;
        $this->spyDispatcher->method('dispatch')->willReturnCallback(function (object $e) use (&$dispatchCount): object {
            ++$dispatchCount;

            return $e;
        });

        $chain = new BootstrapperChain($this->spyDispatcher);
        $chain->addBootstrapper($bootstrapper);

        $chain->boot($this->tenant);
        $chain->clear();

        $this->assertSame(1, $bootCallCount, 'boot() still called exactly once');
        $this->assertSame(1, $clearCallCount, 'clear() still called exactly once');
        $this->assertSame(1, $dispatchCount, 'boot() still dispatches TenantBootstrapped');
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Creates a stub that implements both TenantBootstrapperInterface and
     * HealthCheckBootstrapperInterface, returning the given result from check().
     */
    private function createImplementorReturning(BootstrapperHealthResult $result): TenantBootstrapperInterface&HealthCheckBootstrapperInterface
    {
        // Create a real anonymous class implementing both interfaces.
        return new class($result) implements TenantBootstrapperInterface, HealthCheckBootstrapperInterface {
            public function __construct(private readonly BootstrapperHealthResult $result)
            {
            }

            public function boot(TenantInterface $tenant): void
            {
            }

            public function clear(): void
            {
            }

            public function check(TenantInterface $tenant): BootstrapperHealthResult
            {
                return $this->result;
            }
        };
    }

    /**
     * Creates a stub that implements both interfaces but throws from check().
     */
    private function createThrowingImplementor(\Throwable $exception): TenantBootstrapperInterface&HealthCheckBootstrapperInterface
    {
        return new class($exception) implements TenantBootstrapperInterface, HealthCheckBootstrapperInterface {
            public function __construct(private readonly \Throwable $exception)
            {
            }

            public function boot(TenantInterface $tenant): void
            {
            }

            public function clear(): void
            {
            }

            public function check(TenantInterface $tenant): BootstrapperHealthResult
            {
                throw $this->exception;
            }
        };
    }
}
