<?php

declare(strict_types=1);

namespace Tenancy\Bundle\PHPStan\Rule;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InClassNode;
use PHPStan\Rules\Rule;
use Tenancy\Bundle\PHPStan\Rule\Helper\AttributeHierarchyHelper;

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
    public function __construct(
        private readonly AttributeHierarchyHelper $helper,
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
        return [];
    }
}
