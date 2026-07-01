<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Unit\EventListener;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Tenancy\Bundle\Context\TenantContext;
use Tenancy\Bundle\EventListener\TenantMaintenanceModeListener;
use Tenancy\Bundle\TenantInterface;
use Twig\Environment;

/**
 * Unit tests for TenantMaintenanceModeListener.
 *
 * Covers MAINT-03 (503 + Retry-After + Cache-Control: no-store),
 * MAINT-04 (null-tenant + sub-request bypass),
 * MAINT-06 (IP/route/path allow-list bypass),
 * MAINT-07 (Twig template + hardcoded-HTML fallback).
 */
final class TenantMaintenanceModeListenerTest extends TestCase
{
    private TenantContext $tenantContext;

    /** @var TenantInterface&MockObject */
    private TenantInterface $tenant;

    /** @var HttpKernelInterface&MockObject */
    private HttpKernelInterface $kernel;

    protected function setUp(): void
    {
        $this->tenantContext = new TenantContext();
        $this->tenant = $this->createMock(TenantInterface::class);
        $this->kernel = $this->createMock(HttpKernelInterface::class);
    }

    // -------------------------------------------------------------------------
    // Helper
    // -------------------------------------------------------------------------

    private function makeListener(
        int $retryAfter = 3600,
        ?string $template = null,
        array $allowIps = [],
        array $allowRoutes = [],
        array $allowPaths = [],
        ?Environment $twig = null,
    ): TenantMaintenanceModeListener {
        return new TenantMaintenanceModeListener(
            $this->tenantContext,
            $retryAfter,
            $template,
            $allowIps,
            $allowRoutes,
            $allowPaths,
            $twig,
        );
    }

    private function makeMainEvent(Request $request): RequestEvent
    {
        return new RequestEvent($this->kernel, $request, HttpKernelInterface::MAIN_REQUEST);
    }

    private function makeSubEvent(Request $request): RequestEvent
    {
        return new RequestEvent($this->kernel, $request, HttpKernelInterface::SUB_REQUEST);
    }

    // -------------------------------------------------------------------------
    // PRIORITY constant
    // -------------------------------------------------------------------------

    public function testPriorityConstantIs16(): void
    {
        $this->assertSame(16, TenantMaintenanceModeListener::PRIORITY);
    }

    // -------------------------------------------------------------------------
    // MAINT-04: Sub-request bypass
    // -------------------------------------------------------------------------

    public function testSubRequestIsIgnored(): void
    {
        $this->tenant->method('isInMaintenance')->willReturn(true);
        $this->tenantContext->setTenant($this->tenant);

        $listener = $this->makeListener();
        $request = Request::create('/');
        $event = $this->makeSubEvent($request);

        $listener->onKernelRequest($event);

        $this->assertFalse($event->hasResponse(), 'Sub-requests must never get a 503 response');
    }

    // -------------------------------------------------------------------------
    // MAINT-04: Null-tenant (landlord/public) bypass
    // -------------------------------------------------------------------------

    public function testNullTenantRequestIsIgnored(): void
    {
        // TenantContext is empty (no setTenant call)
        $listener = $this->makeListener();
        $request = Request::create('/health');
        $event = $this->makeMainEvent($request);

        $listener->onKernelRequest($event);

        $this->assertFalse($event->hasResponse(), 'Null-tenant (public/landlord) routes must not be 503d');
    }

    // -------------------------------------------------------------------------
    // Normal tenant (NOT in maintenance) — no response set
    // -------------------------------------------------------------------------

    public function testTenantNotInMaintenanceIsPassedThrough(): void
    {
        $this->tenant->method('isInMaintenance')->willReturn(false);
        $this->tenantContext->setTenant($this->tenant);

        $listener = $this->makeListener();
        $request = Request::create('/dashboard');
        $event = $this->makeMainEvent($request);

        $listener->onKernelRequest($event);

        $this->assertFalse($event->hasResponse());
    }

    // -------------------------------------------------------------------------
    // MAINT-03: 503 + Retry-After + Cache-Control: no-store (HTML branch)
    // -------------------------------------------------------------------------

    public function testMaintenanceTenantReturns503WithRequiredHeaders(): void
    {
        $this->tenant->method('isInMaintenance')->willReturn(true);
        $this->tenantContext->setTenant($this->tenant);

        $listener = $this->makeListener(retryAfter: 7200);
        $request = Request::create('/dashboard');
        $request->headers->set('Accept', 'text/html,application/xhtml+xml');
        $event = $this->makeMainEvent($request);

        $listener->onKernelRequest($event);

        $this->assertTrue($event->hasResponse(), 'Maintenance tenant must get a 503 response');
        $response = $event->getResponse();
        $this->assertNotNull($response);
        $this->assertSame(503, $response->getStatusCode());
        $this->assertSame('7200', $response->headers->get('Retry-After'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    // -------------------------------------------------------------------------
    // MAINT-03: JSON content-negotiation
    // -------------------------------------------------------------------------

    public function testJsonRequestReturnsJsonBody(): void
    {
        $this->tenant->method('isInMaintenance')->willReturn(true);
        $this->tenantContext->setTenant($this->tenant);

        $listener = $this->makeListener(retryAfter: 3600);
        $request = Request::create('/api/resource');
        $request->headers->set('Accept', 'application/json');
        $event = $this->makeMainEvent($request);

        $listener->onKernelRequest($event);

        $this->assertTrue($event->hasResponse());
        $response = $event->getResponse();
        $this->assertNotNull($response);
        $this->assertSame(503, $response->getStatusCode());
        $this->assertSame('3600', $response->headers->get('Retry-After'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('application/json', (string) $response->headers->get('Content-Type'));

        /** @var array{status: string, retryAfter: int} $decoded */
        $decoded = json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        $this->assertSame('maintenance', $decoded['status']);
        $this->assertSame(3600, $decoded['retryAfter']);
    }

    public function testHtmlRequestReturnsHtmlBody(): void
    {
        $this->tenant->method('isInMaintenance')->willReturn(true);
        $this->tenantContext->setTenant($this->tenant);

        $listener = $this->makeListener(retryAfter: 3600);
        $request = Request::create('/dashboard');
        $request->headers->set('Accept', 'text/html');
        $event = $this->makeMainEvent($request);

        $listener->onKernelRequest($event);

        $this->assertTrue($event->hasResponse());
        $response = $event->getResponse();
        $this->assertNotNull($response);
        $this->assertSame(503, $response->getStatusCode());
        $this->assertSame('3600', $response->headers->get('Retry-After'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('text/html', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString('<html', (string) $response->getContent());
    }

    public function testJsonAndHtmlBranchesHaveIdenticalStatusAndHeaders(): void
    {
        $this->tenant->method('isInMaintenance')->willReturn(true);
        $this->tenantContext->setTenant($this->tenant);

        $retryAfter = 1800;

        // JSON branch
        $listenerA = $this->makeListener(retryAfter: $retryAfter);
        $jsonRequest = Request::create('/api/data');
        $jsonRequest->headers->set('Accept', 'application/json');
        $jsonEvent = $this->makeMainEvent($jsonRequest);
        $listenerA->onKernelRequest($jsonEvent);
        $jsonResponse = $jsonEvent->getResponse();
        $this->assertNotNull($jsonResponse);

        // HTML branch — fresh context
        $contextB = new TenantContext();
        $tenantB = $this->createMock(TenantInterface::class);
        $tenantB->method('isInMaintenance')->willReturn(true);
        $contextB->setTenant($tenantB);

        $listenerB = new TenantMaintenanceModeListener(
            $contextB,
            $retryAfter,
            null,
            [],
            [],
            [],
            null,
        );
        $htmlRequest = Request::create('/dashboard');
        $htmlRequest->headers->set('Accept', 'text/html');
        $htmlEvent = $this->makeMainEvent($htmlRequest);
        $listenerB->onKernelRequest($htmlEvent);
        $htmlResponse = $htmlEvent->getResponse();
        $this->assertNotNull($htmlResponse);

        $this->assertSame($jsonResponse->getStatusCode(), $htmlResponse->getStatusCode());
        $this->assertSame($jsonResponse->headers->get('Retry-After'), $htmlResponse->headers->get('Retry-After'));
        $this->assertStringContainsString('no-store', (string) $htmlResponse->headers->get('Cache-Control'));
    }

    // -------------------------------------------------------------------------
    // MAINT-06: IP allow-list bypass
    // -------------------------------------------------------------------------

    public function testAllowedIpBypassesMaintenance(): void
    {
        $this->tenant->method('isInMaintenance')->willReturn(true);
        $this->tenantContext->setTenant($this->tenant);

        $listener = $this->makeListener(allowIps: ['203.0.113.10']);
        $request = Request::create('/dashboard', 'GET', [], [], [], ['REMOTE_ADDR' => '203.0.113.10']);
        $event = $this->makeMainEvent($request);

        $listener->onKernelRequest($event);

        $this->assertFalse($event->hasResponse(), 'IP in allow_ips must bypass maintenance');
    }

    public function testAllowedCidrRangeBypassesMaintenance(): void
    {
        $this->tenant->method('isInMaintenance')->willReturn(true);
        $this->tenantContext->setTenant($this->tenant);

        // 10.0.0.0/8 covers 10.x.x.x
        $listener = $this->makeListener(allowIps: ['10.0.0.0/8']);
        $request = Request::create('/dashboard', 'GET', [], [], [], ['REMOTE_ADDR' => '10.42.0.1']);
        $event = $this->makeMainEvent($request);

        $listener->onKernelRequest($event);

        $this->assertFalse($event->hasResponse(), 'CIDR-matched IP must bypass maintenance');
    }

    public function testNonAllowedIpDoesNotBypassMaintenance(): void
    {
        $this->tenant->method('isInMaintenance')->willReturn(true);
        $this->tenantContext->setTenant($this->tenant);

        $listener = $this->makeListener(allowIps: ['10.0.0.0/8']);
        $request = Request::create('/dashboard', 'GET', [], [], [], ['REMOTE_ADDR' => '192.168.1.1']);
        $event = $this->makeMainEvent($request);

        $listener->onKernelRequest($event);

        $this->assertTrue($event->hasResponse(), 'Non-allowed IP must still get 503');
    }

    // -------------------------------------------------------------------------
    // MAINT-06: Route allow-list bypass
    // -------------------------------------------------------------------------

    public function testAllowedRouteBypassesMaintenance(): void
    {
        $this->tenant->method('isInMaintenance')->willReturn(true);
        $this->tenantContext->setTenant($this->tenant);

        $listener = $this->makeListener(allowRoutes: ['app_health_check']);
        $request = Request::create('/health');
        $request->attributes->set('_route', 'app_health_check');
        $event = $this->makeMainEvent($request);

        $listener->onKernelRequest($event);

        $this->assertFalse($event->hasResponse(), 'Route in allow_routes must bypass maintenance');
    }

    public function testNonAllowedRouteDoesNotBypassMaintenance(): void
    {
        $this->tenant->method('isInMaintenance')->willReturn(true);
        $this->tenantContext->setTenant($this->tenant);

        $listener = $this->makeListener(allowRoutes: ['app_health_check']);
        $request = Request::create('/dashboard');
        $request->attributes->set('_route', 'app_dashboard');
        $event = $this->makeMainEvent($request);

        $listener->onKernelRequest($event);

        $this->assertTrue($event->hasResponse(), 'Non-allowed route must still get 503');
    }

    // -------------------------------------------------------------------------
    // MAINT-06: Path prefix allow-list bypass
    // -------------------------------------------------------------------------

    public function testAllowedPathPrefixBypassesMaintenance(): void
    {
        $this->tenant->method('isInMaintenance')->willReturn(true);
        $this->tenantContext->setTenant($this->tenant);

        $listener = $this->makeListener(allowPaths: ['/_tenancy/health']);
        $request = Request::create('/_tenancy/health/db');
        $event = $this->makeMainEvent($request);

        $listener->onKernelRequest($event);

        $this->assertFalse($event->hasResponse(), 'Path with allowed prefix must bypass maintenance');
    }

    public function testNonAllowedPathDoesNotBypassMaintenance(): void
    {
        $this->tenant->method('isInMaintenance')->willReturn(true);
        $this->tenantContext->setTenant($this->tenant);

        $listener = $this->makeListener(allowPaths: ['/_tenancy/health']);
        $request = Request::create('/dashboard');
        $event = $this->makeMainEvent($request);

        $listener->onKernelRequest($event);

        $this->assertTrue($event->hasResponse(), 'Path not matching allow_paths must still get 503');
    }

    // -------------------------------------------------------------------------
    // MAINT-07: Custom Twig template renders successfully
    // -------------------------------------------------------------------------

    public function testTwigTemplateRendersWhenConfigured(): void
    {
        $this->tenant->method('isInMaintenance')->willReturn(true);
        $this->tenantContext->setTenant($this->tenant);

        $renderedHtml = '<html><body>Custom 503</body></html>';
        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('maintenance/503.html.twig', ['tenant' => $this->tenant, 'retryAfter' => 3600])
            ->willReturn($renderedHtml);

        $listener = $this->makeListener(template: 'maintenance/503.html.twig', twig: $twig);
        $request = Request::create('/dashboard');
        $request->headers->set('Accept', 'text/html');
        $event = $this->makeMainEvent($request);

        $listener->onKernelRequest($event);

        $this->assertTrue($event->hasResponse());
        $response = $event->getResponse();
        $this->assertNotNull($response);
        $this->assertSame(503, $response->getStatusCode());
        $this->assertSame($renderedHtml, $response->getContent());
    }

    // -------------------------------------------------------------------------
    // MAINT-07: Twig render throws → hardcoded HTML fallback (D-02)
    // -------------------------------------------------------------------------

    public function testTwigRenderExceptionFallsBackToHardcodedHtml(): void
    {
        $this->tenant->method('isInMaintenance')->willReturn(true);
        $this->tenantContext->setTenant($this->tenant);

        $twig = $this->createMock(Environment::class);
        $twig->method('render')->willThrowException(new \RuntimeException('Template not found'));

        $listener = $this->makeListener(template: 'maintenance/missing.html.twig', twig: $twig);
        $request = Request::create('/dashboard');
        $request->headers->set('Accept', 'text/html');
        $event = $this->makeMainEvent($request);

        $listener->onKernelRequest($event);

        $this->assertTrue($event->hasResponse());
        $response = $event->getResponse();
        $this->assertNotNull($response);
        $this->assertSame(503, $response->getStatusCode());
        // Must NOT contain the Twig exception message — must contain the hardcoded fallback
        $this->assertStringContainsString('<html', (string) $response->getContent());
        $this->assertStringNotContainsString('Template not found', (string) $response->getContent());
        // Headers still set despite fallback
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertNotNull($response->headers->get('Retry-After'));
    }

    // -------------------------------------------------------------------------
    // Cross-tenant isolation (MAINT-05)
    // -------------------------------------------------------------------------

    public function testCrossTenantIsolationTenantADoesNotAffectTenantB(): void
    {
        // Context A: tenant in maintenance → 503
        $tenantA = $this->createMock(TenantInterface::class);
        $tenantA->method('isInMaintenance')->willReturn(true);
        $contextA = new TenantContext();
        $contextA->setTenant($tenantA);

        $listenerA = new TenantMaintenanceModeListener(
            $contextA,
            3600,
            null,
            [],
            [],
            [],
            null,
        );
        $requestA = Request::create('/dashboard');
        $eventA = $this->makeMainEvent($requestA);
        $listenerA->onKernelRequest($eventA);
        $this->assertTrue($eventA->hasResponse(), 'Tenant A (in maintenance) must receive 503');
        $this->assertSame(503, $eventA->getResponse()?->getStatusCode());

        // Context B: different tenant NOT in maintenance → no response
        $tenantB = $this->createMock(TenantInterface::class);
        $tenantB->method('isInMaintenance')->willReturn(false);
        $contextB = new TenantContext();
        $contextB->setTenant($tenantB);

        $listenerB = new TenantMaintenanceModeListener(
            $contextB,
            3600,
            null,
            [],
            [],
            [],
            null,
        );
        $requestB = Request::create('/dashboard');
        $eventB = $this->makeMainEvent($requestB);
        $listenerB->onKernelRequest($eventB);
        $this->assertFalse($eventB->hasResponse(), 'Tenant B (not in maintenance) must NOT receive 503');
    }

    // -------------------------------------------------------------------------
    // Cache-Control: no-store present on all 503 responses (Pitfall 5)
    // -------------------------------------------------------------------------

    public function testPragmaAndCacheControlHeadersAreSetOnJsonResponse(): void
    {
        $this->tenant->method('isInMaintenance')->willReturn(true);
        $this->tenantContext->setTenant($this->tenant);

        $listener = $this->makeListener();
        $request = Request::create('/api/test');
        $request->headers->set('Accept', 'application/json');
        $event = $this->makeMainEvent($request);

        $listener->onKernelRequest($event);

        $response = $event->getResponse();
        $this->assertNotNull($response);
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertSame('no-cache', $response->headers->get('Pragma'));
    }
}
