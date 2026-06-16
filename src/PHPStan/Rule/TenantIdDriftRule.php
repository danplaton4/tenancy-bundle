<?php

declare(strict_types=1);

namespace Tenancy\Bundle\PHPStan\Rule;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InClassNode;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use Tenancy\Bundle\Attribute\TenantAware;

/**
 * Rule 3: fires when a #[TenantAware] entity's tenant_id column is missing,
 * nullable, or not a string type.
 *
 * The TenantAwareFilter hardcodes `tenant_id` as a quoted-string comparison
 * (`%s.tenant_id = '%s'`). A missing, nullable, or non-string-typed column
 * is a latent cross-tenant data leak that this rule catches at analysis time.
 *
 * D-04: name + nullable + string-type checks. No length assertion.
 * D-02: reflection fallback primary (attribute-mapped entities);
 *       ObjectMetadataResolver (phpstan-doctrine) optional/guarded path.
 *
 * @implements Rule<InClassNode>
 */
final class TenantIdDriftRule implements Rule
{
    /** @var list<string> Case-insensitive string Doctrine type names accepted for tenant_id */
    private const STRING_TYPES = ['string', 'ascii_string', 'guid', 'uuid'];

    public function __construct(
        // D-02: optional — injected by phpstan-doctrine when installed; null when absent.
        // Using ?object (NOT ?ObjectMetadataResolver) so the class loads when phpstan-doctrine is absent.
        private readonly ?object $objectMetadataResolver = null,
    ) {
    }

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

        // Optional-dep guard: Doctrine ORM is not a hard dependency of the bundle.
        // Mirror SharedEntityMutualExclusionPass line 44.
        if (!interface_exists(\Doctrine\ORM\EntityManagerInterface::class)) {
            return [];
        }

        $classReflection = $node->getClassReflection();

        // Walk class hierarchy via PHPStan ClassReflection (NOT native reflection)
        // to avoid BetterReflection adapter type incompatibility at level 9.
        if (!$this->hasTenantAwareInHierarchy($classReflection)) {
            return [];
        }

        $className = $classReflection->getName();

        // D-02: optional ObjectMetadataResolver path (phpstan-doctrine installed)
        if (null !== $this->objectMetadataResolver
            && class_exists(\PHPStan\Type\Doctrine\ObjectMetadataResolver::class)) {
            /** @var \PHPStan\Type\Doctrine\ObjectMetadataResolver $resolver */
            $resolver = $this->objectMetadataResolver;
            $metadata = $resolver->getClassMetadata($className);
            if (null !== $metadata) {
                return $this->checkViaMetadata($metadata, $className);
            }
            // Fall through to reflection fallback if metadata is null
        }

        // PRIMARY: reflection fallback — scan #[ORM\Column] attributes across the hierarchy
        return $this->checkViaReflection($classReflection, $className);
    }

    /**
     * Walk up the PHPStan ClassReflection hierarchy to check for #[TenantAware].
     *
     * PHP class attributes are NOT inherited. Walking getParentClass() is required
     * to detect #[TenantAware] declared on a parent or MappedSuperclass.
     * Uses PHPStan's ClassReflection API to avoid BetterReflection adapter type issues.
     */
    private function hasTenantAwareInHierarchy(ClassReflection $classReflection): bool
    {
        $current = $classReflection;

        do {
            $nativeReflection = $current->getNativeReflection();
            if ([] !== $nativeReflection->getAttributes(TenantAware::class)) {
                return true;
            }
            $current = $current->getParentClass();
        } while ($current instanceof ClassReflection);

        return false;
    }

    /**
     * Check tenant_id column mapping via Doctrine ClassMetadata (phpstan-doctrine path).
     *
     * @param object $metadata Doctrine ClassMetadata instance (typed as object to avoid hard import)
     *
     * @return list<\PHPStan\Rules\IdentifierRuleError>
     */
    private function checkViaMetadata(object $metadata, string $className): array
    {
        // Access fieldMappings via array cast to avoid hard ClassMetadata dependency.
        // ClassMetadata::$fieldMappings is a public array<string, mixed> in Doctrine ORM 2.x/3.x.
        $raw = (array) $metadata;
        /** @var array<string, mixed> $fieldMappings */
        $fieldMappings = $raw['fieldMappings'] ?? [];

        // fieldMappings is keyed by property name in Doctrine; column name is in 'columnName' key
        $found = null;
        foreach ($fieldMappings as $mapping) {
            if (!is_array($mapping)) {
                continue;
            }
            $colName = $mapping['columnName'] ?? $mapping['column'] ?? null;
            if ('tenant_id' === $colName) {
                $found = [
                    'nullable' => (bool) ($mapping['nullable'] ?? false),
                    'type' => isset($mapping['type']) && is_string($mapping['type']) ? $mapping['type'] : null,
                ];
                break;
            }
        }

        return $this->evaluateFinding($found, $className);
    }

    /**
     * Check tenant_id column mapping via PHP reflection of #[ORM\Column] attributes.
     *
     * PRIMARY CI-tested path. Walks properties across the full class hierarchy
     * (including parents/MappedSuperclass) so an inherited tenant_id property is found.
     *
     * Handles:
     *   - Explicit name: #[ORM\Column(name: 'tenant_id')]
     *   - Positional name: #[ORM\Column('tenant_id')]
     *   - Default name: property $tenant_id → column tenant_id (exact match)
     *   - Default name: property $tenantId → column tenant_id (camelCase → snake_case)
     *
     * @return list<\PHPStan\Rules\IdentifierRuleError>
     */
    private function checkViaReflection(ClassReflection $classReflection, string $className): array
    {
        $found = null;

        // Walk ClassReflection hierarchy to collect all properties (including inherited)
        $current = $classReflection;
        while ($current instanceof ClassReflection) {
            $nativeReflection = $current->getNativeReflection();

            foreach ($nativeReflection->getProperties() as $property) {
                foreach ($property->getAttributes(\Doctrine\ORM\Mapping\Column::class) as $attr) {
                    $args = $attr->getArguments();

                    // Resolve the column name:
                    // 1. Explicit named arg: #[ORM\Column(name: 'tenant_id')]
                    // 2. Positional arg: #[ORM\Column('tenant_id')]
                    // 3. Default: Doctrine derives from property name (snake_case)
                    $colName = $args['name'] ?? $args[0] ?? null;

                    if (null === $colName) {
                        // Derive from property name using Doctrine's default naming:
                        // $tenantId → tenant_id (camelCase → underscore_case)
                        $propName = $property->getName();
                        $colName = strtolower((string) preg_replace('/([A-Z])/', '_$1', lcfirst($propName)));
                    }

                    if ('tenant_id' === $colName) {
                        $found = [
                            'nullable' => (bool) ($args['nullable'] ?? false),
                            'type' => isset($args['type']) && is_string($args['type']) ? $args['type'] : null,
                        ];
                        break 2;
                    }
                }
            }

            if (null !== $found) {
                break;
            }

            $current = $current->getParentClass();
        }

        return $this->evaluateFinding($found, $className);
    }

    /**
     * Evaluate the found column mapping (or its absence) and return errors.
     *
     * @param array{nullable?: bool, type?: string|null}|null $found
     *
     * @return list<\PHPStan\Rules\IdentifierRuleError>
     */
    private function evaluateFinding(?array $found, string $className): array
    {
        if (null === $found) {
            return [
                RuleErrorBuilder::message(sprintf(
                    'Class %s is #[TenantAware] but has no column mapped to tenant_id. '
                    .'Add a non-nullable string column named tenant_id (e.g. VARCHAR(63)).',
                    $className,
                ))
                    ->identifier('tenancy.tenantIdDrift')
                    ->build(),
            ];
        }

        if (true === ($found['nullable'] ?? false)) {
            return [
                RuleErrorBuilder::message(sprintf(
                    'Class %s is #[TenantAware] but its tenant_id column is nullable. '
                    .'The tenant_id column must be non-nullable to prevent cross-tenant data leaks.',
                    $className,
                ))
                    ->identifier('tenancy.tenantIdDrift')
                    ->build(),
            ];
        }

        $type = $found['type'] ?? null;
        if (null !== $type && !in_array(strtolower($type), self::STRING_TYPES, true)) {
            return [
                RuleErrorBuilder::message(sprintf(
                    'Class %s is #[TenantAware] but its tenant_id column maps to non-string type "%s". '
                    .'The TenantAwareFilter compares tenant_id as a quoted string slug; accepted types: string, ascii_string, guid, uuid.',
                    $className,
                    $type,
                ))
                    ->identifier('tenancy.tenantIdDrift')
                    ->build(),
            ];
        }

        return [];
    }
}
