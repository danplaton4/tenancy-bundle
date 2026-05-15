<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Integration\Resolver\Support;

use Psr\Log\AbstractLogger;

/**
 * In-memory PSR-3 logger for integration tests — records every log() call so the
 * mismatch-warning behavior locked by CONTEXT.md D-11 can be asserted end-to-end.
 */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{level: mixed, message: string|\Stringable, context: array<mixed>}> */
    public array $records = [];

    /**
     * @param array<mixed> $context
     */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => $level, 'message' => $message, 'context' => $context];
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
