<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Tenancy\Bundle\Exception\TenantNotFoundException;
use Tenancy\Bundle\Health\HealthResponseSanitizer;
use Tenancy\Bundle\Health\HealthStatus;
use Tenancy\Bundle\Health\TenantHealthCheckerInterface;
use Tenancy\Bundle\Health\TenantHealthReport;
use Tenancy\Bundle\Provider\TenantProviderInterface;

/**
 * HTTP controller for tenant health-check endpoints.
 *
 * Delivers three actions following IETF application/health+json:
 *
 *  - live()  — Liveness probe (HEALTH-01, D-07). Pure process check; zero I/O.
 *    k8s convention: a failed liveness probe kills the pod — so it MUST only
 *    fail when the PHP process is genuinely dead, not because a tenant DB is down.
 *
 *  - ready() — Readiness probe per tenant (HEALTH-02, D-05, D-06).
 *    HTTP 200 (pass/warn) or 503 (fail); HTTP 404 for unknown slug (D-06).
 *
 *  - fleet() — Aggregate dashboard (HEALTH-06, D-08). Always HTTP 200.
 *    Bounded via clamped limit/offset; NOT a k8s probe target.
 *
 * Every response body is sanitized through HealthResponseSanitizer before
 * serialization — no raw DSNs or credentials ever reach the wire (T-33-04).
 *
 * This class does NOT extend AbstractController — it has no Twig/serializer
 * dependency and returns plain JsonResponse directly. Routes are defined in
 * config/routes/health.php and config/routes/health_fleet.php (D-01, D-02).
 *
 * @see TenantHealthCheckerInterface::checkOne()  The probe delegate (never boot())
 * @see HealthResponseSanitizer::sanitizeArray()  Credential scrubber applied to all bodies
 */
final class TenantHealthController
{
    /** Content-Type for all health responses per IETF application/health+json spec. */
    private const CONTENT_TYPE = 'application/health+json';

    public function __construct(
        private readonly TenantHealthCheckerInterface $checker,
        private readonly ?TenantProviderInterface $provider,
        private readonly HealthResponseSanitizer $sanitizer,
        /** Default page size for fleet pagination (D-08). */
        private readonly int $fleetDefaultLimit,
        /** Hard cap on fleet page size — prevents thundering-herd exhaustion (Pitfall 6). */
        private readonly int $fleetMaxLimit,
    ) {
    }

    /**
     * GET /_tenancy/health/live — Liveness probe (HEALTH-01).
     *
     * Returns HTTP 200 {"status":"ok"} as soon as the PHP process executes this
     * method. Zero dependency I/O, zero tenant iteration (D-07, Anti-pattern H-A3).
     *
     * A k8s liveness failure kills the pod: this action must be uncoupled from any
     * external dependency state. DB outages, tenant unavailability, and bootstrapper
     * failures MUST NOT affect this response.
     */
    public function live(): JsonResponse
    {
        return new JsonResponse(
            ['status' => 'ok'],
            200,
            ['Content-Type' => self::CONTENT_TYPE],
        );
    }

    /**
     * GET /_tenancy/health/ready/{slug} — Per-tenant readiness probe (HEALTH-02).
     *
     * HTTP status mapping (D-05):
     *  - pass/warn → 200
     *  - fail      → 503
     *  - unknown slug (TenantNotFoundException) → 404 (D-06)
     *
     * All bodies are sanitized before serialization (T-33-04).
     * The controller delegates entirely to TenantHealthCheckerInterface::checkOne()
     * and never touches TenantContext directly (Anti-pattern H-A5/H-A6).
     */
    public function ready(string $slug): JsonResponse
    {
        if (null === $this->provider) {
            // No-Doctrine lane: provider not wired; return a safe 503.
            $body = $this->sanitizer->sanitizeArray([
                'status' => HealthStatus::Fail->value,
                'output' => 'No tenant provider configured.',
            ]);

            return new JsonResponse($body, 503, ['Content-Type' => self::CONTENT_TYPE]);
        }

        try {
            $tenant = $this->provider->findBySlug($slug);
        } catch (TenantNotFoundException) {
            $body = $this->sanitizer->sanitizeArray([
                'status' => HealthStatus::Fail->value,
                'output' => sprintf("Tenant '%s' not found", $slug),
            ]);

            return new JsonResponse($body, 404, ['Content-Type' => self::CONTENT_TYPE]);
        }

        $report = $this->checker->checkOne($tenant);
        $body = $this->buildReadyBody($report);
        $body = $this->sanitizer->sanitizeArray($body);
        $httpStatus = $this->mapStatusToHttpCode($report->status);

        return new JsonResponse($body, $httpStatus, ['Content-Type' => self::CONTENT_TYPE]);
    }

    /**
     * GET /_tenancy/health — Bounded fleet aggregate dashboard (HEALTH-06).
     *
     * NOT a k8s probe target — always returns HTTP 200 regardless of tenant failures (D-08).
     * A failing tenant does NOT 503 the whole page; the aggregate is informational.
     *
     * Pagination: reads ?limit (clamped to [1, fleet_max_limit]) and ?offset (min 0).
     * Each page probes at most `limit` tenants sequentially (never in-process parallel — D-08).
     *
     * Response shape:
     *   {total, offset, limit, summary:{pass,warn,fail}, tenants:[{slug,status,output?}]}
     */
    public function fleet(Request $request): JsonResponse
    {
        $rawLimit = (int) $request->query->get('limit', (string) $this->fleetDefaultLimit);
        $limit = max(1, min($rawLimit, $this->fleetMaxLimit));

        $rawOffset = (int) $request->query->get('offset', '0');
        $offset = max(0, $rawOffset);

        $allTenants = null !== $this->provider ? $this->provider->findAll() : [];
        $total = \count($allTenants);

        $page = \array_slice($allTenants, $offset, $limit);

        $summary = ['pass' => 0, 'warn' => 0, 'fail' => 0];
        $tenants = [];

        foreach ($page as $tenant) {
            $report = $this->checker->checkOne($tenant);
            $statusValue = $report->status->value;

            ++$summary[$statusValue];

            $tenantEntry = ['slug' => $report->slug, 'status' => $statusValue];
            if (HealthStatus::Pass !== $report->status) {
                // Include output for non-pass statuses to help operators identify issues.
                $worstOutput = $this->extractWorstOutput($report);
                if (null !== $worstOutput) {
                    $tenantEntry['output'] = $worstOutput;
                }
            }

            $tenants[] = $tenantEntry;
        }

        $body = $this->sanitizer->sanitizeArray([
            'total' => $total,
            'offset' => $offset,
            'limit' => $limit,
            'summary' => $summary,
            'tenants' => $tenants,
        ]);

        // Fleet is always HTTP 200 — it is a dashboard aggregate, not a probe target (D-08).
        return new JsonResponse($body, 200, ['Content-Type' => self::CONTENT_TYPE]);
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    /**
     * Builds the IETF application/health+json body for a readiness response.
     *
     * Response shape (Pattern 5):
     *   {status, checks:{tenancy:db:{slug}:[{componentId, componentType, status, output?, time}]}}
     *
     * @return array<string, mixed>
     */
    private function buildReadyBody(TenantHealthReport $report): array
    {
        $checks = [];

        foreach ($report->results as $result) {
            $checkKey = sprintf('tenancy:db:%s', $report->slug);

            $entry = [
                'componentId' => $result->componentClass,
                'componentType' => 'datastore',
                'status' => $result->status->value,
                'time' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            ];

            if (null !== $result->output) {
                $entry['output'] = $result->output;
            }

            $checks[$checkKey][] = $entry;
        }

        $body = [
            'status' => $report->status->value,
            'checks' => $checks,
        ];

        // Include a top-level output when the aggregate is failing (informational summary).
        if (HealthStatus::Fail === $report->status) {
            $body['output'] = $this->extractWorstOutput($report);
        }

        return $body;
    }

    /**
     * Maps a HealthStatus to the IETF-mandated HTTP status code (D-05).
     *
     * Pass/Warn → 200 (tenant is serving; warn is degraded but operational)
     * Fail      → 503 (tenant is non-operational; LB should stop routing to it)
     */
    private function mapStatusToHttpCode(HealthStatus $status): int
    {
        return match ($status) {
            HealthStatus::Pass, HealthStatus::Warn => 200,
            HealthStatus::Fail => 503,
        };
    }

    /**
     * Extracts the output message from the worst (Fail-priority) result in a report.
     * Used for top-level output in failing responses and fleet tenant entries.
     */
    private function extractWorstOutput(TenantHealthReport $report): ?string
    {
        foreach ($report->results as $result) {
            if (HealthStatus::Fail === $result->status && null !== $result->output) {
                return $result->output;
            }
        }

        foreach ($report->results as $result) {
            if (null !== $result->output) {
                return $result->output;
            }
        }

        return null;
    }
}
