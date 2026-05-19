# Phase 6: Messenger Integration — Context

**Gathered:** 2026-03-19
**Status:** Ready for planning

<domain>
## Phase Boundary

Preserve tenant context across Symfony Messenger process boundaries:

1. **`TenantStamp`** — `StampInterface` implementation carrying the tenant slug. Attached automatically by the sending middleware when a tenant is active.
2. **Sending middleware** — Intercepts every dispatched envelope. If `TenantContext::hasTenant()` is true, attaches `TenantStamp`. If no tenant is active, passes the envelope through unchanged (non-tenant messages are a legitimate use case).
3. **Worker-side middleware** — On consume side: reads `TenantStamp` from envelope, loads the tenant via `TenantProviderInterface`, boots the full `BootstrapperChain`, runs the handler in a `try/finally` block, always clears context after handler completes (success or exception).

This phase does NOT include: CLI commands (Phase 7), PHPUnit trait (Phase 8), OSS hardening (Phase 9).

</domain>

<decisions>
## Implementation Decisions

### A — No-tenant dispatch behavior
- **Decision:** Skip silently.
- **Rationale:** Not all messages are tenant-scoped — system jobs, warmup tasks, and admin commands all legitimately dispatch without an active tenant. The bundle's pattern across all phases is "be transparent when no tenant is active" (cache delegates to global pool, SQL filter returns empty restriction). Forcing a throw would break non-tenant messages and is inconsistent.
- **Implementation:** Sending middleware checks `TenantContext::hasTenant()`. If `true`, attach `TenantStamp($tenant->getSlug())`. If `false`, `return $handler($envelope)` unchanged.
- **Worker corollary:** If an envelope arrives with no `TenantStamp`, the worker middleware passes it through without any tenant bootstrapping. Handler runs in global context. This is correct for non-tenant messages.

### B — Worker missing-tenant handling
- **Decision:** Throw and let Messenger's retry policy handle it.
- **Implementation:** Worker middleware calls `TenantProviderInterface::findBySlug($slug)`. If `TenantNotFoundException` or `TenantInactiveException` is thrown, let it propagate. Messenger's configured retry/dead-letter policy handles the rest. No special catch blocks.
- **Rationale:** Re-using existing exception types is consistent with how resolvers handle the same failure modes. Messenger's retry infrastructure is the correct place for retry/DLQ decisions — the bundle should not make that call for the user.
- **No silent discard:** Message is never silently lost; it surfaces in the dead-letter queue after max retries.

### C — Stamp payload
- **Decision:** Slug only.
- **Implementation:** `TenantStamp` carries a single `string $tenantSlug` field. Worker calls `TenantProviderInterface::findBySlug($tenantSlug)` to reload the full tenant object (including connection config).
- **Rationale:** Consistent with how every other resolver works (slug → DB lookup → TenantInterface). No credentials travel through the message broker. Lightweight serialization.
- **DoctrineTenantProvider cache:** The existing `DoctrineTenantProvider` caches tenant lookups — the worker-side DB lookup is fast and does not hit the landlord DB on every message.

### D — Bus scope
- **Decision:** Auto-enroll on all buses, zero config.
- **Implementation:** Both middlewares registered via `messenger.middleware` tag with no bus restriction. Symfony Messenger applies tagged middleware to all configured buses automatically.
- **Rationale:** Consistent with the always-on pattern used for `DoctrineBootstrapper` and `TenantAwareCacheAdapter`. Zero config is a core DX promise of the bundle.
- **No new config keys:** No `tenancy.messenger.buses` option. No `tenancy.messenger.enabled` flag. Just works.

### TenantStamp serialization
- **Claude's discretion** on exact serialization approach, but: `TenantStamp` must be serializable by Symfony Messenger's `PhpSerializer` and `JsonSerializer`. Using a plain `readonly` promoted property (`public readonly string $tenantSlug`) with a named constructor or direct constructor is sufficient. No custom `__serialize`/`__unserialize` needed unless testing reveals issues.

### Teardown guarantee
- **try/finally** is mandatory in the worker middleware. Tenant context must be cleared even when the handler throws. This is specified in the roadmap success criterion 3 and is non-negotiable.
- **Teardown sequence:** mirrors `TenantContextOrchestrator::onKernelTerminate()`: `BootstrapperChain::clear()` → `TenantContext::clear()` → dispatch `TenantContextCleared`.

### Event dispatching on worker side
- **Claude's discretion** on whether `TenantResolved` is dispatched on the worker side. Arguments for: consistent lifecycle (listeners that react to `TenantResolved` run). Arguments against: the tenant was not "resolved" in the HTTP sense — it was "restored". If dispatched, the `resolvedBy` field would be something like `TenantWorkerMiddleware::class`. Researcher should investigate whether any downstream listener depends on `TenantResolved` being dispatched.

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Phase requirements
- `.planning/REQUIREMENTS.md` §MSG-01, MSG-02, MSG-03
- `.planning/ROADMAP.md` §Phase 6 — Goal, success criteria (4 truths), plan breakdown

### Existing codebase — integration points
- `src/Context/TenantContext.php` — `hasTenant()`, `setTenant()`, `getTenant()`, `clear()` — the four methods both middlewares need
- `src/Bootstrapper/BootstrapperChain.php` — `boot(TenantInterface)` / `clear()` — canonical lifecycle hooks; worker middleware calls these
- `src/Provider/TenantProviderInterface.php` — interface the worker uses to reload a tenant from its slug
- `src/Provider/DoctrineTenantProvider.php` — concrete provider with cache-then-check pattern; already used by resolvers
- `src/EventListener/TenantContextOrchestrator.php` — canonical teardown sequence to mirror in worker middleware (`clear()` → `clear()` → `TenantContextCleared`)
- `src/Event/TenantContextCleared.php` — dispatched after teardown; worker must dispatch this too
- `src/TenancyBundle.php` — where new middleware services are wired into DI (`loadExtension()`)
- `config/services.php` — DI wiring conventions

### Symfony Messenger contracts — researcher must investigate
- `Symfony\Component\Messenger\Stamp\StampInterface` — marker interface `TenantStamp` implements
- `Symfony\Component\Messenger\Middleware\MiddlewareInterface` — `handle(Envelope, StackInterface): Envelope` contract both middlewares implement
- `Symfony\Component\Messenger\Envelope::with()` / `::last()` — how stamps are attached and read
- `Symfony\Component\Messenger\Attribute\AsMessageHandler` — not needed here, but researcher should confirm middleware tag (`messenger.middleware`) vs. handler registration
- Serialization: how `StampInterface` implementations are serialized with `PhpSerializer` vs. `JsonSerializer` — confirm `TenantStamp` survives round-trip through both

### Prior phase context
- `.planning/phases/01-core-foundation/01-CONTEXT.md` — BootstrapperChain boot/clear contract
- `.planning/phases/02-tenant-resolution/02-CONTEXT.md` — `TenantProviderInterface` / `DoctrineTenantProvider` cache pattern; slug is the canonical identifier
- `.planning/phases/05-infrastructure-bootstrappers/05-CONTEXT.md` — "always-on, zero config" DI registration pattern to follow for middleware

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- `TenantProviderInterface::findBySlug(string $slug): TenantInterface` — exact method the worker middleware needs; no new API required
- `TenantContext::hasTenant()` — sending middleware guard
- `TenantContext::setTenant()` / `::clear()` — worker middleware lifecycle
- `BootstrapperChain::boot()` / `::clear()` — worker middleware lifecycle
- `TenantContextCleared` — signal event; worker must dispatch after teardown

### Established Patterns
- **`final class` everywhere** — `TenantStamp`, sending middleware, and worker middleware all use `final`
- **`private readonly` constructor injection** — all bundle services
- **Always-on DI registration** — `DoctrineBootstrapper` and `TenantAwareCacheAdapter` are registered unconditionally; middlewares follow the same pattern
- **Teardown sequence** — `BootstrapperChain::clear()` → `TenantContext::clear()` → dispatch `TenantContextCleared` (established in `TenantContextOrchestrator::onKernelTerminate()`)
- **Let exceptions propagate** — `TenantNotFoundException` and `TenantInactiveException` are not caught in middleware; they surface to Messenger's retry policy

### Integration Points
- `src/Messenger/` — new directory; `TenantStamp.php`, `TenantSendingMiddleware.php`, `TenantWorkerMiddleware.php`
- `config/services.php` or `loadExtension()` — register both middlewares with `messenger.middleware` tag
- `tests/Unit/Messenger/` — unit tests for all three classes
- `tests/Integration/Messenger/` — integration tests with real kernel and message bus

### What does NOT exist yet
- No Messenger dependency in `composer.json` — researcher must confirm whether `symfony/messenger` is already a hard dep or needs adding as soft/optional
- No existing middleware, stamp, or bus configuration in the bundle

</code_context>

<deferred>
## Deferred Ideas

- **`tenancy.messenger.enabled` config flag** — opt-out mechanism for apps that want to manage middleware manually. V1.1 if someone asks.
- **Per-bus middleware opt-in** — `tenancy.messenger.buses: [default, async]` for granular control. V1.1.
- **`TenantResolved` dispatch on worker side** — currently "Claude's discretion" but could become a user-facing option if downstream listeners need to fire in worker processes.
- **Stamp encryption** — if `TenantStamp` ever carries more than a slug (e.g. connection config), the payload should be encrypted before transit. Not relevant for slug-only design.
- **Worker concurrency / tenant isolation** — multiple workers processing messages for the same tenant concurrently is safe (each worker process is isolated). No locking mechanism needed in v1.

</deferred>

---

*Phase: 06-messenger-integration*
*Context gathered: 2026-03-19*