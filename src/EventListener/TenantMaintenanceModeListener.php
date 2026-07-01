<?php

declare(strict_types=1);

namespace Tenancy\Bundle\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Tenancy\Bundle\Context\TenantContext;
use Tenancy\Bundle\TenantInterface;
use Twig\Environment;

/**
 * kernel.request priority-16 listener.
 *
 * Fires AFTER TenantContextOrchestrator (priority 20), which means
 * TenantContext already holds the resolved tenant (or is empty for
 * landlord/public routes).
 *
 * Returns HTTP 503 for tenants whose isInMaintenance() flag is true.
 * Sets Retry-After and Cache-Control: no-store on every 503 response.
 * Content-negotiates JSON vs HTML body.
 *
 * NEVER throws an exception — response is always set via setResponse()
 * so Retry-After + Cache-Control headers travel to the client regardless
 * of any exception-handling middleware installed at lower priority.
 *
 * @see \Tenancy\Bundle\DependencyInjection\Compiler\MaintenanceModeContractPass
 *   enforces PRIORITY < TenantContextOrchestrator::PRIORITY at compile time.
 */
#[AsEventListener(event: KernelEvents::REQUEST, method: 'onKernelRequest', priority: self::PRIORITY)]
final class TenantMaintenanceModeListener
{
    /** Priority 16: after TenantContextOrchestrator (20), before Security firewall (8). */
    public const PRIORITY = 16;

    /**
     * @param array<string> $allowIps    IP addresses or CIDR ranges that bypass maintenance
     * @param array<string> $allowRoutes exact _route names that bypass maintenance
     * @param array<string> $allowPaths  path info prefixes that bypass maintenance
     */
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly int $retryAfter,
        private readonly ?string $template,
        private readonly array $allowIps,
        private readonly array $allowRoutes,
        private readonly array $allowPaths,
        private readonly ?Environment $twig,
    ) {
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        // (1) Sub-requests never receive a maintenance response.
        if (!$event->isMainRequest()) {
            return;
        }

        // (2) Null-tenant (landlord/public/health routes): TenantContext is empty → bypass.
        //     This ensures operators are never locked out of landlord-side routes (MAINT-04).
        if (!$this->tenantContext->hasTenant()) {
            return;
        }

        $request = $event->getRequest();

        // (3) Allow-list OR-check (MAINT-06 / D-06 / D-07).
        //     Any single match → bypass maintenance gate entirely.
        if ($this->isAllowListed($request)) {
            return;
        }

        // (4) Maintenance gate.
        //     getTenant() is non-null here — hasTenant() asserted in check (2) above.
        $tenant = $this->tenantContext->getTenant();
        if (null === $tenant || !$tenant->isInMaintenance()) {
            return;
        }

        // (5) Build 503 response — content-negotiate body, always set headers.
        $event->setResponse($this->buildMaintenanceResponse($request, $tenant));
    }

    private function isAllowListed(Request $request): bool
    {
        // IP / CIDR — uses Symfony IpUtils for IPv4/IPv6 and CIDR support (D-07).
        if ([] !== $this->allowIps
            && IpUtils::checkIp((string) $request->getClientIp(), $this->allowIps)) {
            return true;
        }

        // Exact _route match.
        if ([] !== $this->allowRoutes
            && \in_array($request->attributes->get('_route'), $this->allowRoutes, true)) {
            return true;
        }

        // Path-info prefix match (str_starts_with).
        $pathInfo = $request->getPathInfo();
        foreach ($this->allowPaths as $prefix) {
            if (str_starts_with($pathInfo, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function buildMaintenanceResponse(Request $request, TenantInterface $tenant): Response
    {
        $headers = [
            'Retry-After' => (string) $this->retryAfter,
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
            'Pragma' => 'no-cache',
        ];

        // JSON branch: Accept: application/json or XMLHttpRequest.
        if ($this->isJsonRequest($request)) {
            $body = json_encode(
                ['status' => 'maintenance', 'retryAfter' => $this->retryAfter],
                \JSON_THROW_ON_ERROR,
            );
            $headers['Content-Type'] = 'application/json';

            return new Response($body, 503, $headers);
        }

        // HTML branch: Twig (when configured) with hardcoded-HTML fallback (D-01 / D-02).
        $body = $this->renderHtml($tenant);
        $headers['Content-Type'] = 'text/html; charset=UTF-8';

        return new Response($body, 503, $headers);
    }

    private function isJsonRequest(Request $request): bool
    {
        // Honour explicit application/json in Accept header.
        if (\in_array('application/json', $request->getAcceptableContentTypes(), true)) {
            return true;
        }

        // Also honour XMLHttpRequest convention (jQuery / legacy AJAX).
        return $request->isXmlHttpRequest();
    }

    private function renderHtml(TenantInterface $tenant): string
    {
        if (null !== $this->template && null !== $this->twig) {
            try {
                return $this->twig->render(
                    $this->template,
                    ['tenant' => $tenant, 'retryAfter' => $this->retryAfter],
                );
            } catch (\Throwable) {
                // Twig template error must NEVER 500 the site (D-01 / D-02).
                // Fall through to the hardcoded HTML below.
            }
        }

        return $this->defaultHtml();
    }

    /**
     * Hardcoded, dependency-free 503 HTML body (D-01).
     *
     * No Twig, no translation, no external assets — deliberately minimal
     * so it can never fail, even when all services are degraded.
     */
    private function defaultHtml(): string
    {
        return sprintf(
            '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Maintenance</title>
    <style>
        body { font-family: sans-serif; text-align: center; padding: 4rem; background: #f8f8f8; color: #333; }
        h1 { font-size: 2rem; margin-bottom: 1rem; }
        p  { color: #666; }
    </style>
</head>
<body>
    <h1>Under Maintenance</h1>
    <p>This service is temporarily unavailable. Please try again in %d seconds.</p>
</body>
</html>',
            $this->retryAfter,
        );
    }
}
