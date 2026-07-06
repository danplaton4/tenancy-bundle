<?php

declare(strict_types=1);

/**
 * Liveness + readiness route definitions for the tenancy health-check surface.
 *
 * Import this file in the consuming application with a prefix to enable the
 * liveness and readiness probes (D-01 — route-import IS the HTTP opt-in):
 *
 *   # config/routes/tenancy_health.yaml
 *   tenancy_health:
 *     resource: '@TenancyBundle/config/routes/health.php'
 *     prefix: /_tenancy/health
 *
 * Routes registered:
 *   tenancy_health_live  — GET /live  (liveness, HEALTH-01, D-07; zero tenant I/O)
 *   tenancy_health_ready — GET /ready/{slug}  (readiness per-tenant, HEALTH-02)
 *
 * The fleet endpoint ships in a SEPARATE route file (config/routes/health_fleet.php)
 * because it enumerates/samples the tenant roster — a different exposure profile
 * that operators may wish to firewall independently (D-02).
 *
 * @see TenantHealthController::live()   Liveness action
 * @see TenantHealthController::ready()  Readiness action
 * @see config/routes/health_fleet.php   Fleet dashboard (separate import)
 */

use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Tenancy\Bundle\Controller\TenantHealthController;

return static function (RoutingConfigurator $routes): void {
    // Liveness probe — GET /live
    // Pure process check; zero DB I/O; k8s-safe (D-07, HEALTH-01).
    $routes->add('tenancy_health_live', '/live')
        ->controller([TenantHealthController::class, 'live'])
        ->methods(['GET']);

    // Readiness probe — GET /ready/{slug}
    // Per-tenant DB connectivity probe; returns 200 (pass/warn) or 503 (fail); 404 on unknown slug (D-05, D-06, HEALTH-02).
    $routes->add('tenancy_health_ready', '/ready/{slug}')
        ->controller([TenantHealthController::class, 'ready'])
        ->methods(['GET'])
        ->requirements(['slug' => '[a-z0-9\-]+']);
};
