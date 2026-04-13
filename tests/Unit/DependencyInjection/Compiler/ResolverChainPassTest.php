<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Unit\DependencyInjection\Compiler;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Tenancy\Bundle\DependencyInjection\Compiler\ResolverChainPass;
use Tenancy\Bundle\Resolver\ResolverChain;

final class ResolverChainPassTest extends TestCase
{
    public function testProcessDoesNothingWhenChainServiceMissing(): void
    {
        $container = new ContainerBuilder();
        $pass = new ResolverChainPass();

        // No ResolverChain definition registered — pass should bail out silently
        $pass->process($container);

        // If we reach this line, no exception was thrown
        $this->assertTrue(true);
    }

    public function testProcessCollectsTaggedServicesInPriorityOrder(): void
    {
        $container = new ContainerBuilder();

        // Register the ResolverChain definition
        $chainDefinition = new Definition(ResolverChain::class);
        $container->setDefinition(ResolverChain::class, $chainDefinition);

        // Register two resolver services with different priorities
        $lowPriorityDefinition = new Definition('stdClass');
        $lowPriorityDefinition->addTag('tenancy.resolver', ['priority' => 10]);
        $container->setDefinition('resolver.low_priority', $lowPriorityDefinition);

        $highPriorityDefinition = new Definition('stdClass');
        $highPriorityDefinition->addTag('tenancy.resolver', ['priority' => 20]);
        $container->setDefinition('resolver.high_priority', $highPriorityDefinition);

        $pass = new ResolverChainPass();
        $pass->process($container);

        $methodCalls = $chainDefinition->getMethodCalls();

        $this->assertCount(2, $methodCalls, 'Expected two addResolver method calls on the chain definition');

        // Higher priority (20) should be injected first
        [$firstCall, $secondCall] = $methodCalls;
        $this->assertSame('addResolver', $firstCall[0]);
        $this->assertSame('addResolver', $secondCall[0]);

        // The first reference should point to the high-priority service
        $this->assertSame('resolver.high_priority', (string) $firstCall[1][0]);
        $this->assertSame('resolver.low_priority', (string) $secondCall[1][0]);
    }

    public function testProcessHandlesNoTaggedServices(): void
    {
        $container = new ContainerBuilder();

        // Register the chain definition but no tagged resolvers
        $chainDefinition = new Definition(ResolverChain::class);
        $container->setDefinition(ResolverChain::class, $chainDefinition);

        $pass = new ResolverChainPass();
        $pass->process($container);

        $methodCalls = $chainDefinition->getMethodCalls();

        $this->assertCount(0, $methodCalls, 'Expected no addResolver calls when no tagged services exist');
    }
}
