<?php

declare(strict_types=1);

namespace Tenancy\Bundle\PHPStan\Rule;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InClassNode;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use Tenancy\Bundle\Attribute\Shared;
use Tenancy\Bundle\Attribute\TenantAware;

/**
 * Rule 1: fires when a class carries BOTH #[Shared] and #[TenantAware].
 *
 * A shared entity is a landlord-side master; a TenantAware entity is
 * tenant-scoped. They are mutually exclusive.
 *
 * Edit-time complement to SharedEntityMutualExclusionPass (boot-time guard).
 * Unlike the pass, this rule scans attributes directly — no tenancy.shared_entity
 * container tag dependency.
 *
 * @implements Rule<InClassNode>
 */
final class MutualExclusionRule implements Rule
{
    public function getNodeType(): string
    {
        return InClassNode::class;
    }

    /**
     * @return list<\PHPStan\Rules\IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        assert($node instanceof InClassNode);

        $classReflection = $node->getClassReflection();

        $hasShared = $this->hasAttributeInHierarchy($classReflection, Shared::class);
        $hasTenantAware = $this->hasAttributeInHierarchy($classReflection, TenantAware::class);

        if (!$hasShared || !$hasTenantAware) {
            return [];
        }

        $className = $classReflection->getName();

        return [
            RuleErrorBuilder::message(sprintf(
                'Entity %s cannot carry both #[Shared] and #[TenantAware]. A shared entity is a landlord-side master; a TenantAware entity is tenant-scoped. Pick one.',
                $className,
            ))
                ->identifier('tenancy.mutualExclusion')
                ->build(),
        ];
    }

    /**
     * Walk up the PHPStan ClassReflection hierarchy to check for the given attribute.
     *
     * PHP class attributes are NOT inherited — ReflectionClass::getAttributes() only
     * reports attributes declared directly on that class. Walking getParentClass()
     * explicitly discovers attributes on ancestor classes.
     *
     * Uses PHPStan's own ClassReflection API (not PHP native reflection) to avoid
     * type-incompatibility with BetterReflection adapter types at level 9.
     *
     * @param class-string $attribute
     */
    private function hasAttributeInHierarchy(ClassReflection $classReflection, string $attribute): bool
    {
        $current = $classReflection;

        do {
            $nativeReflection = $current->getNativeReflection();
            if ([] !== $nativeReflection->getAttributes($attribute)) {
                return true;
            }
            $current = $current->getParentClass();
        } while ($current instanceof ClassReflection);

        return false;
    }
}
