<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Health;

use Tenancy\Bundle\Mailer\DsnSanitizer;

/**
 * Scrubs DSN credentials from health response strings and data structures.
 *
 * Reuses {@see DsnSanitizer::REDACTION_REGEX} as the single source of truth for
 * the credential pattern (WR-07 tightening applied there covers all
 * `scheme://user:password@host` shapes including mysql://, postgres://, redis://,
 * smtp://, smtps://, etc.).
 *
 * Downstream response builders (HTTP controller, CLI command) MUST run every
 * output string through this sanitizer before including it in any response body
 * (T-33-04, Anti-pattern H-A4).
 *
 * @see DsnSanitizer::REDACTION_REGEX  Single source of truth for the redaction pattern
 * @see BootstrapperHealthResult::output  The field most likely to carry a DSN credential
 */
final class HealthResponseSanitizer
{
    /**
     * Redacts any `scheme://user:password@host` credential from a single string.
     *
     * Delegates directly to {@see DsnSanitizer::REDACTION_REGEX} — does NOT
     * duplicate or re-derive the pattern.
     */
    public function sanitize(string $message): string
    {
        return preg_replace(
            DsnSanitizer::REDACTION_REGEX,
            DsnSanitizer::REPLACEMENT,
            $message,
        ) ?? $message;
    }

    /**
     * Recursively sanitizes all string leaf values in an array.
     *
     * Non-string values (int, float, bool, null, nested arrays) are left
     * untouched. Array keys are not sanitized.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public function sanitizeArray(array $data): array
    {
        array_walk_recursive($data, function (mixed &$value): void {
            if (\is_string($value)) {
                $value = $this->sanitize($value);
            }
        });

        return $data;
    }
}
