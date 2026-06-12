<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Integration\SharedEntity\Support;

use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;

/**
 * Recording PSR-3 logger for integration tests.
 *
 * Captures every log call with its level, message, and context so that tests
 * can assert structured log output from services that accept a LoggerInterface.
 *
 * Usage: inject via InjectRecordingLoggerPass into the test container, then
 * retrieve from the container and call getRecords() / getErrorRecords() in assertions.
 */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    private array $records = [];

    public function log(mixed $level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = [
            'level' => (string) $level,
            'message' => (string) $message,
            'context' => $context,
        ];
    }

    /**
     * @return list<array{level: string, message: string, context: array<string, mixed>}>
     */
    public function getRecords(): array
    {
        return $this->records;
    }

    /**
     * @return list<array{level: string, message: string, context: array<string, mixed>}>
     */
    public function getErrorRecords(): array
    {
        return array_values(array_filter(
            $this->records,
            static fn (array $r): bool => LogLevel::ERROR === $r['level']
        ));
    }

    public function reset(): void
    {
        $this->records = [];
    }
}
