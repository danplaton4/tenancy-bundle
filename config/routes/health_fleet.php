<?php

declare(strict_types=1);

/**
 * Fleet endpoint route definition — SEPARATE from live/ready (D-02).
 *
 * Import this file INDEPENDENTLY when you want the aggregate fleet dashboard.
 * It is intentionally separate from config/routes/health.php because the fleet
 * endpoint enumerates tenant slugs and has a different exposure profile than the
 * per-probe liveness/readiness endpoints. Operators can mount the probes on the
 * LB network and independently firewall or decline the dashboard.
 *
 *   # config/routes/tenancy_health_fleet.yaml
 *   tenancy_health_fleet:
 *     resource: '@TenancyBundle/config/routes/health_fleet.php'
 *     prefix: /_tenancy/health
 *
 * Route registered:
 *   tenancy_health_fleet — GET /  (fleet dashboard; bounded by limit/offset; always HTTP 200; HEALTH-06)
 *
 * This route is NOT a k8s probe target. A failing tenant does NOT 503 the whole
 * response; the endpoint returns an aggregate with per-tenant statuses (D-08).
 *
 * @see TenantHealthController::fleet()  Fleet dashboard action
 * @see config/routes/health.php         Live + ready probes (separate import)
 */

use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Tenancy\Bundle\Controller\TenantHealthController;

return static function (RoutingConfigurator $routes): void {
    // Fleet aggregate dashboard — GET /
    // Always HTTP 200; bounded by ?limit (clamped to fleet_max_limit) and ?offset (D-08, HEALTH-06).
    $routes->add('tenancy_health_fleet', '/')
        ->controller([TenantHealthController::class, 'fleet'])
        ->methods(['GET']);
};
