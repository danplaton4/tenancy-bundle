<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Health;

/**
 * Per-tenant aggregate health report.
 *
 * Holds the tenant's slug, the worst-of aggregate {@see HealthStatus}, and the
 * ordered list of per-component probe results. The aggregate status is derived
 * from the results using strict worst-of semantics (D-05):
 * any Fail → Fail; else any Warn → Warn; else Pass; empty list → Pass.
 *
 * Use the named static constructors instead of calling the constructor directly.
 *
 * @see TenantHealthChecker  The service that builds this report via checkOne()
 * @see BootstrapperHealthResult  The per-component result type in $results
 */
final readonly class TenantHealthReport
{
    /**
     * @param BootstrapperHealthResult[] $results Ordered list of per-component probe results
     */
    public function __construct(
        /** Slug of the tenant this report covers. */
        public string $slug,
        /** Aggregate worst-of status across all component probes. */
        public HealthStatus $status,
        /** Per-component probe results. */
        public array $results,
    ) {
    }

    /**
     * Creates a report by aggregating an ordered list of component results.
     *
     * Applies strict worst-of aggregation (D-05):
     * - Any Fail → aggregate is Fail
     * - Else any Warn → aggregate is Warn
     * - Else → aggregate is Pass
     * - Empty list → Pass (no probes means nothing to fail)
     *
     * @param BootstrapperHealthResult[] $results
     */
    public static function fromResults(string $slug, array $results): self
    {
        return new self($slug, self::worstOf($results), $results);
    }

    /**
     * Creates a failing report from an unexpected exception during the probe run.
     *
     * Wraps the exception in a single {@see BootstrapperHealthResult::fromException()} entry
     * so the caller (TenantHealthChecker) can return a structured failure instead of
     * propagating the exception.
     */
    public static function fromException(string $slug, \Throwable $e): self
    {
        $results = [BootstrapperHealthResult::fromException(self::class, $e)];

        return new self($slug, HealthStatus::Fail, $results);
    }

    /**
     * Derives the worst-of status across a list of component results.
     *
     * Strict worst-of (D-05): any Fail wins; else any Warn wins; else Pass.
     * Empty list → Pass (no probes = healthy by convention).
     *
     * @param BootstrapperHealthResult[] $results
     */
    private static function worstOf(array $results): HealthStatus
    {
        $hasWarn = false;

        foreach ($results as $result) {
            if (HealthStatus::Fail === $result->status) {
                return HealthStatus::Fail;
            }

            if (HealthStatus::Warn === $result->status) {
                $hasWarn = true;
            }
        }

        return $hasWarn ? HealthStatus::Warn : HealthStatus::Pass;
    }
}
