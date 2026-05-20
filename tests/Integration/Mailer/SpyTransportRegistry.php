<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Integration\Mailer;

/**
 * Test-only static collector of every DSN that a SpyTransport was instantiated
 * with during a test run.
 *
 * Provides a single global observation point so AsyncCanaryTest can verify the
 * complete send → serialize → deserialize → handler → Transports::send chain
 * used the expected tenant DSN — without needing to fish the SpyTransport
 * instance out of the LRU cache (which the handler may have flushed by the
 * time the test inspects state).
 *
 * The async canary's load-bearing assertion is:
 *
 *   assertNotContains('null://null', SpyTransportRegistry::dsnsUsed())
 *
 * Tests MUST reset() the registry in setUp() so observations don't leak across
 * tests.
 */
final class SpyTransportRegistry
{
    /** @var list<string> */
    private static array $dsnsUsed = [];

    public static function record(string $dsn): void
    {
        self::$dsnsUsed[] = $dsn;
    }

    /** @return list<string> */
    public static function dsnsUsed(): array
    {
        return self::$dsnsUsed;
    }

    public static function reset(): void
    {
        self::$dsnsUsed = [];
    }
}
