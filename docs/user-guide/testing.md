# Testing

The `InteractsWithTenancy` trait provides everything needed to write PHPUnit integration tests
that require tenant context. It handles tenant setup, schema creation, assertion helpers, and
automatic cleanup — with `:memory:` SQLite for fast, isolated tests.

## Overview

`InteractsWithTenancy` is a PHPUnit trait for `KernelTestCase` subclasses. It provides:

- `initializeTenant(string $slug)` — boot a clean tenant context with `:memory:` SQLite
- `clearTenant()` — tear down tenant context and bootstrapper chain
- `assertTenantActive(string $slug)` — assert a specific tenant is active
- `assertNoTenant()` — assert no tenant is active
- `getTenantService(string $class)` — retrieve a service from the test container
- Automatic `tearDown()` that calls `clearTenant()` after every test

!!! info "In-Memory SQLite Strategy"
    Each `initializeTenant()` call creates a fresh `:memory:` SQLite database for the tenant EM.
    No external database, no test fixtures to clean up, and sub-millisecond schema creation. Tests
    run in complete isolation — each test method gets a pristine, empty database.

## Setup

### Test Kernel

You need a test kernel configured for database-per-tenant mode. Use the bundled
`TenancyTestKernel` or your own application kernel:

```php
<?php

declare(strict_types=1);

namespace App\Tests;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Tenancy\Bundle\Testing\InteractsWithTenancy;

class InvoiceRepositoryTest extends KernelTestCase
{
    use InteractsWithTenancy;

    public function testFindsOnlyTenantInvoices(): void
    {
        $this->initializeTenant('acme');
        $this->assertTenantActive('acme');

        // The tenant EM is switched to an :memory: SQLite DB for 'acme'
        // Schema is created — ready to use
        $em = static::getContainer()->get('doctrine')->getManager('tenant');

        $invoice = new Invoice();
        $invoice->setAmount('99.99');
        $em->persist($invoice);
        $em->flush();

        $invoices = $em->getRepository(Invoice::class)->findAll();
        $this->assertCount(1, $invoices);
        $this->assertSame('99.99', $invoices[0]->getAmount());
    }
}
```

## How `initializeTenant()` Works

The method follows a strict sequence. Order matters — particularly the schema creation step:

```
1. Clear prior context
   └─ TenantContext::clear()
   └─ BootstrapperChain::clear()

2. Build synthetic Tenant
   └─ new Tenant($slug, $slug)
   └─ setConnectionConfig(['driver' => 'pdo_sqlite', 'memory' => true, 'path' => null])

3. Activate in TenantContext
   └─ TenantContext::setTenant($tenant)

4. Run BootstrapperChain::boot()
   └─ DatabaseSwitchBootstrapper::boot() → switchTenant() → close()
   └─ Other bootstrappers (cache namespace, etc.)

5. Reset tenant EM + create schema
   └─ $registry->resetManager('tenant')
   └─ SchemaTool::createSchema(allMetadata)
```

!!! warning "Schema creation must happen AFTER boot()"
    `DatabaseSwitchBootstrapper::boot()` calls `TenantConnection::switchTenant()` which calls
    `close()`. On SQLite `:memory:` databases, `close()` **destroys the database**. Schema
    creation must happen **after** `boot()` completes, not before. The trait enforces this order.

The `'path' => null` key in the connection config is explicit: it ensures `array_merge()` in
`switchTenant()` nulls out any pre-existing `path` from the placeholder connection, because
DBAL checks `isset($params['path'])` before checking the `memory` flag.

## Available Methods

### `initializeTenant(string $slug)`

Sets up a clean tenant context with an `:memory:` SQLite database. Boots all registered
bootstrappers. Safe to call multiple times within a single test — clears prior state first.

```php
$this->initializeTenant('acme');
```

### `clearTenant()`

Clears the active tenant context and runs `BootstrapperChain::clear()`. Resets the DBAL
connection to the placeholder. Guards on `hasTenant()` — safe to call even when no tenant
was initialized.

```php
$this->clearTenant();
```

### `assertTenantActive(string $slug)`

Asserts that `TenantContext` has an active tenant with the given slug. Fails with a descriptive
message if no tenant is active or the slug does not match.

```php
$this->assertTenantActive('acme');
```

### `assertNoTenant()`

Asserts that `TenantContext` has no active tenant. Useful for testing code paths that run outside
tenant context.

```php
$this->assertNoTenant();
```

### `getTenantService(string $class)`

Retrieves a service from the test container. Equivalent to
`static::getContainer()->get($class)` but typed for generics.

```php
$repository = $this->getTenantService(InvoiceRepository::class);
```

## Automatic Teardown

`tearDown()` is overridden by the trait to call `clearTenant()` before `parent::tearDown()`.
PHPUnit always runs `tearDown()` even when the test or `setUp()` throws — tenant context is
always cleaned up.

## Two-Tenant Isolation Test

Testing that data created under one tenant is invisible to another:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Tenant\Invoice;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Tenancy\Bundle\Testing\InteractsWithTenancy;

class TenantIsolationTest extends KernelTestCase
{
    use InteractsWithTenancy;

    public function testTenantsHaveSeparateData(): void
    {
        $doctrine = static::getContainer()->get('doctrine');

        // Boot tenant 'acme' and create an invoice
        $this->initializeTenant('acme');
        $acmeEm = $doctrine->resetManager('tenant');
        $invoice = new Invoice();
        $invoice->setAmount('100.00');
        $acmeEm->persist($invoice);
        $acmeEm->flush();
        $this->clearTenant();

        // Boot tenant 'demo' — completely separate :memory: database
        $this->initializeTenant('demo');
        $demoEm = $doctrine->resetManager('tenant');
        $demoInvoices = $demoEm->getRepository(Invoice::class)->findAll();

        // Demo cannot see acme's invoice
        $this->assertCount(0, $demoInvoices);
        $this->assertTenantActive('demo');
    }
}
```

## See Also

- [Database-per-Tenant](database-per-tenant.md) — how the connection switching works
- [Getting Started](getting-started.md) — basic test setup
- [Contributor Guide: Test Infrastructure](../contributor-guide/test-infrastructure.md)
