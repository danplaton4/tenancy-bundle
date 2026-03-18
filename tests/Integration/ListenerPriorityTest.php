<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\KernelEvents;
use Tenancy\Bundle\EventListener\TenantContextOrchestrator;

final class ListenerPriorityTest extends TestCase
{
    private static TestKernel $kernel;

    public static function setUpBeforeClass(): void
    {
        static::$kernel = new TestKernel('test', false);
        static::$kernel->boot();
    }

    public static function tearDownAfterClass(): void
    {
        static::$kernel->shutdown();
    }

    public function testOrchestratorRegisteredAtPriority20OnKernelRequest(): void
    {
        /** @var \Symfony\Component\EventDispatcher\EventDispatcherInterface $dispatcher */
        $dispatcher = static::$kernel->getContainer()->get('event_dispatcher');

        $listeners = $dispatcher->getListeners(KernelEvents::REQUEST);

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
}
