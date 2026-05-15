<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Unit\Resolver\Support;

use Psr\Log\AbstractLogger;

/**
 * In-memory PSR-3 logger that records every log() call.
 * Used by OriginHeaderResolverTest to assert the mismatch warning is emitted with the
 * exact structured context shape locked by CONTEXT.md D-11.
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
