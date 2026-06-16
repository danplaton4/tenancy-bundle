<?php

declare(strict_types=1);

namespace Tenancy\Bundle\PHPStan\Rule;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use Tenancy\Bundle\Attribute\Shared;

/**
 * Rule 2: fires when a concrete Doctrine EntityManager queries a #[Shared]
 * entity via find(), getReference(), or getRepository() — the static-analysis
 * catch for a cross-tenant data leak.
 *
 * A #[Shared] entity is a landlord-side master record. Querying it through the
 * tenant EntityManager risks returning the wrong tenant's copy (or leaking
 * landlord master data). Use the named landlord EntityManager instead, or
 * suppress the individual call site with the tenancy.sharedEntityLeak identifier
 * when you have verified that the query is going through the landlord path.
 *
 * Conservative (D-03): fires ONLY when BOTH conditions hold:
 *   1. The caller type is the CONCRETE class Doctrine\ORM\EntityManager (not
 *      the EntityManagerInterface — PHPStan cannot distinguish the landlord EM
 *      from the tenant EM when both are typed as the interface).
 *   2. The queried entity is resolvable from a literal ::class constant argument
 *      and carries #[Shared] (directly or on an ancestor class).
 * Stays silent on EntityManagerInterface-typed callers and non-literal entity
 * arguments. The landlord EM path is inherently silent because it is injected
 * as an interface in most application code.
 *
 * Gated by the tenancy.checkSharedEntityLeaks parameter (default true, D-01).
 * The real static signal for "safe" is which EntityManager the query goes through
 * — not a fluent setter or service name.
 *
 * @implements Rule<Node\Expr\MethodCall>
 */
final class SharedEntityLeakRule implements Rule
{
    /** @var list<string> Methods whose first argument is a ::class entity constant */
    private const EM_QUERY_METHODS = ['find', 'getReference', 'getRepository'];

    public function __construct(
        private readonly bool $checkSharedEntityLeaks,
        private readonly ReflectionProvider $reflectionProvider,
    ) {
    }

    public function getNodeType(): string
    {
        return Node\Expr\MethodCall::class;
    }

    /**
     * @return list<\PHPStan\Rules\IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        assert($node instanceof Node\Expr\MethodCall);

        // D-01: gate — if the check is disabled, stay silent
        if (!$this->checkSharedEntityLeaks) {
            return [];
        }

        // Optional-dep guard: Doctrine ORM is not a hard dependency of the bundle.
        // Mirror SharedEntityMutualExclusionPass line 44.
        if (!interface_exists(\Doctrine\ORM\EntityManagerInterface::class)) {
            return [];
        }

        // Check method name: only inspect find, getReference, getRepository
        if (!$node->name instanceof Node\Identifier) {
            return [];
        }

        $methodName = $node->name->toString();
        if (!\in_array($methodName, self::EM_QUERY_METHODS, true)) {
            return [];
        }

        // D-03 conservative: fire only when the caller is the CONCRETE EntityManager class.
        // Both the landlord and tenant EMs may share EntityManagerInterface as their type;
        // PHPStan cannot distinguish them by type alone. Firing on the concrete class
        // catches the clear unambiguous case (direct default EM injection typed as
        // EntityManager) while staying silent on interface-typed callers.
        $callerType = $scope->getType($node->var);
        $callerClassNames = $callerType->getObjectClassNames();

        $isConcreteEntityManager = false;
        foreach ($callerClassNames as $callerClassName) {
            if (\Doctrine\ORM\EntityManager::class === $callerClassName) {
                $isConcreteEntityManager = true;
                break;
            }
        }

        if (!$isConcreteEntityManager) {
            return [];
        }

        // Resolve the entity class from the first argument.
        // Conservative: only proceed when the argument is a literal ::class constant.
        // Non-literal (variable, expression) is left silent to avoid false positives.
        $args = $node->getArgs();
        if ([] === $args) {
            return [];
        }

        $firstArgType = $scope->getType($args[0]->value);
        $entityClassStrings = $firstArgType->getConstantStrings();

        if ([] === $entityClassStrings) {
            return [];
        }

        $errors = [];
        foreach ($entityClassStrings as $entityClassString) {
            $entityClass = $entityClassString->getValue();

            if (!$this->reflectionProvider->hasClass($entityClass)) {
                continue;
            }

            $classReflection = $this->reflectionProvider->getClass($entityClass);

            if (!$this->hasSharedInHierarchy($classReflection)) {
                continue;
            }

            $errors[] = RuleErrorBuilder::message(sprintf(
                'Entity %s is #[Shared] (a landlord-side master). Querying it through the tenant EntityManager '
                .'risks a cross-tenant data leak. Route the query through the named landlord EntityManager '
                .'or suppress with @phpstan-ignore tenancy.sharedEntityLeak.',
                $entityClass,
            ))
                ->identifier('tenancy.sharedEntityLeak')
                ->build();
        }

        return $errors;
    }

    /**
     * Walk up the PHPStan ClassReflection hierarchy to check for the #[Shared] attribute.
     *
     * PHP class attributes are NOT inherited — ReflectionClass::getAttributes() only
     * reports attributes declared directly on that class. Walking getParentClass()
     * explicitly discovers #[Shared] on ancestor classes.
     *
     * Uses PHPStan's ClassReflection API (not PHP native reflection) to avoid
     * type-incompatibility with BetterReflection adapter types at PHPStan level 9.
     * Mirrors the pattern from MutualExclusionRule and TenantIdDriftRule.
     */
    private function hasSharedInHierarchy(ClassReflection $classReflection): bool
    {
        $current = $classReflection;

        do {
            $nativeReflection = $current->getNativeReflection();
            if ([] !== $nativeReflection->getAttributes(Shared::class)) {
                return true;
            }
            $current = $current->getParentClass();
        } while ($current instanceof ClassReflection);

        return false;
    }
}
