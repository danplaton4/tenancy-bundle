<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Support;

use Psr\Log\AbstractLogger;

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
     * @param array<mixed> $context
     */
    public function log($level, string|\Stringable $message, array $context = []): void
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
            static fn (array $r): bool => 'warning' === $r['level'],
        ));
    }
}
