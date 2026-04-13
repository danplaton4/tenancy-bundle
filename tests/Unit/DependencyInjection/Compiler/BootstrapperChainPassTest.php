<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Unit\DependencyInjection\Compiler;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Tenancy\Bundle\Bootstrapper\BootstrapperChain;
use Tenancy\Bundle\DependencyInjection\Compiler\BootstrapperChainPass;

final class BootstrapperChainPassTest extends TestCase
{
    public function testProcessRemovesDoctrineBootstrapperWhenNoEntityManager(): void
    {
        $container = new ContainerBuilder();

        // Register DoctrineBootstrapper but no entity manager
        $container->setDefinition('tenancy.doctrine_bootstrapper', new Definition(\stdClass::class));

        // Register the chain so the pass doesn't bail early
        $chainDefinition = new Definition(BootstrapperChain::class);
        $container->setDefinition(BootstrapperChain::class, $chainDefinition);

        $pass = new BootstrapperChainPass();
        $pass->process($container);

        $this->assertFalse($container->hasDefinition('tenancy.doctrine_bootstrapper'));
    }

    public function testProcessKeepsDoctrineBootstrapperWhenEntityManagerExists(): void
    {
        $container = new ContainerBuilder();

        // Register DoctrineBootstrapper AND an entity manager
        $container->setDefinition('tenancy.doctrine_bootstrapper', new Definition(\stdClass::class));
        $container->setDefinition('doctrine.orm.entity_manager', new Definition(\stdClass::class));

        $chainDefinition = new Definition(BootstrapperChain::class);
        $container->setDefinition(BootstrapperChain::class, $chainDefinition);

        $pass = new BootstrapperChainPass();
        $pass->process($container);

        $this->assertTrue($container->hasDefinition('tenancy.doctrine_bootstrapper'));
    }

    public function testProcessDoesNothingWhenChainServiceMissing(): void
    {
        $container = new ContainerBuilder();
        $pass = new BootstrapperChainPass();

        // No BootstrapperChain definition registered — pass should bail out silently
        $pass->process($container);

        // If we reach this line, no exception was thrown
        $this->assertTrue(true);
    }

    public function testProcessCollectsTaggedServicesInPriorityOrder(): void
    {
        $container = new ContainerBuilder();

        // Register the BootstrapperChain definition
        $chainDefinition = new Definition(BootstrapperChain::class);
        $container->setDefinition(BootstrapperChain::class, $chainDefinition);

        // Register two bootstrapper services with different priorities
        $lowPriorityDefinition = new Definition('stdClass');
        $lowPriorityDefinition->addTag('tenancy.bootstrapper', ['priority' => 10]);
        $container->setDefinition('bootstrapper.low_priority', $lowPriorityDefinition);

        $highPriorityDefinition = new Definition('stdClass');
        $highPriorityDefinition->addTag('tenancy.bootstrapper', ['priority' => 20]);
        $container->setDefinition('bootstrapper.high_priority', $highPriorityDefinition);

        $pass = new BootstrapperChainPass();
        $pass->process($container);

        $methodCalls = $chainDefinition->getMethodCalls();

        $this->assertCount(2, $methodCalls, 'Expected two addBootstrapper method calls on the chain definition');

        // Higher priority (20) should be injected first
        [$firstCall, $secondCall] = $methodCalls;
        $this->assertSame('addBootstrapper', $firstCall[0]);
        $this->assertSame('addBootstrapper', $secondCall[0]);

        // The first reference should point to the high-priority service
        $this->assertSame('bootstrapper.high_priority', (string) $firstCall[1][0]);
        $this->assertSame('bootstrapper.low_priority', (string) $secondCall[1][0]);
    }

    public function testProcessHandlesNoTaggedServices(): void
    {
        $container = new ContainerBuilder();

        // Register the chain definition but no tagged bootstrappers
        $chainDefinition = new Definition(BootstrapperChain::class);
        $container->setDefinition(BootstrapperChain::class, $chainDefinition);

        $pass = new BootstrapperChainPass();
        $pass->process($container);

        $methodCalls = $chainDefinition->getMethodCalls();

        $this->assertCount(0, $methodCalls, 'Expected no addBootstrapper calls when no tagged services exist');
    }
}
