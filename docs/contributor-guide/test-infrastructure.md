# Test Infrastructure

The bundle has a comprehensive test suite that verifies both unit-level logic and full
Symfony container compilation with real service wiring. Understanding the patterns used
here is important for writing tests that fit the existing structure.

## Test Organization

```
tests/
├── Unit/              # 40+ files — pure unit tests, no kernel, no database
└── Integration/       # 28+ files — full kernel boot, SQLite, real DI container
    ├── Support/        # Shared test kernels and compiler passes
    ├── Messenger/      # Messenger middleware integration tests
    │   └── Support/
    ├── Command/        # CLI command integration tests
    │   └── Support/
    └── Testing/        # InteractsWithTenancy trait tests
        └── Support/
```

The project maintains a **1.7:1 test-to-source file ratio** (68 test files to 40 source
files). When adding a new source class, add proportional tests.

**Unit tests** (`tests/Unit/`):

- Fast, no I/O, no real Symfony kernel
- Mock external dependencies (Doctrine ORM, Messenger)
- One test class per source class

**Integration tests** (`tests/Integration/`):

- Boot a purpose-built test kernel
- Verify real service wiring, compiler pass behavior, and DI container correctness
- Use SQLite for Doctrine-dependent tests (no external database required)

## The 7 Test Kernels

Each integration test scenario uses a dedicated kernel configured for exactly what that
test needs — no more, no less. This avoids the combinatorial complexity of a single
shared kernel and keeps test isolation tight.

| Kernel | Location | Purpose |
|--------|----------|---------|
| `TestKernel` | `tests/Integration/TestKernel.php` | Minimal FrameworkBundle + TenancyBundle. Used for DI wiring tests that do not need Doctrine. Replaces `tenancy.provider` with `NullTenantProvider`. |
| `DoctrineTestKernel` | `tests/Integration/Support/DoctrineTestKernel.php` | Adds DoctrineBundle with two EMs (landlord + tenant), SQLite connections, and `tenancy.database.enabled: true`. Used for database-switch and EntityManager-reset tests. |
| `BootstrapperTestKernel` | `tests/Integration/Support/BootstrapperTestKernel.php` | DoctrineBundle + single EM in `shared_db` mode. Used for DoctrineBootstrapper and cache bootstrapper tests. Exposes services via `MakeBootstrapperServicesPublicPass`. |
| `SharedDbTestKernel` | `tests/Integration/Support/SharedDbTestKernel.php` | DoctrineBundle + single EM + TestApp entity mappings in `shared_db` mode. Used for `TenantAwareFilter` SQL filter tests. Exposes services via `MakeSharedDbServicesPublicPass`. |
| `MessengerTestKernel` | `tests/Integration/Messenger/MessengerTestKernel.php` | FrameworkBundle + TenancyBundle + Messenger bus. No Doctrine. Used for `TenantSendingMiddleware` and `TenantWorkerMiddleware` tests. |
| `CommandTestKernel` | `tests/Integration/Command/Support/CommandTestKernel.php` | FrameworkBundle + TenancyBundle only (no DoctrineBundle). Stubs Doctrine DBAL and migrations services. Used for CLI command DI wiring tests. |
| `TenancyTestKernel` | `tests/Integration/Testing/Support/TenancyTestKernel.php` | Full database-per-tenant kernel for `InteractsWithTenancy` trait tests. Two EMs (landlord + tenant), SQLite, `MakeTenancyTestServicesPublicPass`. |

## Pattern: `setUpBeforeClass` / `tearDownAfterClass`

Integration tests use `setUpBeforeClass` to boot the kernel once per test class and
`tearDownAfterClass` to shut it down. This avoids re-booting the kernel on every test
method, which is expensive, and sidesteps PHPUnit risky-test warnings from kernel error
handler registration.

```php
final class DatabasePerTenantMiddlewareIntegrationTest extends TestCase
{
    private static DoctrineTestKernel $kernel;

    public static function setUpBeforeClass(): void
    {
        // Clean up leftover SQLite files from a prior run
        foreach ([sys_get_temp_dir().'/tenancy_test_tenant_a.db', ...] as $path) {
            if (file_exists($path)) {
                unlink($path);
            }
        }

        static::$kernel = new DoctrineTestKernel('test', false);
        static::$kernel->boot();
    }

    public static function tearDownAfterClass(): void
    {
        static::$kernel->shutdown();
    }

    public function testSomething(): void
    {
        $container = static::$kernel->getContainer();
        // ...
    }
}
```

!!! info "SQLite file cleanup"
    Each `setUpBeforeClass` that uses file-based SQLite deletes leftover `.db` files
    before booting the kernel. This prevents "table already exists" errors when the test
    suite is re-run without a full clean. Different kernels use different file paths to
    avoid cross-test interference (e.g. `tenancy_test_landlord.db`,
    `tenancy_bootstrapper_test.db`, `tenancy_test_shared_db.db`).

## Pattern: Compiler Pass Test Services

Bundle services are private by default — the container does not expose them for
`$container->get(...)` calls outside the kernel. Each test kernel adds a
purpose-built compiler pass that makes the services it needs public:

```php
final class MakeBootstrapperServicesPublicPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $ids = [
            'tenancy.doctrine_bootstrapper',
            'tenancy.context',
            'tenancy.bootstrapper_chain',
            'doctrine.orm.default_entity_manager',
            'cache.app',
        ];

        foreach ($ids as $id) {
            if ($container->hasDefinition($id)) {
                $container->getDefinition($id)->setPublic(true);
            } elseif ($container->hasAlias($id)) {
                $container->getAlias($id)->setPublic(true);
            }
        }
    }
}
```

Available test compiler passes:

| Pass | Kernel | Services exposed |
|------|--------|-----------------|
| `MakeBootstrapperServicesPublicPass` | `BootstrapperTestKernel` | `tenancy.doctrine_bootstrapper`, `tenancy.context`, `tenancy.bootstrapper_chain`, `cache.app` |
| `MakeDatabaseServicesPublicPass` | `DoctrineTestKernel` | Database switch services, connections, EMs |
| `MakeSharedDbServicesPublicPass` | `SharedDbTestKernel` | `tenancy.shared_driver`, default EM, `tenancy.context` |
| `MakeMessengerServicesPublicPass` | `MessengerTestKernel` | Messenger bus, tenant middlewares |
| `MakeCommandsPublicPass` | `CommandTestKernel` | `tenancy.command.migrate`, `tenancy.command.run` |
| `MakeTenancyTestServicesPublicPass` | `TenancyTestKernel` | All services needed by `InteractsWithTenancy` trait |

## Pattern: Stub / Spy Services

Integration tests use lightweight test doubles that implement bundle interfaces:

**`NullTenantProvider`** (`tests/Integration/Support/`) — Implements
`TenantProviderInterface`. Both `findBySlug()` and `findAll()` throw
`RuntimeException` if called — it exists only to satisfy DI wiring in tests that do not
test actual tenant lookups.

**`ReplaceTenancyProviderPass`** — A compiler pass that replaces `tenancy.provider`
(the real `DoctrineTenantProvider`, which requires a working database + cache) with
`NullTenantProvider`. Used by almost every integration test kernel.

**`StubTenantProvider`** (`tests/Integration/Messenger/Support/`) — Implements
`TenantProviderInterface` with configurable stub data. Used by Messenger tests that need
`findBySlug()` to return a real tenant object.

**`NoOpBootstrapper`** (`tests/Integration/Messenger/Support/`) — Implements
`TenantBootstrapperInterface`. `boot()` and `clear()` are no-ops. Used to verify the
`BootstrapperChain` wiring without running real bootstrappers.

**`StubConnectionFactory`** (`tests/Integration/Command/Support/`) — Returns a stub
`Doctrine\DBAL\Connection` for command kernel tests that need a wired but non-functional
DBAL connection.

## SQLite Strategy

Integration tests use SQLite for all Doctrine work — no MySQL or PostgreSQL server needed.
The test kernels use file-based SQLite paths under `sys_get_temp_dir()`:

```
/tmp/tenancy_test_landlord.db         # DoctrineTestKernel landlord EM
/tmp/tenancy_test_placeholder.db      # DoctrineTestKernel tenant EM placeholder
/tmp/tenancy_test_tenant_a.db         # DatabasePerTenantMiddlewareIntegrationTest — tenant A
/tmp/tenancy_test_tenant_b.db         # DatabasePerTenantMiddlewareIntegrationTest — tenant B
/tmp/tenancy_bootstrapper_test.db     # BootstrapperTestKernel
/tmp/tenancy_test_shared_db.db        # SharedDbTestKernel
/tmp/tenancy_testing_trait_landlord.db # TenancyTestKernel landlord EM
```

`TenantDriverMiddleware` wraps the tenant connection's driver at container compile time
via the `doctrine.middleware` tag (`connection: tenant`). On every `connect()`, its
`TenantAwareDriver` merges the active tenant's `getConnectionConfig()` over the
placeholder params — allowing one SQLite placeholder connection to be switched to any
other SQLite file (representing "tenant A's database", "tenant B's database", etc.) with
no extra Doctrine configuration in the test kernel.

## Running Tests by Category

```bash
# Full suite
vendor/bin/phpunit

# Unit tests only (fast, ~1 second)
vendor/bin/phpunit --testsuite unit

# Integration tests only
vendor/bin/phpunit --testsuite integration

# Specific test directory
vendor/bin/phpunit tests/Integration/Messenger/

# Single test file
vendor/bin/phpunit tests/Integration/DBAL/DatabasePerTenantMiddlewareIntegrationTest.php

# Specific test method
vendor/bin/phpunit --filter testSwitchToTenantA tests/Integration/DBAL/DatabasePerTenantMiddlewareIntegrationTest.php
```
