<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Command\Install;

/**
 * Internal result of the AST-detection phase of {@see BundlesPhpInstaller}.
 *
 * Not part of the public API surface — callers consume {@see InstallResult} instead.
 * Exposed only within {@see BundlesPhpInstaller} for clean separation of detect/write.
 *
 * @internal
 */
final readonly class DetectionResult
{
    /**
     * @param 'standard'|'non_standard'|'missing' $status
     * @param list<string>                        $registeredFqcns FQCNs found as `::class` keys in the bundles array (canonical, namespace-separated)
     * @param int|null                            $endPos          Byte offset of the closing `]` of the top-level array (for string-template insertion). null when status !== 'standard'.
     * @param string|null                         $reason          human-readable rationale when status === 'non_standard' (mirrored into the refusal message)
     */
    public function __construct(
        public string $status,
        public array $registeredFqcns,
        public ?int $endPos,
        public ?string $reason,
    ) {
    }

    /**
     * @param list<string> $registeredFqcns
     */
    public static function standard(array $registeredFqcns, int $endPos): self
    {
        return new self('standard', $registeredFqcns, $endPos, null);
    }

    public static function nonStandard(string $reason): self
    {
        return new self('non_standard', [], null, $reason);
    }

    public static function missing(): self
    {
        return new self('missing', [], null, 'config/bundles.php does not exist');
    }
}
