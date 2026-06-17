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

        // WR-02: skip non-instantiable bases that legitimately defer tenant_id to concrete subclasses.
        // A #[TenantAware] #[ORM\MappedSuperclass] base (e.g. this bundle's own AbstractTenant split)
        // or an abstract class is NOT required to declare tenant_id itself — only concrete, instantiable
        // entities must. Firing on the base would train consumers to suppress the rule (false positive).
        // Placed after hasTenantAwareInHierarchy() and before the path branch so it applies to both
        // the metadata and reflection paths.
        $nativeReflection = $classReflection->getNativeReflection();
        if ($nativeReflection->isAbstract()
            || [] !== $nativeReflection->getAttributes(\Doctrine\ORM\Mapping\MappedSuperclass::class)
        ) {
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
     * ORM 3.x: ClassMetadata::$fieldMappings is array<string, FieldMapping> where FieldMapping
     * is a final class implementing ArrayAccess (NOT a plain array). The prior (array) cast +
     * is_array() guard skipped every entry, producing a false "no tenant_id" on every valid
     * entity (CR-01). Fixed via property_exists guard + object-shape @var narrowing +
     * ArrayAccess offset accessor, which also works for ORM 2.x plain-array entries (IN-02).
     *
     * @param object $metadata Doctrine ClassMetadata instance (typed as object to avoid hard import)
     *
     * @return list<\PHPStan\Rules\IdentifierRuleError>
     */
    private function checkViaMetadata(object $metadata, string $className): array
    {
        // Guard: if fieldMappings is not accessible, degrade to silence (not a false positive).
        // An unreadable metadata shape must NOT emit "no tenant_id" — the annotation may exist
        // via a path we cannot inspect (e.g. future Doctrine versions changing the property name).
        if (!property_exists($metadata, 'fieldMappings')) {
            return [];
        }

        // Narrow via object-shape @var so PHPStan 2.1.x accepts ->fieldMappings read at level 9.
        // Each entry is a FieldMapping object on ORM 3.x (implements ArrayAccess) or a plain array
        // on ORM 2.x — both are covered by the ArrayAccess instanceof branch below.
        /** @var object{fieldMappings: iterable<object>} $meta */
        $meta = $metadata;

        // fieldMappings is keyed by property name; column name is in 'columnName' property.
        $found = null;
        foreach ($meta->fieldMappings as $fm) {
            // ORM 3.x: FieldMapping objects implement \ArrayAccess but public property access is the
            // ORM-4.0-safe read path (ArrayAccess::offsetGet() fires E_USER_DEPRECATED on ORM 3.x).
            // ORM 2.x: plain array entries do NOT implement \ArrayAccess — they fall to the is_array()
            // branch below and are NOT matched by this instanceof check.
            if ($fm instanceof \ArrayAccess) {
                /** @var \ArrayAccess<array-key, mixed>&object{columnName: string, nullable: bool|null, type: string} $fm */
                $colName = $fm->columnName;
                if ('tenant_id' === $colName) {
                    $nullableRaw = $fm->nullable;
                    $typeRaw = $fm->type;
                    $found = [
                        'nullable' => (bool) ($nullableRaw ?? false),
                        'type' => is_string($typeRaw) ? $typeRaw : null,
                    ];
                    break;
                }
            } elseif (is_array($fm)) {
                // ORM 2.x fallback: plain array entry
                $colName = $fm['columnName'] ?? $fm['column'] ?? null;
                if ('tenant_id' === $colName) {
                    $found = [
                        'nullable' => (bool) ($fm['nullable'] ?? false),
                        'type' => isset($fm['type']) && is_string($fm['type']) ? $fm['type'] : null,
                    ];
                    break;
                }
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
                        // WR-04: also fall back to positional indices when named keys are absent.
                        // ORM\Column constructor: type at positional index 1, nullable at index 6.
                        // Mirrors the existing name resolution: $args['name'] ?? $args[0] ?? null.
                        $nullableRaw = $args['nullable'] ?? $args[6] ?? false;
                        $typeRaw = $args['type'] ?? $args[1] ?? null;
                        $found = [
                            'nullable' => (bool) $nullableRaw,
                            'type' => is_string($typeRaw) ? $typeRaw : null,
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
