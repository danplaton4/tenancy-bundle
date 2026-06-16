<?php

declare(strict_types=1);

namespace Tenancy\Bundle\PHPStan\Rule;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use Tenancy\Bundle\PHPStan\Rule\Helper\AttributeHierarchyHelper;

/**
 * Rule 2: fires when a Doctrine query in tenant-EM context targets a #[Shared]
 * entity without an explicit landlord override.
 *
 * Gated by the tenancy.checkSharedEntityLeaks parameter (default true).
 *
 * This is a skeleton — processNode() logic is implemented in Plan 03.
 *
 * @implements Rule<Node\Expr\MethodCall>
 */
final class SharedEntityLeakRule implements Rule
{
    public function __construct(
        private readonly AttributeHierarchyHelper $helper,
        private readonly bool $checkSharedEntityLeaks,
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
        // TODO(Plan 03): Rule 2 logic
        return [];
    }
}
