# Design Decisions

This page documents the key architectural decisions made during bundle development. For each decision: what was decided, why it was chosen, what alternatives were considered, and the trade-offs accepted.

These decisions are not arbitrary. Many encode security defaults or practical constraints that would otherwise require users to re-discover them.

---

## 1. strict_mode Defaults to ON

**What:** When a `#[TenantAware]` entity is queried and no tenant is active, `TenantAwareFilter` throws `TenantMissingException`. This is the default behavior.

**Why:** "A data leak across tenants is a security incident, not a config mistake." Fail-closed is safer than fail-open. If a developer forgets to boot tenant context before querying, they get a clear error — not silently cross-tenant data.

**Alternatives considered:** Default `strict_mode: false` (more convenient for initial setup). Rejected — convenience does not justify the data leak risk. Developers opt out explicitly when they genuinely need cross-tenant queries (admin tooling, migrations).

**Trade-off:** Console commands and admin tooling that intentionally query across tenants must either disable strict mode in config or ensure no `#[TenantAware]` entities are queried without an active tenant.

---

## 2. TenantContext Is Zero-Dependency

**What:** `TenantContext` has no constructor parameters and no injected services. It is a plain PHP object with four methods: `setTenant()`, `getTenant()`, `hasTenant()`, `clear()`.

**Why:** `TenantContext` is injected into nearly every bundle service: `TenantAwareFilter`, `TenantWorkerMiddleware`, `TenantSendingMiddleware`, `SharedDriver`, resolvers, and user-land bootstrappers. Any dependency added to `TenantContext` risks a circular reference during container compilation. A zero-dependency leaf node is always safe to inject anywhere in the dependency graph.

**Alternatives considered:** Inject `EventDispatcherInterface` into `TenantContext` to auto-dispatch events when `setTenant()` is called. Rejected — creates coupling between the context holder and the event system, and introduces circular dep risk when any event listener also holds `TenantContext`.

**Trade-off:** `TenantContextOrchestrator` and `TenantWorkerMiddleware` must explicitly call `setTenant()` and dispatch events themselves. The orchestration logic is slightly more verbose, but the context holder stays clean.

---

## 3. Bootstrapper clear() Runs in Reverse Order

**What:** If bootstrappers A, B, C boot in that order, teardown calls C, B, A.

**Why:** Later bootstrappers may depend on state set up by earlier ones. Example: `DatabaseSwitchBootstrapper` sets up the tenant DB connection (A). `DoctrineBootstrapper` clears the identity map using that connection (B). If clear ran A first (restore landlord DB), then B would be clearing an identity map against the landlord connection — not the tenant connection it was built with. Reverse order unwinds the stack safely.

**Alternatives considered:** Clear in original order (A, B, C). Rejected — would require each bootstrapper to be fully independent, which is too restrictive. The cascade-dependency pattern (later bootstrappers depending on earlier ones) is a natural and useful pattern.

**Trade-off:** Bootstrapper authors must be aware that other bootstrappers that ran after them will clear first. The clear order is documented and predictable.

---

## 4. kernel.request Priority 20

**What:** `TenantContextOrchestrator` is registered at `kernel.request` priority 20.

**Why:** Must be after the Router (priority 32) so the resolved route is available to resolvers that inspect route attributes. Must be before the Security firewall (priority 8) so that controllers receive fully-tenanted services when they are constructed. Priority 20 is the only safe window.

**Alternatives considered:**

- Priority 0 (default, after security): Rejected — controller constructors would receive un-tenanted services because the DI container resolves constructor dependencies before the controller action runs.
- Priority 32+ (before Router): Rejected — route information would not be available to route-based resolvers.

**Trade-off:** The priority constant is `public const PRIORITY = 20` — it can be referenced by integrators who need to know where tenancy fits in the listener stack.

---

## 5. DBAL Driver-Middleware for Connection Switching (reflection approach REJECTED)

**What:** Tenant database switching routes through `Doctrine\DBAL\Driver\Middleware`.
`TenantDriverMiddleware::wrap()` returns a `TenantAwareDriver` that merges the active
tenant's `getConnectionConfig()` over the landlord placeholder params inside
`connect()`. `DatabaseSwitchBootstrapper::boot()` is reduced to `$connection->close()` —
DBAL's lazy-reconnect path re-enters the middleware with fresh `TenantContext` state.

**Why:** DBAL 4 resolves the `Driver` implementation at `DriverManager::getConnection()`
construction time and stores it immutably on the `Connection`. Only per-`connect()`
parameter merging via a middleware can rotate the socket without mutating vendor internals.

**Alternatives considered:**

| Approach | Disposition |
|----------|-------------|
| DBAL `Connection` subclass + private-property reflection mutation on `$params` | **REJECTED** (Phase 15, v0.2) |
| New `Connection` instance per tenant | Rejected — invalidates all DI service references |
| DBAL event for parameter mutation | Rejected — no such event exists in DBAL |
| Bundle-managed connection pool | Rejected — over-engineered for the problem |
| **`Doctrine\DBAL\Driver\Middleware` chain** | **Accepted — the documented DBAL 4 extension point** |

**Private-property reflection on `Connection` — REJECTED.** DBAL 4 stores the resolved
`Driver` immutably on `Connection` at construction time. A reflection trick can mutate
`$params` but not `$driver`, making the approach viable only by coincidence (when the
landlord placeholder and the tenant databases share a driver family). The approach also
couples bundle correctness to a vendor implementation detail that is outside the
documented DBAL contract. The mature architecture uses `Doctrine\DBAL\Driver\Middleware`
instead — see [`dbal-middleware.md`](dbal-middleware.md) for the full pipeline.

**Trade-off:** Tenant `getConnectionConfig()` **must** return discrete DBAL params — never a
`url` key. DBAL parses `url` at DriverManager time, before middlewares run; tenant-side
`url` keys in the merged array are effectively ignored. This constraint is documented in
`TenantAwareDriver` and surfaced in `UPGRADE.md`.

---

## 6. Optional Doctrine via class_exists Guards

**What:** All Doctrine imports and features are guarded by `class_exists()` or `interface_exists()`. No Doctrine class is hard-imported at the top of bundle files without a guard.

**Why:** The bundle should be installable without Doctrine for non-DB use cases (cache isolation, Messenger context propagation, or custom bootstrappers). A hard `use Doctrine\ORM\...` import at the top of any bundle service file would cause a fatal `class not found` error if Doctrine is not installed.

**Alternatives considered:** Make Doctrine a hard `require` in `composer.json`. Rejected — reduces bundle applicability. Users running Messenger-only or cache-isolation-only scenarios would be forced to install Doctrine.

**Trade-off:** The `BootstrapperChainPass` must check `$container->has('doctrine.orm.entity_manager')` before keeping `DoctrineBootstrapper`. Conditional service registration in `loadExtension()` adds complexity.

---

## 7. interface_exists for Messenger — Not class_exists

**What:** The Messenger guard uses `interface_exists(MessageBusInterface::class)`, not `class_exists(MessageBusInterface::class)`.

**Why:** `MessageBusInterface` is a PHP **interface**. `class_exists()` returns `false` for interfaces in PHP — it only returns `true` for classes, abstract classes, and traits. Using `class_exists()` here would cause silent skip of all Messenger wiring, with no error.

This distinction was caught during development when Messenger middleware was silently not registered because the guard used `class_exists`.

!!! info "Lesson: PHP type check functions are specific"
    - `class_exists(Foo::class)` — returns `true` for classes, abstract classes, traits; returns `false` for interfaces
    - `interface_exists(Foo::class)` — returns `true` for interfaces only
    - When guarding optional dependencies, always use the function matching the PHP type

---

## 8. ConsoleResolver Operates Independently

**What:** The `ConsoleResolver` listens on `ConsoleCommandEvent` and boots tenant context directly, rather than routing through `TenantContextOrchestrator` and `ResolverChain`.

**Why:** Console commands have no HTTP `Request` object. `ResolverChain::resolve(Request $request)` requires a `Request` — it cannot be called from a console context. ConsoleResolver intercepts `ConsoleCommandEvent`, reads the `--tenant=<slug>` flag, and calls `BootstrapperChain::boot()` directly.

**Alternatives considered:** Modify `ResolverChain` to accept a nullable `Request`. Rejected — makes the HTTP path more complex for a single special case. The console path is sufficiently different (CLI flag vs. HTTP header/domain) to warrant separate handling.

**Trade-off:** ConsoleResolver bypasses the normal resolver priority ordering. This is intentional — there is only one source of tenant identity in a console context (the `--tenant` flag).

---

## 9. TenantResolved Not Dispatched in Worker

**What:** `TenantWorkerMiddleware` does not dispatch `TenantResolved` when it boots tenant context from a `TenantStamp`.

**Why:** `TenantResolved` carries `public readonly ?Request $request`. Listeners attached to this event in HTTP context may call `$event->getRequest()` and expect a non-null value. In a Messenger worker, there is no request — firing `TenantResolved` could trigger HTTP-specific listeners and cause null reference errors or incorrect behavior.

The tenant is being **restored** from a stamp, not **resolved** from a request. These are semantically different operations.

**Alternative considered:** Dispatch `TenantResolved` with `request: null` (the field is nullable). Rejected — would require every `TenantResolved` listener to check `$event->request !== null` before using it, creating a fragile contract.

---

## 10. EntityManagerResetListener Uses resetManager(null)

**What:** `EntityManagerResetListener` calls `$registry->resetManager()` with no argument (equivalent to `null`), not `$registry->resetManager('tenant')`.

**Why:** Works correctly in both driver modes:

| Mode | Entity Manager to Reset | Correct Call |
|------|------------------------|--------------|
| `database_per_tenant` | Named `'tenant'` EM | `resetManager()` → default EM |
| `shared_db` | Default unnamed EM | `resetManager()` → default EM |

In `database_per_tenant` mode, the tenant EM is the default EM (applications that don't use the landlord EM directly only have one EM configured as default). In `shared_db` mode, there is only one EM and it is the default. Passing `null` (no argument) resets the default EM, which is the correct EM in both modes.

**Original bug:** The listener previously called `resetManager('tenant')`, which worked only in `database_per_tenant` mode and threw `InvalidArgumentException` in `shared_db` mode (where no EM named `'tenant'` exists). This was fixed in Phase 05.

!!! info "Lesson: 'Default' is correct for multi-mode services"
    When a service must work across multiple driver modes, targeting the default entity manager (null/no argument) is more robust than targeting a named EM. The default EM always exists; named EMs are mode-specific.
