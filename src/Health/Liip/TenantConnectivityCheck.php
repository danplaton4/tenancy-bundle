<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Health\Liip;

use Laminas\Diagnostics\Check\CheckInterface;
use Laminas\Diagnostics\Result\Failure;
use Laminas\Diagnostics\Result\ResultInterface;
use Laminas\Diagnostics\Result\Success;
use Laminas\Diagnostics\Result\Warning;
use Tenancy\Bundle\Health\HealthResponseSanitizer;
use Tenancy\Bundle\Health\HealthStatus;
use Tenancy\Bundle\Health\TenantHealthCheckerInterface;
use Tenancy\Bundle\Provider\TenantProviderInterface;

/**
 * Liip monitor-bundle check adapter for per-tenant DB connectivity.
 *
 * Implements {@see CheckInterface} so the liip runner auto-discovers this check
 * via the `liip_monitor.check` service tag registered by {@see HealthCheckIntegrationPass}.
 *
 * Delegates the probe lifecycle entirely to {@see TenantHealthCheckerInterface::checkOne()},
 * which enforces the set→probe→clear-in-finally invariant. This class MUST NOT set or
 * clear TenantContext directly (Anti-pattern H-A7 — the checker's finally owns the clear).
 *
 * Result mapping (worst-of across all tenants):
 *   - All pass → Laminas\Diagnostics\Result\Success
 *   - Any warn, none fail → Laminas\Diagnostics\Result\Warning
 *   - Any fail → Laminas\Diagnostics\Result\Failure
 *
 * The result message is built from sanitized report data only — raw DSNs are never
 * included (T-33-04, HEALTH-04).
 *
 * @see HealthCheckIntegrationPass  The compiler pass that registers this service (class_exists-guarded)
 * @see TenantHealthCheckerInterface::checkOne()  The probe delegate (never boot())
 */
final class TenantConnectivityCheck implements CheckInterface
{
    public function __construct(
        private readonly TenantHealthCheckerInterface $checker,
        private readonly TenantProviderInterface $provider,
        private readonly HealthResponseSanitizer $sanitizer,
    ) {
    }

    /**
     * Perform the health check across all tenants.
     *
     * Iterates all tenants via the provider, delegates each probe to the checker,
     * and derives the worst-of result. Returns Failure when any tenant fails,
     * Warning when any tenant warns (none fail), Success when all pass.
     *
     * All output strings are sanitized before inclusion in the result message
     * (T-33-04 — no raw DSN credentials in liip check results).
     */
    public function check(): ResultInterface
    {
        $tenants = $this->provider->findAll();

        if ([] === $tenants) {
            return new Success('No tenants registered.');
        }

        $failSlugs = [];
        $warnSlugs = [];
        $hasAnyFail = false;
        $hasAnyWarn = false;

        foreach ($tenants as $tenant) {
            // checkOne() owns set→probe→clear-in-finally — MUST NOT touch TenantContext here.
            $report = $this->checker->checkOne($tenant);

            if (HealthStatus::Fail === $report->status) {
                $hasAnyFail = true;
                // Collect sanitized output for the failure message — never a raw DSN.
                $output = $this->extractSanitizedOutput($report);
                $failSlugs[] = null !== $output
                    ? sprintf('%s (%s)', $report->slug, $output)
                    : $report->slug;
            } elseif (HealthStatus::Warn === $report->status) {
                $hasAnyWarn = true;
                $output = $this->extractSanitizedOutput($report);
                $warnSlugs[] = null !== $output
                    ? sprintf('%s (%s)', $report->slug, $output)
                    : $report->slug;
            }
        }

        if ($hasAnyFail) {
            return new Failure(
                sprintf(
                    'Tenant connectivity failed for: %s',
                    implode(', ', $failSlugs),
                ),
            );
        }

        if ($hasAnyWarn) {
            return new Warning(
                sprintf(
                    'Tenant connectivity degraded for: %s',
                    implode(', ', $warnSlugs),
                ),
            );
        }

        return new Success(sprintf('All %d tenant(s) connected successfully.', \count($tenants)));
    }

    /**
     * Human-readable label for the liip monitor check list.
     */
    public function getLabel(): string
    {
        return 'Tenancy: per-tenant DB connectivity';
    }

    /**
     * Extracts and sanitizes the worst output message from a report.
     *
     * Returns null when no output is available so callers can skip the output field.
     * All strings pass through HealthResponseSanitizer — no raw DSNs ever reach the
     * liip result message (T-33-04).
     */
    private function extractSanitizedOutput(\Tenancy\Bundle\Health\TenantHealthReport $report): ?string
    {
        // Prefer Fail-level output first, then any output.
        foreach ($report->results as $result) {
            if (HealthStatus::Fail === $result->status && null !== $result->output) {
                return $this->sanitizer->sanitize($result->output);
            }
        }

        foreach ($report->results as $result) {
            if (null !== $result->output) {
                return $this->sanitizer->sanitize($result->output);
            }
        }

        return null;
    }
}
