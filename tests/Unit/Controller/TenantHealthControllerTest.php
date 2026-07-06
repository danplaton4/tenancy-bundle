<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Unit\Controller;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Tenancy\Bundle\Controller\TenantHealthController;
use Tenancy\Bundle\Exception\TenantInactiveException;
use Tenancy\Bundle\Exception\TenantNotFoundException;
use Tenancy\Bundle\Health\BootstrapperHealthResult;
use Tenancy\Bundle\Health\HealthResponseSanitizer;
use Tenancy\Bundle\Health\HealthStatus;
use Tenancy\Bundle\Health\TenantHealthCheckerInterface;
use Tenancy\Bundle\Health\TenantHealthReport;
use Tenancy\Bundle\Provider\TenantProviderInterface;
use Tenancy\Bundle\TenantInterface;

/**
 * Unit tests for TenantHealthController — live/ready/fleet actions.
 *
 * Tests are grouped into:
 *  - Task 1: live() and ready() behaviors (HEALTH-01, HEALTH-02, D-05, D-06, HEALTH-04)
 *  - Task 2: fleet() behaviors (HEALTH-06, D-08, Pitfall 6)
 *
 * The controller is instantiated directly with mock dependencies — no container boot.
 *
 * @see TenantHealthController
 */
final class TenantHealthControllerTest extends TestCase
{
    private const FLEET_DEFAULT_LIMIT = 50;
    private const FLEET_MAX_LIMIT = 200;
    private const HEALTH_JSON = 'application/health+json';

    /** @var TenantHealthCheckerInterface&MockObject */
    private TenantHealthCheckerInterface $checker;

    /** @var TenantProviderInterface&MockObject */
    private TenantProviderInterface $provider;

    private HealthResponseSanitizer $sanitizer;
    private TenantHealthController $controller;

    protected function setUp(): void
    {
        $this->checker = $this->createMock(TenantHealthCheckerInterface::class);
        $this->provider = $this->createMock(TenantProviderInterface::class);
        $this->sanitizer = new HealthResponseSanitizer();

        $this->controller = new TenantHealthController(
            $this->checker,
            $this->provider,
            $this->sanitizer,
            self::FLEET_DEFAULT_LIMIT,
            self::FLEET_MAX_LIMIT,
        );
    }

    // =========================================================================
    // Task 1: live() — HEALTH-01 / D-07
    // =========================================================================

    /**
     * live() returns HTTP 200 with {"status":"ok"} body.
     * Content-Type must be application/health+json (IETF standard).
     */
    public function testLivenessReturnsHttp200WithOkStatus(): void
    {
        $response = $this->controller->live();

        $this->assertSame(200, $response->getStatusCode());

        $body = json_decode((string) $response->getContent(), true);
        $this->assertIsArray($body);
        $this->assertSame('ok', $body['status']);
    }

    /**
     * live() must set Content-Type: application/health+json (D-07, IETF spec).
     */
    public function testLivenessContentTypeIsHealthJson(): void
    {
        $response = $this->controller->live();

        $this->assertStringContainsString(
            self::HEALTH_JSON,
            (string) $response->headers->get('Content-Type'),
        );
    }

    /**
     * live() must call the checker and provider ZERO times (HEALTH-01, D-07, Anti-pattern H-A3).
     * A DB outage must never make liveness fail — it performs zero dependency I/O.
     */
    public function testLivenessCallsCheckerZeroTimes(): void
    {
        $this->checker->expects($this->never())->method('checkOne');
        $this->provider->expects($this->never())->method('findBySlug');
        $this->provider->expects($this->never())->method('findAll');

        $this->controller->live();
    }

    // =========================================================================
    // Task 1: ready() — HEALTH-02, D-05, D-06
    // =========================================================================

    /**
     * ready() returns HTTP 200 for a healthy (Pass) tenant.
     * Body must contain status 'pass' and a 'checks' key (IETF health+json).
     */
    public function testReadinessReturnsHttp200ForPassingTenant(): void
    {
        $tenant = $this->createTenantMock('acme');
        $result = BootstrapperHealthResult::pass('SomeBootstrapper');
        $report = TenantHealthReport::fromResults('acme', [$result]);

        $this->provider->method('findBySlug')->with('acme')->willReturn($tenant);
        $this->checker->method('checkOne')->with($tenant)->willReturn($report);

        $response = $this->controller->ready('acme');

        $this->assertSame(200, $response->getStatusCode());

        $body = json_decode((string) $response->getContent(), true);
        $this->assertIsArray($body);
        $this->assertSame('pass', $body['status']);
        $this->assertArrayHasKey('checks', $body);
    }

    /**
     * ready() returns HTTP 503 for a failing (Fail) tenant (D-05).
     */
    public function testReadinessReturnsHttp503ForFailingTenant(): void
    {
        $tenant = $this->createTenantMock('acme');
        $result = BootstrapperHealthResult::fail('SomeBootstrapper', 'DB unreachable');
        $report = TenantHealthReport::fromResults('acme', [$result]);

        $this->provider->method('findBySlug')->with('acme')->willReturn($tenant);
        $this->checker->method('checkOne')->with($tenant)->willReturn($report);

        $response = $this->controller->ready('acme');

        $this->assertSame(503, $response->getStatusCode());

        $body = json_decode((string) $response->getContent(), true);
        $this->assertIsArray($body);
        $this->assertSame('fail', $body['status']);
    }

    /**
     * ready() returns HTTP 200 for a Warn tenant — warn is not a hard failure (D-05).
     */
    public function testReadinessReturnsHttp200ForWarnTenant(): void
    {
        $tenant = $this->createTenantMock('acme');
        $result = new BootstrapperHealthResult('SomeBootstrapper', HealthStatus::Warn, 'Slow response');
        $report = TenantHealthReport::fromResults('acme', [$result]);

        $this->provider->method('findBySlug')->with('acme')->willReturn($tenant);
        $this->checker->method('checkOne')->with($tenant)->willReturn($report);

        $response = $this->controller->ready('acme');

        $this->assertSame(200, $response->getStatusCode());

        $body = json_decode((string) $response->getContent(), true);
        $this->assertIsArray($body);
        $this->assertSame('warn', $body['status']);
    }

    /**
     * ready() returns HTTP 404 with application/health+json body when the slug is unknown.
     * Unknown slug means configuration error (D-06), not a tenant health failure.
     */
    public function testReadinessReturnsHttp404ForUnknownSlug(): void
    {
        $this->provider
            ->method('findBySlug')
            ->with('nope')
            ->willThrowException(new TenantNotFoundException('Tenant not found.'));

        $response = $this->controller->ready('nope');

        $this->assertSame(404, $response->getStatusCode());
        $this->assertStringContainsString(
            self::HEALTH_JSON,
            (string) $response->headers->get('Content-Type'),
        );

        $body = json_decode((string) $response->getContent(), true);
        $this->assertIsArray($body);
        $this->assertSame('fail', $body['status']);
        $this->assertStringContainsString('nope', (string) $body['output']);
        $this->assertStringContainsString('not found', strtolower((string) $body['output']));
    }

    /**
     * CR-02: ready() returns a sanitized health+json 503 (NOT a stock 403 error page)
     * when the slug is a known-but-inactive tenant. DoctrineTenantProvider::findBySlug()
     * throws TenantInactiveException for inactive tenants; the controller must map it to
     * the readiness contract, consistent with the CLI command.
     */
    public function testReadinessReturnsHttp503ForInactiveSlug(): void
    {
        $this->provider
            ->method('findBySlug')
            ->with('dormant')
            ->willThrowException(new TenantInactiveException('dormant'));
        $this->checker->expects($this->never())->method('checkOne');

        $response = $this->controller->ready('dormant');

        $this->assertSame(503, $response->getStatusCode());
        $this->assertStringContainsString(
            self::HEALTH_JSON,
            (string) $response->headers->get('Content-Type'),
        );

        $body = json_decode((string) $response->getContent(), true);
        $this->assertIsArray($body);
        $this->assertSame('fail', $body['status']);
        $this->assertStringContainsString('dormant', (string) $body['output']);
        $this->assertStringContainsString('inactive', strtolower((string) $body['output']));
    }

    /**
     * ready() response body must not expose raw DSN credentials — HEALTH-04 / T-33-04.
     * The sanitizer must redact scheme://user:password@host patterns.
     */
    public function testReadinessRedactsDsnInResponseBody(): void
    {
        $tenant = $this->createTenantMock('acme');
        // Output field contains a raw DSN with a secret password
        $result = BootstrapperHealthResult::fail(
            'SomeBootstrapper',
            'mysql://user:s3cr3t@db.host/tenant_acme',
        );
        $report = TenantHealthReport::fromResults('acme', [$result]);

        $this->provider->method('findBySlug')->with('acme')->willReturn($tenant);
        $this->checker->method('checkOne')->with($tenant)->willReturn($report);

        $response = $this->controller->ready('acme');

        $content = (string) $response->getContent();
        $this->assertStringNotContainsString('s3cr3t', $content, 'Raw DSN password must be redacted');
        $this->assertStringContainsString('***', $content, 'Redaction marker *** must appear');
    }

    /**
     * ready() Content-Type is application/health+json on all response variants.
     */
    public function testReadinessContentTypeIsHealthJson(): void
    {
        $tenant = $this->createTenantMock('acme');
        $report = TenantHealthReport::fromResults('acme', []);

        $this->provider->method('findBySlug')->willReturn($tenant);
        $this->checker->method('checkOne')->willReturn($report);

        $response = $this->controller->ready('acme');

        $this->assertStringContainsString(
            self::HEALTH_JSON,
            (string) $response->headers->get('Content-Type'),
        );
    }

    // =========================================================================
    // Task 2: fleet() — HEALTH-06, D-08, Pitfall 6
    // =========================================================================

    /**
     * fleet() returns HTTP 200 even when a tenant's status is fail (D-08).
     * Fleet is a dashboard aggregate, NOT a k8s probe target.
     */
    public function testFleetAlwaysReturnsHttp200(): void
    {
        $tenant = $this->createTenantMock('acme');
        $failResult = BootstrapperHealthResult::fail('SomeBootstrapper', 'DB down');
        $failReport = TenantHealthReport::fromResults('acme', [$failResult]);

        $this->provider->method('findAll')->willReturn([$tenant]);
        $this->checker->method('checkOne')->willReturn($failReport);

        $request = new Request();
        $response = $this->controller->fleet($request);

        $this->assertSame(200, $response->getStatusCode());
    }

    /**
     * WR-01: fleet() MUST still return HTTP 200 when the roster fetch (findAll) throws
     * (e.g. landlord DB down). The always-200 dashboard contract (D-08) requires the
     * error to degrade to a sanitized, empty aggregate rather than propagate as a 500.
     */
    public function testFleetReturnsHttp200WhenFindAllThrows(): void
    {
        $this->provider
            ->method('findAll')
            ->willThrowException(new \RuntimeException('landlord DB unreachable at mysql://root:hunter2@landlord.host/main'));
        $this->checker->expects($this->never())->method('checkOne');

        $request = new Request();
        $response = $this->controller->fleet($request);

        $this->assertSame(200, $response->getStatusCode());

        $content = (string) $response->getContent();
        $this->assertStringNotContainsString('hunter2', $content, 'Roster-failure output must be sanitized');

        $body = json_decode($content, true);
        $this->assertIsArray($body);
        $this->assertSame(0, $body['total']);
        $this->assertSame([], $body['tenants']);
        $this->assertArrayHasKey('output', $body);
    }

    /**
     * fleet() body contains required keys: total, offset, limit, summary, tenants.
     */
    public function testFleetBodyContainsRequiredKeys(): void
    {
        $tenant = $this->createTenantMock('acme');
        $report = TenantHealthReport::fromResults('acme', []);

        $this->provider->method('findAll')->willReturn([$tenant]);
        $this->checker->method('checkOne')->willReturn($report);

        $request = new Request();
        $response = $this->controller->fleet($request);

        $body = json_decode((string) $response->getContent(), true);
        $this->assertIsArray($body);
        $this->assertArrayHasKey('total', $body);
        $this->assertArrayHasKey('offset', $body);
        $this->assertArrayHasKey('limit', $body);
        $this->assertArrayHasKey('summary', $body);
        $this->assertArrayHasKey('tenants', $body);
    }

    /**
     * fleet() summary contains pass/warn/fail keys matching the page tenant statuses.
     */
    public function testFleetSummaryCountsMatchTenantStatuses(): void
    {
        $tenantPass = $this->createTenantMock('pass-co');
        $tenantFail = $this->createTenantMock('fail-co');

        $passReport = TenantHealthReport::fromResults('pass-co', []);
        $failResult = BootstrapperHealthResult::fail('SomeBootstrapper', 'DB down');
        $failReport = TenantHealthReport::fromResults('fail-co', [$failResult]);

        $this->provider->method('findAll')->willReturn([$tenantPass, $tenantFail]);
        $this->checker->method('checkOne')
            ->willReturnMap([
                [$tenantPass, $passReport],
                [$tenantFail, $failReport],
            ]);

        $request = new Request();
        $response = $this->controller->fleet($request);

        $body = json_decode((string) $response->getContent(), true);
        $this->assertIsArray($body);
        $this->assertSame(1, $body['summary']['pass']);
        $this->assertSame(0, $body['summary']['warn']);
        $this->assertSame(1, $body['summary']['fail']);
        $this->assertSame(2, $body['total']);
    }

    /**
     * fleet() with ?limit=500 clamps to fleet_max_limit (200) — Pitfall 6 / D-08.
     * The mocked checker must be called at most fleet_max_limit times.
     */
    public function testFleetClampsLimitToMaxLimit(): void
    {
        // Build 250 tenants; with limit=500 (clamped to 200), only 200 should be probed
        $tenants = [];
        for ($i = 0; $i < 250; ++$i) {
            $tenants[] = $this->createTenantMock('tenant-'.$i);
        }

        $passReport = TenantHealthReport::fromResults('any', []);
        $this->provider->method('findAll')->willReturn($tenants);
        $this->checker->expects($this->exactly(self::FLEET_MAX_LIMIT))->method('checkOne')
            ->willReturn($passReport);

        $request = new Request(['limit' => '500']);
        $response = $this->controller->fleet($request);

        $body = json_decode((string) $response->getContent(), true);
        $this->assertIsArray($body);
        $this->assertSame(self::FLEET_MAX_LIMIT, $body['limit']);
        $this->assertSame(250, $body['total']);
        $this->assertCount(self::FLEET_MAX_LIMIT, $body['tenants']);
    }

    /**
     * fleet() with ?offset=10&limit=5 slices the tenant list correctly.
     */
    public function testFleetPaginationSlicesCorrectly(): void
    {
        $tenants = [];
        for ($i = 0; $i < 20; ++$i) {
            $tenants[] = $this->createTenantMock('tenant-'.$i);
        }

        $passReport = TenantHealthReport::fromResults('any', []);
        $this->provider->method('findAll')->willReturn($tenants);
        $this->checker->expects($this->exactly(5))->method('checkOne')
            ->willReturn($passReport);

        $request = new Request(['offset' => '10', 'limit' => '5']);
        $response = $this->controller->fleet($request);

        $body = json_decode((string) $response->getContent(), true);
        $this->assertIsArray($body);
        $this->assertSame(10, $body['offset']);
        $this->assertSame(5, $body['limit']);
        $this->assertSame(20, $body['total']);
        $this->assertCount(5, $body['tenants']);
    }

    /**
     * fleet() uses the default limit (50) when no query params are supplied.
     */
    public function testFleetUsesDefaultLimitWhenNoQueryParams(): void
    {
        // Build 100 tenants; with default limit=50, only 50 should be probed
        $tenants = [];
        for ($i = 0; $i < 100; ++$i) {
            $tenants[] = $this->createTenantMock('tenant-'.$i);
        }

        $passReport = TenantHealthReport::fromResults('any', []);
        $this->provider->method('findAll')->willReturn($tenants);
        $this->checker->expects($this->exactly(self::FLEET_DEFAULT_LIMIT))->method('checkOne')
            ->willReturn($passReport);

        $request = new Request();
        $response = $this->controller->fleet($request);

        $body = json_decode((string) $response->getContent(), true);
        $this->assertIsArray($body);
        $this->assertSame(self::FLEET_DEFAULT_LIMIT, $body['limit']);
        $this->assertSame(0, $body['offset']);
    }

    /**
     * fleet() body is sanitized — raw DSN credentials must not appear (T-33-04).
     */
    public function testFleetRedactsDsnInResponseBody(): void
    {
        $tenant = $this->createTenantMock('acme');
        $result = BootstrapperHealthResult::fail(
            'SomeBootstrapper',
            'mysql://user:s3cr3t@db.host/tenant_acme',
        );
        $report = TenantHealthReport::fromResults('acme', [$result]);

        $this->provider->method('findAll')->willReturn([$tenant]);
        $this->checker->method('checkOne')->willReturn($report);

        $request = new Request();
        $response = $this->controller->fleet($request);

        $content = (string) $response->getContent();
        $this->assertStringNotContainsString('s3cr3t', $content, 'Raw DSN password must be redacted in fleet response');
        $this->assertStringContainsString('***', $content);
    }

    /**
     * fleet() Content-Type is application/health+json.
     */
    public function testFleetContentTypeIsHealthJson(): void
    {
        $this->provider->method('findAll')->willReturn([]);

        $request = new Request();
        $response = $this->controller->fleet($request);

        $this->assertStringContainsString(
            self::HEALTH_JSON,
            (string) $response->headers->get('Content-Type'),
        );
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function createTenantMock(string $slug): TenantInterface
    {
        $tenant = $this->createMock(TenantInterface::class);
        $tenant->method('getSlug')->willReturn($slug);

        return $tenant;
    }
}
