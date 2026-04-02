<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Testing;

use Doctrine\ORM\Tools\SchemaTool;
use Tenancy\Bundle\Bootstrapper\BootstrapperChain;
use Tenancy\Bundle\Context\TenantContext;
use Tenancy\Bundle\DBAL\TenantConnection;
use Tenancy\Bundle\Entity\Tenant;

/**
 * PHPUnit trait for KernelTestCase subclasses that need tenant-aware integration tests.
 *
 * Usage:
 *   class MyTest extends KernelTestCase
 *   {
 *       use InteractsWithTenancy;
 *
 *       protected static function getKernelClass(): string
 *       {
 *           return TenancyTestKernel::class;
 *       }
 *
 *       public function testSomething(): void
 *       {
 *           $this->initializeTenant('acme');
 *           $this->assertTenantActive('acme');
 *           // ...
 *       }
 *   }
 *
 * Requirements:
 *   - The test kernel must have `tenancy.database.enabled: true` (database-per-tenant mode).
 *   - The tenant DBAL connection must use TenantConnection as its `wrapper_class`.
 *   - The recommended kernel is TenancyTestKernel from the Testing\Support namespace.
 *
 * Each call to initializeTenant() creates a fresh :memory: SQLite schema for the tenant
 * EM. tearDown() automatically clears the tenant context after each test method.
 */
trait InteractsWithTenancy
{
    /**
     * Initialize a synthetic tenant context for the given slug.
     *
     * Sequence:
     *   1. Clear any prior tenant context and bootstrapper chain state.
     *   2. Swap the tenant DBAL connection to a fresh :memory: SQLite database.
     *   3. Reset the tenant EntityManager and create the schema.
     *   4. Activate a synthetic Tenant entity in TenantContext.
     *   5. Run all registered bootstrappers via BootstrapperChain::boot().
     */
    protected function initializeTenant(string $slug): void
    {
        $container = static::getContainer();

        // Step 1: Clear any prior tenant context
        /** @var TenantContext $tenantContext */
        $tenantContext = $container->get('tenancy.context');
        $tenantContext->clear();

        // Also clear bootstrapper chain from prior tenant
        /** @var BootstrapperChain $chain */
        $chain = $container->get('tenancy.bootstrapper_chain');
        $chain->clear();

        // Step 2: Swap tenant connection to fresh :memory: SQLite
        /** @var TenantConnection $conn */
        $conn = $container->get('doctrine.dbal.tenant_connection');
        $conn->switchTenant(['driver' => 'pdo_sqlite', 'memory' => true]);

        // Step 3: Reset tenant EM + create schema
        $registry = $container->get('doctrine');
        $em = $registry->resetManager('tenant');
        $schemaTool = new SchemaTool($em);
        $schemaTool->createSchema($em->getMetadataFactory()->getAllMetadata());

        // Step 4: Activate synthetic tenant in context
        $tenant = new Tenant($slug, $slug);
        $tenantContext->setTenant($tenant);

        // Step 5: Run all registered bootstrappers
        $chain->boot($tenant);
    }

    /**
     * Clear the active tenant context and run bootstrapper chain teardown.
     *
     * Guards on hasTenant() to avoid running bootstrapper clear when no tenant
     * was initialized (e.g., test that never called initializeTenant()).
     */
    protected function clearTenant(): void
    {
        $container = static::getContainer();

        /** @var TenantContext $tenantContext */
        $tenantContext = $container->get('tenancy.context');

        if ($tenantContext->hasTenant()) {
            /** @var BootstrapperChain $chain */
            $chain = $container->get('tenancy.bootstrapper_chain');
            $chain->clear();
        }

        $tenantContext->clear();
    }

    /**
     * Override PHPUnit tearDown to clear the tenant context after each test method.
     *
     * Calls clearTenant() before parent::tearDown() so the container is still
     * available. PHPUnit always runs tearDown even when setUp/test throws.
     */
    protected function tearDown(): void
    {
        $this->clearTenant();
        parent::tearDown();
    }

    /**
     * Assert that a tenant with the given slug is currently active in TenantContext.
     */
    protected function assertTenantActive(string $expectedSlug): void
    {
        /** @var TenantContext $tenantContext */
        $tenantContext = static::getContainer()->get('tenancy.context');

        $this->assertTrue($tenantContext->hasTenant(), 'Expected an active tenant but TenantContext is empty');
        $this->assertSame(
            $expectedSlug,
            $tenantContext->getTenant()->getSlug(),
            sprintf('Expected tenant "%s" but got "%s"', $expectedSlug, $tenantContext->getTenant()->getSlug()),
        );
    }

    /**
     * Assert that no tenant is currently active in TenantContext.
     */
    protected function assertNoTenant(): void
    {
        /** @var TenantContext $tenantContext */
        $tenantContext = static::getContainer()->get('tenancy.context');

        $this->assertFalse($tenantContext->hasTenant(), 'Expected no active tenant but TenantContext has one');
    }

    /**
     * Retrieve a service from the test container by class name.
     *
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    protected function getTenantService(string $class): object
    {
        return static::getContainer()->get($class);
    }
}
