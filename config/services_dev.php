<?php

declare(strict_types=1);

/*
 * Dev-only DI registration for the Tenancy Profiler tab.
 *
 * This file is imported by TenancyBundle::loadExtension() ONLY when
 * $builder->getParameter('kernel.debug') === true. Production containers
 * (debug=false) never see these services — verified by
 * tests/Integration/Profiler/TenantDataCollectorCompileOutTest.php.
 *
 * Phase 19 — Profiler Tab — requirement DX-02.
 */

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Tenancy\Bundle\Profiler\TenantDataCollector;
use Tenancy\Bundle\Profiler\TenantProfilerStash;

use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return function (ContainerConfigurator $container): void {
    $services = $container->services();

    // Stash — captures resolver FQCN, bootstrapper FQCN list, and tenancy exceptions from event time.
    // Zero constructor args. Autoconfigure picks up:
    //   - 4 #[AsEventListener] attributes → kernel event listener registrations
    //   - implements ResetInterface → `kernel.reset` tag (between-request cleanup in long-running runtimes)
    $services->set(TenantProfilerStash::class)
        ->autoconfigure(true)
        ->public();

    // Data collector — reads stash + TenantContext + driver/landlord params on kernel.response.
    // Autoconfigure adds the `data_collector` tag because TenantDataCollector extends AbstractDataCollector
    // (which implements DataCollectorInterface), BUT autoconfigure cannot supply the tag's `id` and
    // `template` attributes — those must be explicit. The explicit ->tag(...) call below adds them
    // alongside the autoconfigured tag (Symfony merges tag attributes when both autoconfigure and
    // explicit tag are present).
    $services->set(TenantDataCollector::class)
        ->autoconfigure(true)
        ->public()
        ->args([
            service(TenantProfilerStash::class),
            service('tenancy.context'),
            param('tenancy.driver'),
            param('tenancy.landlord_connection'),
        ])
        ->tag('data_collector', [
            'id' => 'tenancy',
            'template' => '@Tenancy/Collector/tenant.html.twig',
        ]);
};
