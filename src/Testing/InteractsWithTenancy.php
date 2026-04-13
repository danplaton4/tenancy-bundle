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
     *   2. Build a synthetic Tenant entity whose connectionConfig points to :memory: SQLite.
     *   3. Activate the synthetic tenant in TenantContext.
     *   4. Run all registered bootstrappers via BootstrapperChain::boot().
     *      DatabaseSwitchBootstrapper::boot() will call switchTenant() with the :memory: config,
     *      leaving the connection configured for :memory: after the boot completes.
     *   5. Reset the tenant EntityManager and create the schema on the (now :memory:) connection.
     *
     * The schema must be created AFTER chain->boot() because DatabaseSwitchBootstrapper::boot()
     * calls TenantConnection::switchTenant() which calls close(). Creating a new :memory: SQLite
     * connection after close() yields a fresh empty database — so schema creation must happen last.
     *
     * The 'path' => null key is set explicitly in the in-memory config so that array_merge() in
     * TenantConnection::switchTenant() nulls out any pre-existing 'path' key from the original
     * placeholder connection params. DBAL's SQLite driver checks isset($params['path']) first, so
     * a non-null path would override the 'memory' flag.
     */
    protected function initializeTenant(string $slug): void
    {
        $container = static::getContainer();

        // Step 1: Clear any prior tenant context and bootstrapper chain state.
        /** @var TenantContext $tenantContext */
        $tenantContext = $container->get('tenancy.context');
        $tenantContext->clear();

        /** @var BootstrapperChain $chain */
        $chain = $container->get('tenancy.bootstrapper_chain');
        $chain->clear();

        // Step 2: Build a synthetic Tenant entity with in-memory SQLite connection config.
        // DatabaseSwitchBootstrapper::boot() will call switchTenant() with these params,
        // configuring the tenant DBAL connection for :memory: SQLite.
        $tenant = new Tenant($slug, $slug);
        $tenant->setConnectionConfig(['driver' => 'pdo_sqlite', 'memory' => true, 'path' => null]);

        // Step 3: Activate the synthetic tenant in context so bootstrappers and assertions work.
        $tenantContext->setTenant($tenant);

        // Step 4: Run all registered bootstrappers.
        // This causes DatabaseSwitchBootstrapper::boot() to call switchTenant() with the :memory:
        // config, then close() the prior connection. After this, the DBAL connection is configured
        // for a fresh :memory: SQLite (the actual PDO connection is opened on next query).
        $chain->boot($tenant);

        // Step 5: Reset tenant EM + create schema on the :memory: connection.
        // resetManager() is required after switchTenant() to get an EntityManager whose UnitOfWork
        // is tied to the new connection (not the stale one from before the boot).
        $registry = $container->get('doctrine');
        $em = $registry->resetManager('tenant');
        $schemaTool = new SchemaTool($em);
        $schemaTool->createSchema($em->getMetadataFactory()->getAllMetadata());
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
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    protected function getTenantService(string $class): object
    {
        return static::getContainer()->get($class);
    }
}
