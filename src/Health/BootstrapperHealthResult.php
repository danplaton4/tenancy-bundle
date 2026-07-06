<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Health;

/**
 * Per-component probe result value object.
 *
 * Immutable result returned by {@see HealthCheckBootstrapperInterface::check()}.
 * Use the named static constructors instead of calling the constructor directly.
 *
 * @see HealthCheckBootstrapperInterface
 * @see TenantHealthReport
 */
final readonly class BootstrapperHealthResult
{
    public function __construct(
        /** Fully-qualified class name of the bootstrapper that produced this result. */
        public string $componentClass,
        /** Health status of this component. */
        public HealthStatus $status,
        /** Optional human-readable detail message; may contain sanitized error text. */
        public ?string $output = null,
        /** The underlying throwable, if the probe caught an exception. */
        public ?\Throwable $exception = null,
    ) {
    }

    /**
     * Creates a passing result for the given component class.
     */
    public static function pass(string $componentClass): self
    {
        return new self($componentClass, HealthStatus::Pass);
    }

    /**
     * Creates a failing result with a descriptive output message.
     *
     * @param string          $componentClass FQCN of the failing bootstrapper
     * @param string          $output         Human-readable failure reason (credentials MUST be redacted by caller)
     * @param \Throwable|null $e              The underlying exception, if available
     */
    public static function fail(string $componentClass, string $output, ?\Throwable $e = null): self
    {
        return new self($componentClass, HealthStatus::Fail, $output, $e);
    }

    /**
     * Creates a failing result directly from a caught exception.
     *
     * The exception message becomes the `output` field. Callers are responsible
     * for running the result through {@see HealthResponseSanitizer}
     * before including it in any HTTP or CLI response body.
     */
    public static function fromException(string $componentClass, \Throwable $e): self
    {
        return new self($componentClass, HealthStatus::Fail, $e->getMessage(), $e);
    }
}
