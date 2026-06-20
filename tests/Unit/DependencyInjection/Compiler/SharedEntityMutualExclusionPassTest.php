<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Unit\DependencyInjection\Compiler;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Tenancy\Bundle\Attribute\Shared;
use Tenancy\Bundle\Attribute\TenantAware;

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

        // The pass deliberately no-ops when Doctrine ORM is absent (optional dependency —
        // see SharedEntityMutualExclusionPass::process()). Without Doctrine there are no
        // shared entities to inspect, so the throwing tests below have no premise. Skip the
        // whole class in the no-doctrine CI lane; the dogfood step proves the pass still
        // loads without fatal.
        if (!interface_exists(\Doctrine\ORM\EntityManagerInterface::class)) {
            self::markTestSkipped(
                'SharedEntityMutualExclusionPass no-ops without Doctrine ORM — optional dependency.'
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

    /**
     * WR-02: Guard throws when a child INHERITS #[Shared] from a base and declares #[TenantAware]
     * directly. PHP class attributes are not inherited, so ReflectionClass::getAttributes() on the
     * child alone reports only #[TenantAware] and misses the inherited #[Shared] — before the fix
     * this invalid combination slipped through the guard. The hierarchy walk must catch it.
     */
    public function testGuardThrowsWhenSharedInheritedAndTenantAwareDeclared(): void
    {
        $container = new ContainerBuilder();
        $definition = (new Definition(InheritedSharedTenantAwareEntity::class))
            ->addTag('tenancy.shared_entity');
        $container->setDefinition(InheritedSharedTenantAwareEntity::class, $definition);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(InheritedSharedTenantAwareEntity::class);

        (new \Tenancy\Bundle\DependencyInjection\Compiler\SharedEntityMutualExclusionPass())->process($container);
    }

    /**
     * WR-02: Guard throws when a child inherits BOTH #[Shared] and #[TenantAware] from a base and
     * declares neither itself — the walk must inspect every ancestor, not just the leaf class.
     */
    public function testGuardThrowsWhenBothAttributesInheritedFromBase(): void
    {
        $container = new ContainerBuilder();
        $definition = (new Definition(InheritedBothAttributesEntity::class))
            ->addTag('tenancy.shared_entity');
        $container->setDefinition(InheritedBothAttributesEntity::class, $definition);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(InheritedBothAttributesEntity::class);

        (new \Tenancy\Bundle\DependencyInjection\Compiler\SharedEntityMutualExclusionPass())->process($container);
    }

    /**
     * WR-02: No exception when a child inherits ONLY #[Shared] from its base. The hierarchy walk
     * must not manufacture a false #[TenantAware] match from an ancestor that does not carry it —
     * guards against the walk over-triggering.
     */
    public function testNoExceptionWhenOnlySharedInherited(): void
    {
        $container = new ContainerBuilder();
        $definition = (new Definition(InheritedOnlySharedEntity::class))
            ->addTag('tenancy.shared_entity');
        $container->setDefinition(InheritedOnlySharedEntity::class, $definition);

        // No exception expected — only #[Shared] is present anywhere in the hierarchy
        (new \Tenancy\Bundle\DependencyInjection\Compiler\SharedEntityMutualExclusionPass())->process($container);
        $this->addToAssertionCount(1);
    }
}

/**
 * Test fixture: carries both #[Shared] and #[TenantAware] — invalid combination.
 * The pass must throw \LogicException when this class is tagged tenancy.shared_entity.
 */
#[Shared]
#[TenantAware]
final class BothAttributesEntity
{
}

/**
 * Test fixture: carries only #[Shared] — valid combination, no exception expected.
 */
#[Shared]
final class OnlySharedEntity
{
}

/**
 * Test fixture: carries both attributes but is NOT tagged tenancy.shared_entity.
 * Must be ignored by the pass.
 */
#[Shared]
#[TenantAware]
final class UntaggedBothAttributesEntity
{
}

/*
 * WR-02 inheritance fixtures.
 *
 * PHP class attributes are NOT inherited: ReflectionClass::getAttributes() on a child returns only
 * the attributes declared directly on that class, never those on a parent / mapped-superclass. A
 * shared base entity declaring #[Shared] that is extended by a tenant-scoped child is a realistic
 * Doctrine MappedSuperclass shape, so the guard must walk getParentClass() to see inherited
 * attributes — the behavior these fixtures exercise.
 */

/**
 * Abstract base carrying only #[Shared] — mimics a shared MappedSuperclass. Non-final so children
 * can extend it.
 */
#[Shared]
abstract class SharedBaseEntity
{
}

/**
 * Test fixture: INHERITS #[Shared] from SharedBaseEntity and declares #[TenantAware] directly —
 * invalid combination. The pass must throw even though the child's own getAttributes() sees only
 * #[TenantAware].
 */
#[TenantAware]
final class InheritedSharedTenantAwareEntity extends SharedBaseEntity
{
}

/**
 * Abstract base carrying BOTH #[Shared] and #[TenantAware].
 */
#[Shared]
#[TenantAware]
abstract class BothAttributesBaseEntity
{
}

/**
 * Test fixture: inherits BOTH attributes from BothAttributesBaseEntity and declares neither —
 * invalid combination. The pass must throw by inspecting the ancestor.
 */
final class InheritedBothAttributesEntity extends BothAttributesBaseEntity
{
}

/**
 * Test fixture: inherits ONLY #[Shared] from SharedBaseEntity — valid combination, no exception.
 */
final class InheritedOnlySharedEntity extends SharedBaseEntity
{
}
