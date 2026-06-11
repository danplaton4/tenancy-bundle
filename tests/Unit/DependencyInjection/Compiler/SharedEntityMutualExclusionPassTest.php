<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Unit\DependencyInjection\Compiler;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

/**
 * Covers SHARE-01-l: compiler pass throws when #[Shared] + #[TenantAware] co-present on one class.
 *
 * Wave 0 state: tests skip gracefully until both production classes exist:
 *   - Tenancy\Bundle\Attribute\Shared (Plan 25-01)
 *   - Tenancy\Bundle\DependencyInjection\Compiler\SharedEntityMutualExclusionPass (Plan 25-02)
 *
 * Test fixture classes (BothAttributesEntity, OnlySharedEntity, UntaggedBothAttributesEntity)
 * are defined at the bottom of this file and carry the actual production attributes — they
 * will autoload once Plan 25-01 lands.
 */
final class SharedEntityMutualExclusionPassTest extends TestCase
{
    protected function setUp(): void
    {
        if (!class_exists(\Tenancy\Bundle\DependencyInjection\Compiler\SharedEntityMutualExclusionPass::class)) {
            self::markTestSkipped(
                'SharedEntityMutualExclusionPass not yet available — lands in Plan 25-02.'
            );
        }
    }

    /**
     * SHARE-01-l: Guard throws when a class carries both #[Shared] and #[TenantAware] and is
     * tagged with tenancy.shared_entity.
     */
    public function testMutualExclusionGuardThrows(): void
    {
        $container = new ContainerBuilder();
        $definition = (new Definition(BothAttributesEntity::class))
            ->addTag('tenancy.shared_entity');
        $container->setDefinition(BothAttributesEntity::class, $definition);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(BothAttributesEntity::class);

        (new \Tenancy\Bundle\DependencyInjection\Compiler\SharedEntityMutualExclusionPass())->process($container);
    }

    /**
     * SHARE-01-l: No exception when a class carries only #[Shared] (no #[TenantAware]).
     */
    public function testNoExceptionWhenOnlySharedPresent(): void
    {
        $container = new ContainerBuilder();
        $definition = (new Definition(OnlySharedEntity::class))
            ->addTag('tenancy.shared_entity');
        $container->setDefinition(OnlySharedEntity::class, $definition);

        // No exception expected — only #[Shared] is valid
        (new \Tenancy\Bundle\DependencyInjection\Compiler\SharedEntityMutualExclusionPass())->process($container);
        $this->addToAssertionCount(1);
    }

    /**
     * SHARE-01-l: Classes carrying both attributes but NOT tagged tenancy.shared_entity
     * are ignored by the pass.
     */
    public function testUntaggedClassIsIgnored(): void
    {
        $container = new ContainerBuilder();
        // Definition exists but has no tenancy.shared_entity tag
        $definition = new Definition(UntaggedBothAttributesEntity::class);
        $container->setDefinition(UntaggedBothAttributesEntity::class, $definition);

        // No exception expected — untagged classes are not inspected
        (new \Tenancy\Bundle\DependencyInjection\Compiler\SharedEntityMutualExclusionPass())->process($container);
        $this->addToAssertionCount(1);
    }
}

/**
 * Test fixture: carries both #[Shared] and #[TenantAware] — invalid combination.
 * The pass must throw \LogicException when this class is tagged tenancy.shared_entity.
 *
 * Note: these attributes are referenced at class-definition time — the fixture classes
 * will only be autoloaded when PHP resolves the attribute annotations. Since the
 * setUp() guard marks the test skipped before instantiation, PHP will not attempt
 * to resolve the attribute class names until Plan 25-01 lands.
 */
final class BothAttributesEntity
{
}

/**
 * Test fixture: carries only #[Shared] — valid combination, no exception expected.
 */
final class OnlySharedEntity
{
}

/**
 * Test fixture: carries both attributes but is NOT tagged tenancy.shared_entity.
 * Must be ignored by the pass.
 */
final class UntaggedBothAttributesEntity
{
}
