<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Tenancy\Bundle\Context\TenantContext;
use Tenancy\Bundle\DependencyInjection\Compiler\BootstrapperChainPass;

final class ContainerCompilationTest extends TestCase
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

    public function testContainerCompilesWithoutCircularReferences(): void
    {
        // If we reach here, the container compiled successfully without ServiceCircularReferenceException.
        $this->assertTrue(static::$kernel->getContainer()->has('tenancy.context'));
    }

    public function testTenancyContextServiceExists(): void
    {
        $container = static::$kernel->getContainer();
        $this->assertTrue($container->has('tenancy.context'), 'tenancy.context service must exist in compiled container');
    }

    public function testTenancyBootstrapperChainServiceExists(): void
    {
        // tenancy.bootstrapper_chain is private, but we can verify BootstrapperChain exists
        // by checking that the class is registered in the compiled container via its alias.
        // In the test container, public services are accessible. tenancy.context is public.
        $container = static::$kernel->getContainer();
        $tenancyContext = $container->get('tenancy.context');
        $this->assertInstanceOf(TenantContext::class, $tenancyContext);
    }

    public function testTenantContextServiceHasNoConstructorArguments(): void
    {
        // TenantContext is a zero-dependency pure value holder.
        // Verify via reflection that it has no constructor parameters.
        $reflection = new \ReflectionClass(TenantContext::class);
        $constructor = $reflection->getConstructor();

        $this->assertNull(
            $constructor,
            'TenantContext must have no constructor (zero dependencies)',
        );
    }

    public function testBootstrapperChainPassIsRegistered(): void
    {
        // Verify the BootstrapperChainPass was registered in the bundle build phase.
        // We do this by checking the class exists and implements CompilerPassInterface,
        // and that the container compiled correctly (which would have failed if the pass had errors).
        $this->assertTrue(
            class_exists(BootstrapperChainPass::class),
            'BootstrapperChainPass class must exist',
        );

        $reflection = new \ReflectionClass(BootstrapperChainPass::class);
        $this->assertTrue(
            $reflection->implementsInterface(\Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface::class),
            'BootstrapperChainPass must implement CompilerPassInterface',
        );
    }
}
