<?php

declare(strict_types=1);

namespace Tenancy\Bundle\PHPStan\Rule;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InClassNode;
use PHPStan\Rules\Rule;
use Tenancy\Bundle\PHPStan\Rule\Helper\AttributeHierarchyHelper;

/**
 * Rule 3: fires when a #[TenantAware] entity's tenant_id column is missing,
 * nullable, or not a string type.
 *
 * This is a skeleton — processNode() logic is implemented in Plan 02.
 *
 * @implements Rule<InClassNode>
 */
final class TenantIdDriftRule implements Rule
{
    public function __construct(
        private readonly AttributeHierarchyHelper $helper,
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
        // TODO(Plan 02): Rule 3 logic
        return [];
    }
}
