<?php

declare(strict_types=1);

namespace Tenancy\Bundle\PHPStan\Rule\Helper;

final class AttributeHierarchyHelper
{
    /**
     * Whether $rc or any of its ancestor classes carries the given attribute.
     *
     * PHP class attributes are not inherited and ReflectionClass::getAttributes()
     * reports only attributes declared directly on the reflected class, so a
     * #[Shared]/#[TenantAware] declared on a parent or mapped-superclass must be
     * discovered by walking getParentClass() explicitly.
     *
     * Mirrors SharedEntityMutualExclusionPass::hasAttributeInHierarchy() — that pass
     * is the boot-time twin of the Rules that use this helper at edit-time.
     *
     * The parameter accepts any ReflectionClass regardless of its template type T,
     * because we only call getAttributes() and getParentClass() — both are invariant
     * with respect to T. PHPStan's BetterReflection adapters are subtypes of
     * ReflectionClass<never> at the generic level, so the wider union is used.
     *
     * @param \ReflectionClass<covariant object> $rc
     * @param class-string                       $attribute
     *
     * @phpstan-param \ReflectionClass<object> $rc
     */
    public function hasAttributeInHierarchy(\ReflectionClass $rc, string $attribute): bool
    {
        for ($current = $rc; false !== $current; $current = $current->getParentClass()) {
            if ([] !== $current->getAttributes($attribute)) {
                return true;
            }
        }

        return false;
    }
}
