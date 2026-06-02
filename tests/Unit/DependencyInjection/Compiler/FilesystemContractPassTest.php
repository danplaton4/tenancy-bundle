<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Unit\DependencyInjection\Compiler;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Tenancy\Bundle\DependencyInjection\Compiler\FilesystemContractPass;
use Tenancy\Bundle\Filesystem\FilesystemPrefixingDecorator;
use Tenancy\Bundle\Filesystem\TenantAwareFilesystemDecorator;

/**
 * Behavioural tests for FilesystemContractPass.
 *
 * Covers all behaviours from the plan:
 * - Guard 1 (bundle-absent): cannot be exercised when league/flysystem-bundle is installed.
 * - Guard 2: per_tenant_adapter strategy + allow_per_tenant_adapter=false → LogicException.
 * - Guard 3: invalid strategy attribute → LogicException.
 * - Tag walking — prefix path: decorator Definition registered under <id>.tenant_scoped.
 * - Tag walking — per_tenant_adapter path: TenantAwareFilesystemDecorator registered.
 * - Untagged storage: not decorated.
 * - Empty container: clean pass, no exception.
 * - Disabled (default): early return, no decoration even when tags present.
 * - Effective prefix_template default: decorator 3rd argument is 'tenant_{slug}/'.
 */
final class FilesystemContractPassTest extends TestCase
{
    /**
     * Guard 1: league/flysystem-bundle absent + enabled=true.
     *
     * This cannot be exercised as a live test when league/flysystem-bundle is present
     * in require-dev (as it is in this project). The bundle-absent guard is tested
     * indirectly: when enabled=false (default) the pass returns early regardless of
     * interface presence — see testDisabledReturnsEarlyWithoutDecoration().
     *
     * The integration test in Plan 24-08 covers guard 1 via a stub kernel.
     */
    public function testGuard1BundleAbsentIsSkippedWhenInstalled(): void
    {
        self::markTestSkipped(
            'league/flysystem-bundle present in require-dev — cannot exercise the bundle-absent guard.'
            .' Integration test in Plan 24-08 covers it via a stub kernel.'
        );
    }

    public function testGuard2RejectsPerTenantAdapterWhenForbidden(): void
    {
        $container = $this->makeContainer(enabled: true, allowPerTenant: false);
        $container->setDefinition('users.storage', (new Definition())->addTag('tenancy.scoped', ['strategy' => 'per_tenant_adapter']));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('users.storage');
        $this->expectExceptionMessage('allow_per_tenant_adapter');

        (new FilesystemContractPass())->process($container);
    }

    public function testGuard3RejectsInvalidStrategy(): void
    {
        $container = $this->makeContainer(enabled: true, allowPerTenant: true);
        $container->setDefinition('users.storage', (new Definition())->addTag('tenancy.scoped', ['strategy' => 'nope']));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('users.storage');
        $this->expectExceptionMessage('nope');

        (new FilesystemContractPass())->process($container);
    }

    public function testPrefixStrategyRegistersFilesystemPrefixingDecorator(): void
    {
        $container = $this->makeContainer(enabled: true, allowPerTenant: true);
        $container->setDefinition('users.storage', (new Definition())->addTag('tenancy.scoped', ['strategy' => 'prefix']));

        (new FilesystemContractPass())->process($container);

        self::assertTrue($container->hasDefinition('users.storage.tenant_scoped'), 'Decorator Definition must be registered under <id>.tenant_scoped');
        self::assertSame(FilesystemPrefixingDecorator::class, $container->getDefinition('users.storage.tenant_scoped')->getClass());
    }

    public function testPerTenantAdapterStrategyRegistersTenantAwareDecorator(): void
    {
        $container = $this->makeContainer(enabled: true, allowPerTenant: true);
        $container->setDefinition('users.storage', (new Definition())->addTag('tenancy.scoped', ['strategy' => 'per_tenant_adapter']));

        (new FilesystemContractPass())->process($container);

        self::assertTrue($container->hasDefinition('users.storage.tenant_scoped'));
        self::assertSame(TenantAwareFilesystemDecorator::class, $container->getDefinition('users.storage.tenant_scoped')->getClass());
    }

    public function testUntaggedStorageIsNotDecorated(): void
    {
        $container = $this->makeContainer(enabled: true, allowPerTenant: true);
        // Tagged with flysystem.storage but NOT tenancy.scoped
        $container->setDefinition('public.storage', (new Definition())->addTag('flysystem.storage'));

        (new FilesystemContractPass())->process($container);

        self::assertFalse($container->hasDefinition('public.storage.tenant_scoped'), 'Untagged service must not get a decorator');
    }

    public function testEmptyContainerPassesCleanly(): void
    {
        $container = $this->makeContainer(enabled: true, allowPerTenant: true);

        // No exception expected
        (new FilesystemContractPass())->process($container);
        $this->addToAssertionCount(1);
    }

    public function testDisabledReturnsEarlyWithoutDecoration(): void
    {
        $container = $this->makeContainer(enabled: false, allowPerTenant: true);
        // Even with tenancy.scoped tags present, the pass must no-op when disabled
        $container->setDefinition('users.storage', (new Definition())->addTag('tenancy.scoped', ['strategy' => 'prefix']));

        (new FilesystemContractPass())->process($container);

        self::assertFalse($container->hasDefinition('users.storage.tenant_scoped'), 'Pass must be no-op when tenancy.filesystem.enabled is false');
    }

    public function testDisabledByMissingParameterReturnsEarly(): void
    {
        // Container with no parameters at all — should be treated as disabled
        $container = new ContainerBuilder();
        $container->setDefinition('users.storage', (new Definition())->addTag('tenancy.scoped', ['strategy' => 'prefix']));

        (new FilesystemContractPass())->process($container);

        self::assertFalse($container->hasDefinition('users.storage.tenant_scoped'));
    }

    public function testDefaultPrefixTemplateIsUsedWhenAttributeAbsent(): void
    {
        $container = $this->makeContainer(enabled: true, allowPerTenant: true);
        // Tag without prefix_template attribute
        $container->setDefinition('users.storage', (new Definition())->addTag('tenancy.scoped', ['strategy' => 'prefix']));

        (new FilesystemContractPass())->process($container);

        $args = $container->getDefinition('users.storage.tenant_scoped')->getArguments();
        // Third argument (index 2) is the prefix template
        self::assertSame('tenant_{slug}/', $args[2], 'Default prefix_template must be tenant_{slug}/');
    }

    public function testCustomPrefixTemplateIsPassedToDecorator(): void
    {
        $container = $this->makeContainer(enabled: true, allowPerTenant: true);
        $container->setDefinition('users.storage', (new Definition())->addTag('tenancy.scoped', [
            'strategy' => 'prefix',
            'prefix_template' => 'uploads/{slug}/',
        ]));

        (new FilesystemContractPass())->process($container);

        $args = $container->getDefinition('users.storage.tenant_scoped')->getArguments();
        self::assertSame('uploads/{slug}/', $args[2]);
    }

    /**
     * Helper: build a ContainerBuilder with the tenancy.filesystem parameters set.
     */
    private function makeContainer(bool $enabled, bool $allowPerTenant): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('tenancy.filesystem.enabled', $enabled);
        $container->setParameter('tenancy.filesystem.allow_per_tenant_adapter', $allowPerTenant);

        return $container;
    }
}
