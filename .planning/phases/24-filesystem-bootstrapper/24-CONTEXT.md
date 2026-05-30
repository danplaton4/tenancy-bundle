---
phase: 24
slug: filesystem-bootstrapper
name: Filesystem Bootstrapper (BOOT-03)
created: 2026-05-30
discuss_mode: discuss (3 gray areas resolved with user)
canonical_refs:
  - path: .planning/REQUIREMENTS.md
    note: "v0.4 active requirements + tentative architectural decisions (DEC-FILE-01..02). BOOT-03 acceptance criteria are the source of truth."
  - path: .planning/phases/24-filesystem-bootstrapper/24-DISCUSSION-LOG.md
    note: "User-locked decisions for the 3 substantive gray areas (Flysystem bundle, adapter mode scope, multi-FS strategy)."
  - path: src/Bootstrapper/MailerBootstrapper.php
    note: "Closest analog — optional dep guarded by interface_exists, no-op boot, clear() cleanup. Phase 24 follows the same shape."
  - path: src/Bootstrapper/TenantBootstrapperInterface.php
    note: "Interface contract — boot(TenantInterface $tenant): void + clear(): void. Tagged tenancy.bootstrapper, picked up by BootstrapperChainPass."
  - path: src/Mailer/TenantAwareTransportsDecorator.php
    note: "Pattern reference: decorate a Symfony service via decoration_priority + read TenantContext live at call time. Phase 24's filesystem decorator follows this shape."
  - path: src/Mailer/TenantMailerConfigTrait.php
    note: "Pattern reference: trait shipped for users with custom Tenant entities. Phase 24 ships TenantFilesystemConfigTrait following the same shape."
  - path: src/Mailer/LruTransportCache.php
    note: "Pattern reference: bounded per-tenant LRU cache cleared on TenantContextCleared. If per-tenant-adapter mode wires multiple Flysystem instances, a similar cache prevents unbounded growth in long-running workers."
  - path: src/Entity/AbstractTenant.php
    note: "Tenant entity base class — add filesystemConfig nullable JSON column following the mailerDsn pattern. Optional via trait so existing custom entities don't break."
  - path: src/TenantInterface.php
    note: "Interface contract — add OPTIONAL getFilesystemConfig(): ?array via trait (no abstract method on interface to avoid BC break)."
  - path: src/DependencyInjection/Compiler/MailerTransportContractPass.php
    note: "Pattern reference: compile-time guard for optional-dep wiring. Phase 24 ships FilesystemContractPass following the same shape."
  - path: src/DependencyInjection/Compiler/BootstrapperChainPass.php
    note: "Compiler pass that tags collect — FilesystemBootstrapper gets tenancy.bootstrapper priority -30 (after Mailer at -20, after Doctrine, after DatabaseSwitch)."
  - path: src/TenancyBundle.php
    note: "configure() needs new tenancy.filesystem.* config node. loadExtension() needs conditional service registration guarded by FilesystemOperator interface_exists."
  - path: src/Resources/views/Collector/tenant.html.twig
    note: "Profiler integration — add a filesystem subsection in the post-Phase-23 unconditional rendering area. Optional D-08-style sub-feature, scope-permitting."
  - path: composer.json
    note: "Add league/flysystem-bundle: ^3.0 to require-dev + suggest. NOT require — must remain optional, guarded by interface_exists."
  - path: examples/saas/
    note: "Demo app may exercise the filesystem bootstrapper for a per-tenant upload page (out-of-scope decision per phase plan; defer to Phase 29 docs)."
---

# Phase 24 — Context

## Domain

**Per-tenant filesystem scoping via Flysystem.** When a tenant is resolved, every Flysystem service tagged `tenancy.scoped` automatically points at the active tenant's storage — either as a sub-prefix on a shared adapter (prefix mode) or as a per-tenant adapter instance (per-tenant-adapter mode). Untagged Flysystem services bypass scoping, so landlord-side assets and shared resources stay accessible across the resolver chain.

Closure of v0.3's "no per-tenant storage" gap. The runnable demo (`examples/saas/`) will gain a credible upload story; downstream SaaS users get filesystem isolation without writing decorators by hand.

## Decisions Locked

### DEC-FILE-BUNDLE — Integrate with `league/flysystem-bundle`

**Decision:** Take a `require-dev` + `suggest` dependency on `league/flysystem-bundle: ^3.0`. Production code uses `interface_exists(\League\Flysystem\FilesystemOperator::class)` guards; the bundle's `FilesystemContractPass` skips wiring when the dep is absent.

**Why:** `league/flysystem-bundle` is the canonical Flysystem 3 bundle, maintained by the Flysystem owner. Modern API, well-documented, native fit for Symfony 7/8. `oneup/flysystem-bundle` is more widely deployed historically but heavier to wrap and supports Flysystem 1/2/3 (drift surface). Picking one bundle now keeps the integration surface small; we can add `oneup` support in a future phase if demand justifies it.

**Rejected:** Multi-bundle runtime detection. Doubles the test matrix and integration surface for unclear ROI — `league/flysystem-bundle` adoption is high enough on Symfony 7+ that single-bundle support covers the install funnel.

### DEC-FILE-MODE — Ship BOTH prefix AND per-tenant-adapter in v0.4

**Decision:** Ship both isolation strategies:

- **`prefix` mode (default):** `FilesystemPrefixingDecorator` wraps the underlying adapter and prepends `tenant_<slug>/` to every operation. Single shared adapter, lowest-friction onboarding. Default per `DEC-FILE-01`.
- **`per_tenant_adapter` mode (opt-in via tenant config):** `TenantAwareFilesystemDecorator` reads the active tenant's `filesystemConfig` and instantiates a per-tenant adapter from the supplied DSN. LRU cache (`LruFilesystemCache`, mirrors `LruTransportCache`) prevents unbounded growth in long-running workers.

Mode selection happens per Flysystem service via the `tenancy.filesystem.scope` config node (or the `tenancy.scoped` tag attribute `strategy: prefix|per_tenant_adapter`).

**Why:** Both modes have real production demand. Prefix mode covers the common case (single S3 bucket, per-tenant subdirs); per-tenant-adapter covers the regulated/sovereign case (separate S3 bucket per tenant, or per-tenant DigitalOcean Spaces). Shipping both in v0.4 reads BOOT-03 as "done" rather than "half-done" — symmetric with how BOOT-04 shipped sync + async in one milestone.

**Rejected:** Defer per-tenant-adapter to v0.5. The split would require re-touching every test fixture + decorator wiring in v0.5 — sunk cost.

**Rejected:** Per-tenant-adapter only. Forces every install to write tenant-side config from day one. Breaks the v0.3 install-funnel principle.

### DEC-FILE-MULTI — Scope by tag (`tenancy.scoped`), not all-or-nothing

**Decision:** Tenant-scope ONLY Flysystem services tagged `tenancy.scoped` (or declared explicitly under `tenancy.filesystem.services:` config). Non-tagged services bypass scoping entirely.

The tag accepts attributes:
- `strategy: prefix | per_tenant_adapter` (default `prefix`)
- `prefix_template: "tenant_{slug}/"` (default — applies only to `prefix` mode)

**Why:** Real SaaS apps have multiple filesystems (uploads, exports, public CDN assets, landlord-only logos). Scoping all of them is too aggressive (no escape hatch); scoping only the default is too narrow (most apps have 3+ filesystems). Tag-based opt-in matches the existing bundle pattern (`tenancy.resolver`, `tenancy.bootstrapper`) and gives users explicit control.

**Rejected:** Scope-all model. No escape hatch for landlord-only filesystems is a real footgun (uploads of company logos for the landlord onboarding flow would accidentally get per-tenant-scoped).

**Rejected:** Default-only model. Most production SaaS deployments have separate filesystems per concern (uploads, exports, public assets). Only-default forces users to manually decorate the rest.

### DEC-FILE-CONFIG — `TenantInterface::getFilesystemConfig()` via OPTIONAL trait

**Decision:** Add `TenantFilesystemConfigTrait` shipping `getFilesystemConfig(): ?array`. Add a `filesystemConfig` nullable JSON column to `AbstractTenant`. The `TenantInterface` does NOT gain a new abstract method (zero BC break for downstream users with custom Tenant entities).

Return shape:
```php
?array{
  prefix?: string,           // prefix mode override — defaults to "tenant_{slug}/"
  adapter_dsn?: string,      // per_tenant_adapter mode — e.g. "s3:///bucket/path?region=eu-central-1"
  services?: array<string>,  // optional: limit scoping to these service IDs (empty = all tagged)
}
```

When `null`: prefix mode with default template applies to all tagged services. This is the zero-config path for new installs.

**Why:** Symmetric with `TenantMailerConfigTrait` from Phase 20 — same migration story in UPGRADE 0.3 → 0.4 (no BC break if users adopt the trait). Optional return shape keeps prefix-mode users out of any per-tenant DSN management. Per-tenant-adapter users opt in via the `adapter_dsn` key.

**Rejected:** Abstract method on TenantInterface. BC break for every downstream user with a custom Tenant entity — same trap that Phase 20 dodged with the trait pattern.

### DEC-FILE-EXCEPTION — `MissingFilesystemConfigException extends \LogicException`

**Decision:** When `per_tenant_adapter` mode encounters a tenant whose `filesystemConfig.adapter_dsn` is null/missing, throw `Tenancy\Bundle\Exception\MissingFilesystemConfigException extends \LogicException`. Mirrors the Phase 23 WR-01 pattern for `MissingTenantProviderException`.

**Why:** Symfony Messenger's default retry strategy treats `RuntimeException` as transient. A misconfigured tenant should NOT be retried — it's a programmer error. `\LogicException` is the correct semantic and is excluded from retry.

### DEC-FILE-COMPILE-PASS — `FilesystemContractPass` compile-time guard

**Decision:** Ship `Tenancy\Bundle\DependencyInjection\Compiler\FilesystemContractPass` that:

1. **Rejects** "filesystem bootstrapper enabled (`tenancy.filesystem.enabled: true`) + `league/flysystem-bundle` not installed" — clear error pointing at the suggested install command.
2. **Rejects** "any tenant has `per_tenant_adapter` strategy + `tenancy.filesystem.allow_per_tenant_adapter: false`" (admin escape hatch for shared-hosting deploys).
3. **Verifies** every tagged service has a valid `strategy` attribute (prefix | per_tenant_adapter).

Same pattern as `MailerTransportContractPass`. Compile-time validation prevents runtime surprises.

### DEC-FILE-PRIORITY — Bootstrapper priority -30

**Decision:** Register `FilesystemBootstrapper` with `priority: -30` on the `tenancy.bootstrapper` tag. boot order: DatabaseSwitch (-0) → Doctrine (-10) → Mailer (-20) → **Filesystem (-30)**. clear() runs in reverse.

**Why:** Filesystem operations should run AFTER database is switched (so per-tenant adapter DSNs can be loaded from tenant entity), and clear() should happen BEFORE database tear-down (so per-tenant adapters can close cleanly while the EM is still alive).

**Note:** Filesystem boot() may be a no-op like Mailer — adapter selection happens live in the decorator at call time. clear() flushes the LRU adapter cache. Plan-phase researcher will confirm whether the live-read pattern from `TenantAwareTransportsDecorator` applies cleanly here.

### DEC-FILE-TEST-ADAPTER — `league/flysystem-memory` for tests

**Decision:** Integration tests use `league/flysystem-memory` (in-memory adapter) — already a transitive dep of `league/flysystem`. No network IO in tests; isolated state per test method.

`tests/Integration/Filesystem/FilesystemBootstrapperIntegrationTest` proves:
1. Tenant A writes land under tenant A's prefix and are invisible from tenant B's read (prefix mode)
2. Tenant A's per-tenant DSN routes to a distinct adapter from tenant B (per_tenant_adapter mode)
3. Untagged services bypass scoping
4. `MissingFilesystemConfigException` thrown with no retry on missing config
5. LRU cache cleared on `TenantContextCleared` event (long-running worker scenario, mirrors Phase 20's 100-tenant simulation test)

## Plan Wave Suggestion

Bootstrapper-shape phases (5 Cache, 20 Mailer) ran roughly 6-12 plans in 3-6 waves. Phase 24 is closer to 20-Mailer in scope (both modes shipping in one phase). Suggested decomposition for the planner:

- **Wave 0:** Test scaffolding — `composer require-dev league/flysystem-bundle league/flysystem-memory`, `FilesystemTestKernel`, `SpyFilesystem` if useful
- **Wave 1:** Primitives — `TenantFilesystemConfigTrait`, `AbstractTenant.filesystemConfig` column, `MissingFilesystemConfigException`, `LruFilesystemCache`
- **Wave 2:** Decorators — `FilesystemPrefixingDecorator` (prefix mode), `TenantAwareFilesystemDecorator` (per-tenant-adapter mode)
- **Wave 3:** Wiring — `FilesystemBootstrapper`, `TenancyBundle` configure/loadExtension/build, `FilesystemContractPass`
- **Wave 4:** Tests — integration suite (5 scenarios above), cache-bounded long-worker simulation
- **Wave 5:** Demo + Docs — `examples/saas/` upload page (optional — gauge complexity), `docs/user-guide/filesystem-bootstrapper.md`

Planner may collapse waves if dependencies allow. Constraint: NO plan should mix mode-specific code without a clear shared boundary (prefix-mode plans should be replaceable independent of per-tenant-adapter plans).

## Anti-Patterns to Guard Against

- **Same-namespace `use` statements** in `src/` — cs-fixer @Symfony `no_unused_imports` strips them (Phase 23 IN-05 lesson, see [[php-constraints]] memory).
- **Optional-before-required constructor params** — PHP 8.0+ deprecates `?Type $param = null` when a required param follows. Match the `?TenantProviderInterface` no-default pattern from Phase 18 gap closure (commit `31465dc`).
- **Twig rendering nested inside `state == 'resolved'` branch only** — Phase 23 INT-01 lesson. If the Profiler tab gains a filesystem subsection, render it unconditionally when the data key is defined.
- **Cross-tenant data leak via shared decorator instance** — `FilesystemPrefixingDecorator` must read `TenantContext` LIVE on every call, never cache the prefix in instance state.
- **Audit-staleness** — `/gsd:audit-milestone` for v0.4 should `git log --since=<phase-24-start>` over `canonical_refs` files before generating closure CONTEXT.md (Phase 23 CR-01 / IN-05 lesson).

## Deferred Ideas

- **`oneup/flysystem-bundle` integration** — add when demand surfaces. Keep `FilesystemBootstrapper` abstract enough that swapping the underlying bundle doesn't require rewriting decorators.
- **Profiler "Filesystem" subsection** — mirror Phase 20's D-08 mailer subsection. Out of scope if Phase 24 already runs long; defer to a v0.4 polish phase.
- **`tenancy:filesystem:migrate` console command** — bulk move existing tenant data when a user migrates from non-tenanted to tenanted storage. Out of scope; document as a manual recipe in the user guide instead.
- **CDN / public-URL signing per tenant** — application-level concern, not bundle scope. Out of scope per REQUIREMENTS.md.

## Canonical References for Downstream Agents

Read in this order:
1. **`.planning/REQUIREMENTS.md`** — BOOT-03 acceptance criteria (the contract)
2. **`24-CONTEXT.md` (this file)** — locked decisions
3. **`src/Bootstrapper/MailerBootstrapper.php`** + **`src/Mailer/TenantAwareTransportsDecorator.php`** + **`src/Mailer/TenantMailerConfigTrait.php`** + **`src/Mailer/LruTransportCache.php`** — the closest precedent
4. **`src/DependencyInjection/Compiler/MailerTransportContractPass.php`** — compile-pass pattern
5. **`src/Entity/AbstractTenant.php`** — column-addition pattern (just add `filesystemConfig`, no BC break)
6. **`league/flysystem-bundle` documentation** — `FilesystemOperator` interface, adapter configuration shape, decorator pattern (research subagent should confirm).

---

_Created: 2026-05-30_
_Created by: Claude (gsd-discuss-phase, 3 user-locked decisions + 5 pattern-inherited decisions)_
