# Pitfalls Research

**Domain:** Symfony multi-tenancy bundle (OSS, PHP 8.2+, Symfony 6.4/7.x)
**Researched:** 2026-03-17
**Confidence:** HIGH (Doctrine behavior verified via official docs and GitHub issues; Symfony internals verified via official docs; OSS patterns verified via community sources)

---

## Critical Pitfalls

### Pitfall 1: Doctrine Identity Map Pollution After Tenant Switch

**What goes wrong:**
When the active tenant changes mid-process (worker reuse, CLI, or integration tests), the Doctrine EntityManager's identity map still holds entity instances from the previous tenant. On the next query, Doctrine finds a matching primary key in the identity map and returns the cached entity instead of hitting the new tenant's database. Tenant A's data silently bleeds into Tenant B's request.

**Why it happens:**
Doctrine's identity map is a unit-of-work-scoped in-memory cache keyed by entity class + primary key. Switching a DBAL connection parameter does not flush or reset this map. Developers assume "new connection = clean state" but Doctrine decouples the connection layer from its object cache entirely.

**How to avoid:**
Always call `$entityManager->clear()` as part of the `TenantContextCleared` event handler — before the connection is closed and after every message is handled in workers. In the `TenantBootstrapper` for Doctrine, clear the EM at both boot and teardown. For the database-per-tenant driver, consider calling `$managerRegistry->resetManager()` rather than `clear()` alone, which also reconnects cleanly. For shared-DB driver, `clear()` suffices (same connection, different filter parameter).

**Warning signs:**
- Tests pass individually but fail when run in a suite
- Integration tests that switch tenants in a loop return unexpected data
- Worker processes that consume messages for multiple tenants return stale foreign entities
- Symfony Profiler shows queries for Tenant B but the response contains Tenant A entity IDs

**Phase to address:** Phase: Database Isolation (Doctrine bootstrapper implementation). Must be verified in the testing phase via multi-tenant unit tests that switch context between assertions.

---

### Pitfall 2: DBAL Connection Not Actually Reset on Tenant Switch

**What goes wrong:**
Calling `$connection->close()` followed by changing connection parameters (DSN, dbname) does not guarantee a clean reconnect in all DBAL versions. In long-running workers, DBAL may silently reuse an existing open socket. Even when a connection is closed and new parameters set, if the DBAL `Connection` object itself is a singleton (shared service), previously-set state — prepared statement caches, platform detection results — can persist.

**Why it happens:**
DBAL's `Connection` is designed as a per-application singleton in Symfony's DI container. Multi-tenancy hacks that mutate its internal `_params` array or call `close()` are fighting the intended design. DBAL also lazily reconnects, meaning `close()` does not clear platform metadata or pending transactions.

**How to avoid:**
For the database-per-tenant driver: wrap the switch in a DBAL event listener that calls `$connection->close()`, mutates params via the official `Connection::resetParams()` API (DBAL 3+), then forces a reconnect. Verify the platform is re-detected for each tenant if tenants span different DB versions. Test explicitly that `$connection->isConnected()` returns `false` after close and that the first subsequent query succeeds. Use `wrapperClass` only when documented — avoid subclassing `Connection` for tenant routing as it bypasses DBAL internals.

**Warning signs:**
- `PDOException: SQLSTATE[HY000] [1049] Unknown database` in workers after the first tenant switch
- Queries executing against the wrong tenant's database during load testing
- `Connection timed out` errors when a worker sits idle between messages (connection timeout at DB/load balancer level, reconnect fails)
- DBAL throws "connection already exists" during second switch attempt

**Phase to address:** Phase: Database Isolation (database-per-tenant driver). Requires integration tests with at least two real databases.

---

### Pitfall 3: Symfony DI Container Immutability — Services Cannot Be Swapped After Boot

**What goes wrong:**
Developers attempt to register a different service definition per tenant at runtime (e.g., swap the Mailer transport, the Flysystem adapter, or a Doctrine connection) by accessing the container post-boot. This is not possible: Symfony compiles and freezes the container at boot. Any attempt to call `$container->set()` on a compiled container outside of the `test` environment will either silently fail or throw.

**Why it happens:**
Symfony's DI container is a compiled PHP class. It is immutable by design after `$kernel->boot()`. Developers coming from Laravel (where the IoC container is mutable at any time) hit this wall immediately. The multi-tenancy pattern of "swap service for tenant" is valid but must be implemented through context objects, not container mutations.

**How to avoid:**
Never attempt to swap service definitions per tenant. Instead, use a stateful `TenantContext` service (a simple value object with a scope-aware getter). All tenant-aware services receive the `TenantContext` as a constructor dependency and read from it at call time — not at construction time. For the Filesystem and Cache bootstrappers, decorate the inner service and proxy calls through a tenant-aware wrapper that reads from `TenantContext`. This is the correct DI-friendly pattern for runtime context switching in Symfony.

**Warning signs:**
- `ServiceNotFoundException` or silent wrong-service usage when trying to call `$container->set()` at runtime
- Singleton services that memoize a value at first call and return it for all subsequent tenants
- Services that inject their tenant-specific config in `__construct` and then get reused across requests

**Phase to address:** Phase: Core (TenantContext service design). This is a foundational architectural decision — getting it wrong cascades through every bootstrapper.

---

### Pitfall 4: SQL Filter Bypass via Native Queries

**What goes wrong:**
Doctrine's `SQLFilter` mechanism (used by the shared-DB driver to inject `WHERE tenant_id = ?`) applies only to DQL queries and QueryBuilder operations. It is completely bypassed when developers use `$entityManager->createNativeQuery()`, `$connection->executeQuery()` (DBAL), or any raw SQL. This is a **security incident**: tenant A can read tenant B's rows.

**Why it happens:**
Doctrine SQL filters hook into the DQL compiler and ORM metadata layer. Raw SQL is opaque to Doctrine — it has no mechanism to rewrite it. This is a documented Doctrine limitation. Developers forget this when writing performance-optimised bulk operations, reports, or when integrating third-party libraries that call DBAL directly (e.g., some pagination libraries, search indexers).

**How to avoid:**
- Document this limitation prominently in the bundle README and in the `#[TenantAware]` attribute docblock.
- Provide a `TenantAwareNativeQueryBuilder` helper that automatically appends `AND tenant_id = :tenantId` parameters to native queries, making the safe path the easy path.
- In strict mode, add a Doctrine DBAL middleware (DBAL 3+ supports middleware) that intercepts raw `executeQuery` calls on entities registered as `#[TenantAware]` and validates tenant_id presence.
- Add a PHPStan rule that flags `createNativeQuery()` calls in classes that handle `#[TenantAware]` entities.

**Warning signs:**
- Reporting queries that return row counts inconsistent with what the ORM returns
- Bulk delete/update operations that clear data across tenants
- Third-party library integration (e.g., ElasticSearch sync, batch imports) that bypasses the ORM layer

**Phase to address:** Phase: Database Isolation (shared-DB driver) + PHPStan extension (Phase: DX). This is the most dangerous security pitfall in the shared-DB model.

---

### Pitfall 5: Messenger Worker Process Reuse — Tenant Context Not Cleared Between Messages

**What goes wrong:**
A Symfony Messenger worker is a long-running PHP process that consumes multiple messages sequentially. If the `TenantStamp` is read from the envelope and used to boot the tenant context for message N, but the teardown event (`TenantContextCleared`) is not fired after message N's handler completes, message N+1 executes with the previous tenant's context still active. In the worst case, a non-tenant-stamped message (system job) executes with a stale tenant context from the previous message.

**Why it happens:**
Symfony Messenger's worker loop calls handlers then moves on. There is no automatic "scope reset" between messages — that is an application concern. Developers implement the `TenantStamp` middleware for the incoming path but forget the outgoing path (cleanup). The worker teardown is subtle because there is no natural "end of request" event for workers.

**How to avoid:**
Implement a `TenantContextMiddleware` that wraps the entire handler call in a try/finally block: boot tenant context from stamp in `try`, dispatch `TenantContextCleared` event in `finally`. The `finally` block must always fire regardless of handler exceptions. Also implement the EM `clear()` call in the same `finally`. Add a `--time-limit` to workers in production to periodically recycle processes and reduce blast radius of any state leakage.

**Warning signs:**
- Worker logs show Tenant A's ID in messages that were stamped with Tenant B
- Doctrine queries in handlers return wrong data after the first few messages
- Tests using `InMemoryTransport` that process multiple messages sequentially fail on message 2+

**Phase to address:** Phase: Messenger integration. The `finally` teardown is non-negotiable — implement it before the feature is considered done.

---

### Pitfall 6: Cache Invalidation When Tenant Context Switches — Prefix Is Not Enough

**What goes wrong:**
Prefixing cache keys with `{tenant_id}:` appears to isolate tenants but creates two hidden problems: (1) `cache:pool:clear` commands and programmatic `$pool->clear()` calls clear ALL tenants' cache, not just the active one; (2) Symfony's cache namespace invalidation works by rotating a version integer stored in the cache itself — if that version key lacks a tenant prefix, namespace-based invalidation becomes cross-tenant. Result: deploying Tenant A's config change can silently invalidate Tenant B's cache.

**Why it happens:**
Symfony's cache namespace versioning stores a `_version` key in the same pool without the namespace prefix applied to it (the version key IS the namespace key). If the tenant prefix is applied only at the item level and not to the namespace version key, the version is shared across tenants.

**How to avoid:**
Use the `namespace` option on cache pool adapters, not just key prefixes. The namespace must be tenant-scoped. The `cache.app` pool should be decorated by the `CacheBootstrapper` to create a new adapter instance with a tenant-namespaced namespace at boot time (not just prepend a prefix to keys). Additionally, provide a `tenancy:cache:clear {tenant_id}` console command that clears only that tenant's namespace. Verify with a test that clearing Tenant A's cache does not affect items under Tenant B's namespace.

**Warning signs:**
- `cache:pool:clear` in CI wipes all tenants' cache simultaneously
- One tenant's cache being empty unexpectedly after another tenant deploys
- Symfony Profiler shows cache hits with mismatched tenant IDs in the key

**Phase to address:** Phase: Cache bootstrapper. Must include a test that verifies cross-tenant isolation.

---

### Pitfall 7: PHPUnit Test Isolation — Tenant Databases Leak Between Tests

**What goes wrong:**
When using `InteractsWithTenancy` trait in `WebTestCase`, each test method boots a tenant context. If the test does not explicitly tear down the context (or if teardown throws), the EntityManager retains state, the DBAL connection remains open to the previous tenant's database, and the next test method inherits stale state. Using transaction-based test isolation (DAMADoctrineTestBundle) helps for single-tenant tests but breaks for database-per-tenant scenarios: the rollback strategy targets a single connection, and there are two connections (landlord + tenant).

**Why it happens:**
Transaction-based isolation wraps each test in a transaction on the primary connection. In multi-tenant setups with separate DBs, there are N+1 connections (1 landlord + N tenant DBs). The DAMADoctrineTestBundle wraps only the configured connection, not dynamically-created tenant connections. Developers assume the bundle handles all connections.

**How to avoid:**
The `InteractsWithTenancy` trait must: (1) always call `clearTenantContext()` in a `tearDown()` method; (2) for database-per-tenant tests, spin up a fresh schema per test method (not per test class) using migrations or schema tool; (3) for shared-DB tests, use transactions for the single connection but reset the SQL filter state explicitly. Use a dedicated `test` database per tenant (named `tenant_{id}_test`) that can be wiped and re-migrated. Avoid re-using production or dev tenant databases in tests.

**Warning signs:**
- Flaky tests that pass alone but fail in suite order
- Test N+1 returning rows inserted by test N
- `The EntityManager is closed` exception in the second test after the first throws

**Phase to address:** Phase: DX (PHPUnit trait). The trait teardown is as important as the setup — write teardown before setup.

---

### Pitfall 8: Circular Dependency Risk in Bootstrapper Registration

**What goes wrong:**
Bootstrappers are registered via a compiler pass that collects all services tagged `tenancy.bootstrapper`. If a bootstrapper depends on a service that itself depends (directly or transitively) on the `TenantContext`, and the `TenantContext` is not yet populated when the compiler pass runs, the container wires a circular graph: `TenantContext → Bootstrapper → ServiceX → TenantContext`. This causes a `ServiceCircularReferenceException` at compile time — or worse, a wrong-order instantiation at runtime if `ServiceX` is lazy.

**Why it happens:**
The bootstrapper pattern creates a dependency inversion: bootstrappers configure services that depend on the tenant, but bootstrappers themselves are invoked by the tenant context system. If the `TenantContext` is not an independent leaf node in the graph (i.e., it requires a bootstrapper to be initialised), the cycle closes.

**How to avoid:**
Make `TenantContext` a pure value holder with zero dependencies — a simple class with `getActiveTenant(): ?Tenant` and a `setActiveTenant()` method. It must not depend on Doctrine, cache, or any other service. All bootstrappers depend on `TenantContext` (reading from it) but `TenantContext` must not depend on any bootstrapper. Validate this constraint with a `CheckCircularReferencesPass` unit test and with PHPStan (a circular dependency check rule). Never inject bootstrappers into `TenantContext`.

**Warning signs:**
- `ServiceCircularReferenceException` at container compile time
- `TenantContext` constructor growing beyond zero parameters
- A bootstrapper that calls `setActiveTenant()` on the context it depends upon

**Phase to address:** Phase: Core (architectural design of TenantContext). Fixed in architecture, not patchable later.

---

### Pitfall 9: kernel.request Listener Priority Conflict With Security Firewall

**What goes wrong:**
Tenant resolution must happen early in the request lifecycle so that the tenant context is available to the security firewall (which may need to load users from the tenant's database). However, if the `TenantResolverListener` is registered at `kernel.request` with priority `0` (default), it executes after the Security firewall's `kernel.request` listener (which runs at priority `8`). The firewall tries to load a user before the tenant context is set — resulting in an empty tenant context and a null-DB connection.

**Why it happens:**
Symfony's built-in `kernel.request` listeners run at specific priorities: Router at `32`, Security at `8`, Locale at `16`. Developers register a listener at `0` assuming it runs "before" the application code, not realising that priority `8` runs before priority `0`.

**How to avoid:**
Register the `TenantResolverListener` at `kernel.request` with priority `20` (between Router at `32` and Security at `8`). Document this priority in the bundle's configuration and in the extension class. Test by asserting that the `TenantContextInterface` is populated during a `LoginFormAuthenticator` flow. Also provide an explicit priority constant (`TenantResolverListener::PRIORITY = 20`) so users implementing custom resolvers know the correct value.

**Warning signs:**
- Authentication fails with "tenant not found" errors that only appear on the login route
- `TenantContext::getActiveTenant()` returns `null` inside a custom authenticator
- Profiler shows tenant resolution happening after security token resolution

**Phase to address:** Phase: Tenant Resolution (resolver listener registration). Easy to miss, critical to get right.

---

### Pitfall 10: Shared Resource Sync — Infinite Event Loop via Doctrine Cascade

**What goes wrong:**
When syncing shared resources (e.g., a global `User` entity) across tenant databases using Doctrine events, the sync mechanism persists a copy of the entity into each tenant database. If the target EntityManager is the same one that triggered the original `postPersist` event, or if cascades are configured on the synced entity, the sync listener fires again for the copy — triggering another sync — creating an infinite loop that fills all tenant databases with duplicate records.

**Why it happens:**
Doctrine event subscribers fire for every EntityManager operation. If the sync bootstrapper shares the same EM instance for landlord and tenant databases, or if the `postPersist` listener does not guard against re-entrancy, it recurses. This is the same problem as "action at a distance" in ORM event listeners.

**How to avoid:**
Use a dedicated `$landlordEntityManager` (separate service ID) for landlord entities and a separate `$tenantEntityManagers` pool for tenant operations. The sync event listener must set a `$syncing = true` guard flag before calling persist on tenant EMs and check it on entry. For async sync (Messenger), dispatch a `SharedResourceSyncMessage` and let a worker re-boot the correct tenant context before persisting — this naturally avoids re-entrancy since the worker runs in a separate process.

**Warning signs:**
- `Maximum function nesting level reached` PHP fatal error in sync listener
- Duplicate records with identical IDs appearing in tenant databases
- Memory exhaustion during `postPersist` for entities with sync enabled

**Phase to address:** Phase: Resource Sharing. Test both sync and async paths with a guard flag unit test.

---

## Technical Debt Patterns

| Shortcut | Immediate Benefit | Long-term Cost | When Acceptable |
|----------|-------------------|----------------|-----------------|
| Hardcoding `tenant_id` column name instead of configuring it | Faster initial implementation | Every adopter with a non-standard schema must fork or monkey-patch | Never — use a config option from day one |
| Using `EntityManager::clear($entityClass)` (deprecated in ORM 3) | Selective cache clearing | Breaks on Doctrine ORM 3 upgrade (API removed) | Never — use `clear()` (no args) |
| Registering the `TenantResolverListener` at default priority `0` | No priority thinking required | Security firewall runs before tenant is resolved | Never — always set explicit priority |
| Storing `TenantContext` in a static property | Simple, no DI needed | Breaks FrankenPHP/RoadRunner (shared memory), impossible to test in isolation | Never |
| Running tenant migrations sequentially with no timeout | Simple implementation | A hung migration blocks all other tenants permanently | Acceptable in v1 if documented; add timeout in v1.1 |
| Skipping EM `clear()` in worker teardown during development | Slightly faster local tests | Stale identity map causes bizarre cross-tenant bugs in production workers | Never |

---

## Integration Gotchas

| Integration | Common Mistake | Correct Approach |
|-------------|----------------|------------------|
| Doctrine SQL Filter + UniqueEntity constraint | `UniqueEntity` validator bypasses the filter, checking uniqueness across all tenants | Either scope uniqueness to the tenant in a custom validator, or use the `UniqueEntity` `filters` option if available (verify per Doctrine version) |
| Symfony Cache + ChainAdapter | Decorating only the outer chain misses the inner adapters; tenant prefix is applied only to one layer | Decorate at the innermost adapter level or use Symfony's `namespace` option which applies at the adapter constructor level |
| Flysystem 3.x + League/Flysystem | `FilesystemAdapter` is not a Symfony service by default; decorating it requires a custom service definition | Register the adapter as a named service and decorate it via the DI, not by subclassing the `Filesystem` object |
| Symfony Mailer + Messenger (async) | Dispatching an email in a handler causes a re-dispatch to the mailer queue without the `TenantStamp`; SMTP transport uses the wrong tenant config | The sending middleware must re-attach the `TenantStamp` when re-dispatching for Mailer async transport |
| PHPStan + Doctrine SQL Filter | PHPStan does not know about runtime-injected SQL filters; it may flag `tenant_id` usage as "always null" | The PHPStan extension must teach PHPStan about the filter's effect on query results |
| Symfony Messenger + multiple transports per tenant | Using a per-tenant transport DSN requires a `TransportFactory` that reads from `TenantContext` — not supported out of the box | Implement a custom `TransportFactory` with tenant-aware DSN resolution, or use routing by message class with a single shared transport |

---

## Performance Traps

| Trap | Symptoms | Prevention | When It Breaks |
|------|----------|------------|----------------|
| Opening a new DBAL connection per message in a worker | Memory growth, DB connection exhaustion (too many open connections) | Reuse connections with `close()`/reconnect pattern; use a connection pool at the DB level (PgBouncer/ProxySQL) | At 20+ concurrent workers processing high-volume tenants |
| Running `tenancy:migrate` sequentially without progress feedback | Migration CLI hangs silently for 30 minutes on 100 tenants; ops team kills it prematurely | Add `--tenant` filter, add real-time progress output, add per-migration timeout | At 50+ tenants with schema-heavy migrations |
| Loading all `Tenant` entities to resolve hostname | `SELECT * FROM tenants` on every request when using a HostResolver | Cache the tenant lookup result (e.g., in APCu/Redis) keyed by hostname with a short TTL | At 1,000+ requests/second |
| Clearing entire Redis cache pool on deploy | All tenants' cached data evicted simultaneously, causing thundering-herd on DB | Use tagged cache invalidation; invalidate only keys tagged with the affected tenant or deployment ID | At 10+ concurrent tenants with warm caches |
| Identity map not cleared between messages in worker | Memory grows linearly per message; eventually OOM-killed | Always call `$em->clear()` in the `finally` block of the worker middleware | After ~10,000 messages without worker restart |

---

## Security Mistakes

| Mistake | Risk | Prevention |
|---------|------|------------|
| Using tenant slug (human-readable string) as the sole tenant identifier in the SQL filter | An attacker can guess slugs and potentially access other tenant data if authorization is not enforced elsewhere | Use an opaque UUID or hash as the tenant_id in the SQL filter; keep slug only for URL routing |
| Disabling strict mode globally to silence errors during development | With strict mode off, a `#[TenantAware]` entity query with no active tenant returns all rows — all tenants' data | Keep strict mode ON by default; never disable globally. Use `withoutTenantContext()` only in explicitly scoped operations (migrations, admin commands) |
| Passing tenant ID directly from user-supplied input to the SQL filter without validation | Tenant ID injection — attacker modifies a header/query param to access another tenant | Always resolve the tenant from a trusted source (database lookup by domain/ID) and validate the resulting Tenant entity exists before setting context |
| SQL filter not applied to associations (lazy-loaded) | `$order->getUser()` may return a user from any tenant if the User entity is not `#[TenantAware]` | Mark all cross-entity relationships with `#[TenantAware]` where applicable; add an integration test that loads an associated entity and asserts it belongs to the active tenant |
| Native query in a report generator bypasses SQL filter | Report leaks all tenants' data | Document the limitation; provide a `TenantAwareNativeQueryHelper`; add PHPStan rule flagging raw native queries in tenant-aware classes |
| `TenantContext` readable from user-land without guard | A service that reads context may be called before tenant is resolved, returning null and falling through to unscoped queries | TenantContext must throw `TenantNotResolvedException` (not return null) when strict mode is on and context is empty |

---

## UX Pitfalls

| Pitfall | User Impact | Better Approach |
|---------|-------------|-----------------|
| Bundle registers services with non-prefixed names (e.g., `tenant_context`) | Namespace collision with existing user services | All services registered as `tenancy.context`, `tenancy.resolver_chain`, etc. — no short names in the global container |
| No debug output in the tenant resolver chain | Developer cannot tell which resolver fired (or why none fired) for a given request | Implement a resolver chain with `debug: true` mode that logs each resolver's result to the Profiler panel |
| `tenancy:migrate` with no dry-run flag | Irreversible schema changes applied to all tenants on accidental run | Add `--dry-run` flag and `--tenant` filter before v1 release |
| Error message "Tenant not found" with no context (domain, header value) | Developer spends 20 minutes debugging; includes sensitive domain info in logs by default | Include the resolved identifier (domain/header value, not the raw request) in the exception message in non-production environments |
| No teardown example in README | Developers implement boot but forget clear, causing worker state leakage | README must show the full lifecycle: boot → handle → clear in one code block |

---

## "Looks Done But Isn't" Checklist

- [ ] **SQL Filter (shared-DB):** Filter is enabled — but verify it is also re-applied after `EntityManager::clear()` is called (clear does not re-enable disabled filters; the filter stays enabled but its parameter may be wiped)
- [ ] **Messenger TenantStamp:** Stamp is attached when dispatching — but verify the stamp survives serialization through the transport (e.g., JSON transport requires all stamp properties to be serializable)
- [ ] **Cache isolation:** Cache key prefix is set — but verify that `$pool->clear()` only clears the active tenant's namespace, not the entire pool
- [ ] **Worker teardown:** Try block calls tenant boot — but verify `finally` always calls tenant clear, including when the handler throws a `Throwable` (not just `\Exception`)
- [ ] **Database-per-tenant migration:** Migrations run for all tenants — but verify that a failed migration for Tenant N is reported and does not silently skip Tenant N+1
- [ ] **HostResolver:** Works for `tenant.app.com` — but verify it also works for custom domains (`tenant.com`) and does not return a false positive for the landlord domain itself
- [ ] **Strict mode:** Throws on missing tenant — but verify it also throws when the tenant ID is set to a non-existent tenant (not just when it is null)
- [ ] **PHPUnit trait:** Sets up tenant — but verify `tearDown()` runs even when `setUp()` throws (use `parent::tearDown()` in correct order)

---

## Recovery Strategies

| Pitfall | Recovery Cost | Recovery Steps |
|---------|---------------|----------------|
| Identity map pollution caused cross-tenant data to be served | HIGH | Audit all affected requests via access logs; notify affected tenants; add `clear()` call to `TenantContextCleared` event immediately |
| DBAL connection not reset → wrong-tenant queries | HIGH (potential data leak) | Immediately roll back to previous version; audit query logs per connection for cross-tenant rows; add integration test reproducing the failure |
| SQL filter bypass via native query exposes data | CRITICAL | Treat as a security incident; rotate all affected tenant credentials; audit the specific query path; add a PHPStan rule immediately |
| Worker state leak (stale tenant context between messages) | MEDIUM | Restart all workers immediately; add `finally` teardown; add a canary test message with an assertion on tenant ID |
| Circular dependency at container compile | LOW | Add `TenantContext` as a leaf node (no dependencies); re-run `bin/console debug:container`; fix the graph |
| Flex recipe breaks existing installations on upgrade | MEDIUM | Pin the recipe version in `symfony.lock`; provide a CHANGELOG entry and upgrade guide; use `recipes:update` command to opt users in explicitly |

---

## Pitfall-to-Phase Mapping

| Pitfall | Prevention Phase | Verification |
|---------|------------------|--------------|
| Doctrine identity map pollution | Database Isolation — Doctrine bootstrapper | Test: switch tenant twice in one process; assert second tenant's data, not first's |
| DBAL connection not reset | Database Isolation — database-per-tenant driver | Integration test: two real databases, switch connection, assert correct DB name |
| DI container immutability | Core — TenantContext design | PHPStan: no service calls `$container->set()` at runtime |
| SQL filter bypass (native queries) | Database Isolation — shared-DB driver + PHPStan extension | Security test: call `createNativeQuery()` without tenant filter; assert exception in strict mode |
| Messenger worker reuse | Messenger integration | Test: process 2 differently-stamped messages; assert context is cleared between them |
| Cache invalidation cross-tenant | Cache bootstrapper | Test: set cache item for Tenant A; clear Tenant B; assert Tenant A item still exists |
| PHPUnit test isolation | DX — InteractsWithTenancy trait | Run the test suite in random order (`--order=random`); all tests must pass |
| Circular dependency in bootstrapper | Core — compiler pass design | `bin/console debug:container` must succeed; no `ServiceCircularReferenceException` |
| kernel.request priority conflict | Tenant Resolution — listener registration | Integration test: assert `TenantContext` is populated inside a `kernel.request` listener at priority `7` |
| Shared resource sync loop | Resource Sharing | Test: persist a shared entity; assert it appears once (not N times) in each tenant DB |

---

## Sources

- [Symfony Built-in Events and Listener Priorities](https://symfony.com/doc/current/reference/events.html) — official, HIGH confidence
- [Doctrine Working with Objects — Identity Map](https://www.doctrine-project.org/projects/doctrine-orm/en/3.6/reference/working-with-objects.html) — official, HIGH confidence
- [Symfony Messenger Docs — Long-Running Workers](https://symfony.com/doc/current/messenger.html) — official, HIGH confidence
- [SymfonyCon Amsterdam 2025: Multi-Tenantize the Symfony components](https://symfony.com/blog/symfonycon-amsterdam-2025-multi-tenantize-the-symfony-components) — official Symfony blog, HIGH confidence
- [Symfony Cache Docs — Namespaces and Invalidation](https://symfony.com/doc/current/components/cache/cache_invalidation.html) — official, HIGH confidence
- [Doctrine ORM Issue #5606 — Disable identity map](https://github.com/doctrine/orm/issues/5606) — GitHub issue, MEDIUM confidence
- [Doctrine ORM Issue #1626 — EntityManager closed on exception](https://github.com/doctrine/orm/issues/1626) — GitHub issue, HIGH confidence
- [Symfony Issue #35360 — EntityManager closed for all future messages after DB error](https://github.com/symfony/symfony/issues/35360) — GitHub issue, HIGH confidence
- [Symfony Cache Issue #59509 — prefix_seed per pool](https://github.com/symfony/symfony/issues/59509) — GitHub issue, MEDIUM confidence
- [rentpost/doctrine-multi-tenancy — Filter context pitfalls](https://github.com/rentpost/doctrine-multi-tenancy) — community library README, MEDIUM confidence
- [Ecotone Symfony Multi-Tenant Applications](https://blog.ecotone.tech/symfony-multi-tenant-applications-with-ecotone/) — community blog, MEDIUM confidence
- [Matthias Noback — Semantic Versioning for Bundles](https://matthiasnoback.nl/2014/09/semantic-versioning-for-bundles/) — community expert blog, MEDIUM confidence
- [Symfony Best Practices for Reusable Bundles](https://symfony.com/doc/current/bundles/best_practices.html) — official, HIGH confidence
- [Symfony Backward Compatibility Promise](https://symfony.com/doc/current/contributing/code/bc.html) — official, HIGH confidence
- [DAMADoctrineTestBundle — PHPUnit test isolation](https://github.com/dmaicher/doctrine-test-bundle) — community library, MEDIUM confidence
- [Symfony Compiler Passes Docs](https://symfony.com/doc/current/service_container/compiler_passes.html) — official, HIGH confidence

---

*Pitfalls research for: Symfony multi-tenancy bundle (symfony-tenancy-bundle)*
*Researched: 2026-03-17*
