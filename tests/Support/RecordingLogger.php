<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Support;

use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;

/**
 * In-memory PSR-3 logger used by both Unit and Integration suites to assert
 * mismatch-warning emissions locked by CONTEXT.md D-11 (Phase 17).
 *
 * Centralized here to prevent the lockstep-drift hazard the duplicated Unit /
 * Integration copies created in iteration 1 (see Phase 17 REVIEW-FIX iter 2,
 * finding WR-01).
 */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{level: mixed, message: string|\Stringable, context: array<mixed>}> */
    private array $records = [];

    /**
     * @param string|\Stringable $message
     * @param array<mixed>       $context
     *
     * NOTE: `$message` is intentionally untyped at the PHP signature level.
     * On prefer-lowest CI the matrix resolves psr/log to ^1.x, whose
     * LoggerInterface::log() declares `$message` with no type at all. PHP
     * LSP forbids a child from declaring a stricter parameter type than its
     * parent, so `string|\Stringable` here is a fatal-on-autoload under
     * PSR-3 v1. The runtime contract is preserved via PHPDoc above.
     */
    public function log($level, $message, array $context = []): void
    {
        $this->records[] = ['level' => $level, 'message' => $message, 'context' => $context];
    }

    public function reset(): void
    {
        $this->records = [];
    }

    /** @return list<array{level: mixed, message: string|\Stringable, context: array<mixed>}> */
    public function records(): array
    {
        return $this->records;
    }

    /** @return list<array{level: mixed, message: string|\Stringable, context: array<mixed>}> */
    public function warnings(): array
    {
        return array_values(array_filter(
            $this->records,
            // Compare against the PSR-3 constant so the filter stays correct
            // even if callers switch to `LogLevel::WARNING` directly or if the
            // PSR-3 spec ever changes the underlying value. `AbstractLogger::warning()`
            // dispatches to `log(LogLevel::WARNING, ...)`, which is what
            // `OriginHeaderResolver::resolve()` records here.
            static fn (array $r): bool => LogLevel::WARNING === $r['level'],
        ));
    }

    /**
     * Records logged at PSR-3 ERROR level.
     *
     * Mirrors warnings(); used by the shared-entity sync integration suite to
     * assert the per-tenant fan-out failure log (D-07). `AbstractLogger::error()`
     * dispatches to `log(LogLevel::ERROR, ...)`.
     *
     * @return list<array{level: mixed, message: string|\Stringable, context: array<mixed>}>
     */
    public function errors(): array
    {
        return array_values(array_filter(
            $this->records,
            static fn (array $r): bool => LogLevel::ERROR === $r['level'],
        ));
    }
}
