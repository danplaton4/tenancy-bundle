---
phase: 02-tenant-resolution
verified: 2026-03-18T22:00:00Z
status: passed
score: 18/18 must-haves verified
re_verification: false
---

# Phase 2: Tenant Resolution Verification Report

**Phase Goal:** Every production identification pattern (subdomain, custom domain, HTTP header, query param, CLI flag) resolves to an active tenant, and developers can inject custom resolvers without touching bundle internals
**Verified:** 2026-03-18T22:00:00Z
**Status:** passed
**Re-verification:** No — initial verification

---

## Scope Note: Custom Domain (RESV-01)

RESV-01 reads "subdomain *or* full custom domain (`tenant.com`)". The phase CONTEXT.md and RESEARCH.md both explicitly record that full custom-domain resolution (Tenant.domain column lookup) is **deferred to a future phase** — a deliberate, documented scope decision. The plans, SUMMARY files, and 02-02-PLAN.md all carry this deferred-scope marker. The phase-level ROADMAP Success Criterion 1 echoes the same wording ("tenant.app.com or tenant.com") but the planning artefacts override the requirement with an explicit deferral. This verification treats the in-scope portion (subdomain resolution) as satisfying RESV-01 for Phase 2, with the custom-domain path flagged below for awareness.

---

## Observable Truths

| # | Truth | Status | Evidence |
|---|-------|--------|----------|
| 1 | ResolverChain iterates resolvers in priority order and returns the first non-null result | VERIFIED | `ResolverChain::resolve()` iterates `$this->resolvers`, returns first non-null with `resolvedBy` FQCN (line 29-38, ResolverChain.php) |
| 2 | ResolverChain throws TenantNotFoundException when all resolvers return null | VERIFIED | Line 40: `throw new TenantNotFoundException('No resolver could identify a tenant...')` |
| 3 | ResolverChainPass collects tenancy.resolver tagged services using PriorityTaggedServiceTrait | VERIFIED | `ResolverChainPass.php` uses `PriorityTaggedServiceTrait` and calls `findAndSortTaggedServices('tenancy.resolver', ...)` |
| 4 | Custom resolvers implementing TenantResolverInterface are auto-tagged via registerForAutoconfiguration | VERIFIED | `TenancyBundle::loadExtension()` calls `registerForAutoconfiguration(TenantResolverInterface::class)->addTag('tenancy.resolver')` |
| 5 | A request to acme.app.com resolves slug 'acme' via HostResolver | VERIFIED | `HostResolver::extractSlug()` strips `.app_domain` suffix and returns last segment before it; 11 unit tests pass |
| 6 | A request to api.acme.app.com resolves slug 'acme' (multi-segment subdomain) | VERIFIED | `explode('.', $subdomain)` + `end($parts)` logic; tested in `HostResolverTest` |
| 7 | A request to www.acme.app.com resolves slug 'acme' (www stripped) | VERIFIED | `str_starts_with($host, 'www.')` + `substr($host, 4)` guard; tested |
| 8 | A request with X-Tenant-ID: acme resolves to the correct tenant via HeaderResolver | VERIFIED | `HeaderResolver::resolve()` reads `$request->headers->get(self::HEADER_NAME)` and calls `findBySlug()`; 5 unit tests |
| 9 | A request without X-Tenant-ID header returns null from HeaderResolver | VERIFIED | Null/empty guard on line 24; tested |
| 10 | A request with ?_tenant=acme resolves to the correct tenant via QueryParamResolver | VERIFIED | `QueryParamResolver::resolve()` reads `$request->query->get(self::PARAM_NAME)` and calls `findBySlug()`; 5 unit tests |
| 11 | A request without ?_tenant param returns null from QueryParamResolver | VERIFIED | Null/empty guard on line 24; tested |
| 12 | Running bin/console --tenant=acme resolves the tenant and boots context | VERIFIED | `ConsoleResolver::onConsoleCommand()` calls `findBySlug()`, `setTenant()`, `boot()`, dispatches `TenantResolved` |
| 13 | ConsoleResolver fires on ConsoleCommandEvent, not kernel.request | VERIFIED | `#[AsEventListener(event: ConsoleEvents::COMMAND, ...)]` attribute; NOT implementing `TenantResolverInterface` |
| 14 | TenantContextOrchestrator calls ResolverChain, sets tenant, boots bootstrappers, dispatches TenantResolved | VERIFIED | `onKernelRequest()` calls `resolverChain->resolve()`, then `setTenant()`, `boot()`, `dispatch(new TenantResolved(...))` |
| 15 | TenantResolved carries the FQCN of the winning resolver in resolvedBy | VERIFIED | `$result['resolvedBy']` from `ResolverChain` passed to `TenantResolved` constructor; unit test asserts `StubResolver::class` |
| 16 | DoctrineTenantProvider caches lookups and checks is_active after cache retrieval | VERIFIED | `CacheInterface::get()` callback caches raw tenant including inactive; `isActive()` checked after retrieval (line 48) |
| 17 | Integration: container compiles with all resolvers wired into ResolverChain | VERIFIED | `TenantResolutionIntegrationTest` boots `ResolverTestKernel`, asserts 3 built-in resolvers in chain |
| 18 | Integration: custom resolver implementing TenantResolverInterface is auto-tagged | VERIFIED | `testCustomResolverIsAutoTagged()` registers `DummyTenantResolver` via autoconfiguration and asserts it appears in chain |

**Score:** 18/18 truths verified

---

## Required Artifacts

| Artifact | Expected | Status | Details |
|----------|----------|--------|---------|
| `src/Resolver/TenantResolverInterface.php` | HTTP resolver contract | VERIFIED | `resolve(Request $request): ?TenantInterface` |
| `src/Resolver/ResolverChain.php` | Chain-of-responsibility implementation | VERIFIED | `final class ResolverChain`, `addResolver()`, `resolve()` returning array with `tenant`+`resolvedBy` |
| `src/Resolver/HostResolver.php` | Subdomain-based tenant resolution | VERIFIED | `final class HostResolver implements TenantResolverInterface`, full extraction algorithm |
| `src/Resolver/HeaderResolver.php` | X-Tenant-ID header based resolution | VERIFIED | `HEADER_NAME = 'X-Tenant-ID'`, delegates to `TenantProviderInterface` |
| `src/Resolver/QueryParamResolver.php` | Query parameter based resolution | VERIFIED | `PARAM_NAME = '_tenant'`, delegates to `TenantProviderInterface` |
| `src/Resolver/ConsoleResolver.php` | CLI tenant resolution via --tenant option | VERIFIED | `#[AsEventListener]` on `ConsoleEvents::COMMAND`, mergeApplicationDefinition + rebind pattern |
| `src/Provider/TenantProviderInterface.php` | Tenant lookup contract | VERIFIED | `findBySlug(string $slug): TenantInterface` |
| `src/Provider/DoctrineTenantProvider.php` | Doctrine + cache tenant provider | VERIFIED | `implements TenantProviderInterface`, `CACHE_TTL = 300`, cache-first with post-retrieval `isActive()` check |
| `src/Exception/TenantNotFoundException.php` | HTTP 404 domain exception | VERIFIED | `implements HttpExceptionInterface`, `getStatusCode(): int { return 404; }` |
| `src/Exception/TenantInactiveException.php` | HTTP 403 domain exception | VERIFIED | `implements HttpExceptionInterface`, `getStatusCode(): int { return 403; }` |
| `src/DependencyInjection/Compiler/ResolverChainPass.php` | Compiler pass for resolver chain assembly | VERIFIED | `use PriorityTaggedServiceTrait`, `findAndSortTaggedServices('tenancy.resolver', ...)`, `addMethodCall('addResolver', ...)` |
| `src/EventListener/TenantContextOrchestrator.php` | Fully wired kernel.request handler | VERIFIED | `private readonly ResolverChain $resolverChain`, `resolverChain->resolve()` call, Phase 1 stub removed |
| `tests/Integration/TenantResolutionIntegrationTest.php` | End-to-end integration tests | VERIFIED | 371 lines, 9 test methods covering chain wiring, resolver order, autoconfiguration, ConsoleResolver, config defaults |
| `tests/Unit/Resolver/HostResolverTest.php` | Subdomain extraction unit tests | VERIFIED | 187 lines, 11 test methods (min_lines: 60 — exceeded) |
| `tests/Unit/Resolver/HeaderResolverTest.php` | Header resolution unit tests | VERIFIED | 87 lines, 5 test methods (min_lines: 40 — exceeded) |
| `tests/Unit/Resolver/QueryParamResolverTest.php` | Query param resolution unit tests | VERIFIED | 87 lines, 5 test methods (min_lines: 40 — exceeded) |
| `tests/Unit/Resolver/ConsoleResolverTest.php` | Console resolution unit tests | VERIFIED | 209 lines, 5 test methods (min_lines: 60 — exceeded) |

---

## Key Link Verification

| From | To | Via | Status | Details |
|------|----|-----|--------|---------|
| `ResolverChainPass.php` | `ResolverChain.php` | `addMethodCall('addResolver')` | WIRED | Line 28: `$definition->addMethodCall('addResolver', [$resolver])` |
| `TenancyBundle.php` | `ResolverChainPass.php` | `build()` registers compiler pass | WIRED | Line 64: `$container->addCompilerPass(new ResolverChainPass())` |
| `TenancyBundle.php` | `TenantResolverInterface.php` | `registerForAutoconfiguration` | WIRED | Lines 48-49: `registerForAutoconfiguration(TenantResolverInterface::class)->addTag('tenancy.resolver')` |
| `HostResolver.php` | `TenantProviderInterface.php` | constructor injection, `findBySlug()` | WIRED | Line 31: `$this->tenantProvider->findBySlug($slug)` |
| `HeaderResolver.php` | `TenantProviderInterface.php` | constructor injection, `findBySlug()` | WIRED | Line 29: `$this->tenantProvider->findBySlug($slug)` |
| `QueryParamResolver.php` | `TenantProviderInterface.php` | constructor injection, `findBySlug()` | WIRED | Line 29: `$this->tenantProvider->findBySlug((string) $slug)` |
| `ConsoleResolver.php` | `TenantProviderInterface.php` | constructor injection, `findBySlug()` | WIRED | Line 55: `$this->tenantProvider->findBySlug((string) $slug)` |
| `ConsoleResolver.php` | `TenantContext.php` | constructor injection, `setTenant()` | WIRED | Line 56: `$this->tenantContext->setTenant($tenant)` |
| `ConsoleResolver.php` | `BootstrapperChain.php` | constructor injection, `boot()` | WIRED | Line 57: `$this->bootstrapperChain->boot($tenant)` |
| `TenantContextOrchestrator.php` | `ResolverChain.php` | constructor injection, `resolve()` | WIRED | Line 39: `$result = $this->resolverChain->resolve($event->getRequest())` |
| `TenantContextOrchestrator.php` | `TenantContext.php` | `setTenant()` | WIRED | Line 41: `$this->tenantContext->setTenant($result['tenant'])` |
| `TenantContextOrchestrator.php` | `TenantResolved.php` | `dispatch(new TenantResolved(...))` | WIRED | Lines 43-45: constructs and dispatches `TenantResolved` with tenant, request, resolvedBy |
| `config/services.php` | `ResolverChain` | `tenancy.resolver_chain` service + alias | WIRED | Lines 32-34: set + alias, injected into `TenantContextOrchestrator` on line 74 |
| `config/services.php` | `DoctrineTenantProvider` | `tenancy.provider` service + alias | WIRED | Lines 51-57: all three HTTP resolvers receive `service('tenancy.provider')` |

---

## Requirements Coverage

| Requirement | Source Plan | Description | Status | Evidence |
|-------------|------------|-------------|--------|----------|
| RESV-01 | 02-02 | HostResolver identifies tenant from subdomain (tenant.app.com) | SATISFIED (subdomain) | `HostResolver` fully implemented, 11 unit tests, integration test; **custom domain deferred per CONTEXT.md** |
| RESV-02 | 02-03 | HeaderResolver identifies tenant from X-Tenant-ID header | SATISFIED | `HeaderResolver` implemented, `HEADER_NAME = 'X-Tenant-ID'`, 5 unit tests |
| RESV-03 | 02-03 | QueryParamResolver identifies tenant from ?_tenant= param | SATISFIED | `QueryParamResolver` implemented, `PARAM_NAME = '_tenant'`, 5 unit tests |
| RESV-04 | 02-04 | ConsoleResolver identifies tenant from --tenant CLI option | SATISFIED | `ConsoleResolver` listens `ConsoleEvents::COMMAND`, full boot orchestration, 5 unit tests |
| RESV-05 | 02-01, 02-05 | Resolver chain is configurable; custom resolvers via TenantResolverInterface + DI tag priority | SATISFIED | `registerForAutoconfiguration`, `ResolverChainPass` with `PriorityTaggedServiceTrait`, integration test `testCustomResolverIsAutoTagged` |

**Orphaned requirements check:** RESV-06 (`OriginHeaderResolver`) appears in REQUIREMENTS.md but is not assigned to Phase 2 — no Phase 2 plan claims it. It is a future-phase item and is not orphaned within this phase scope.

---

## Anti-Patterns Found

| File | Line | Pattern | Severity | Impact |
|------|------|---------|----------|--------|
| — | — | — | — | No anti-patterns found in any phase 02 source file |

All phase 02 source files were scanned for TODO/FIXME/XXX/HACK/PLACEHOLDER markers, stub return patterns (`return null`, `return []`, `return {}`), and console-log-only implementations. None found.

The Phase 1 stub comment ("Phase 2 will inject ResolverChain here") has been confirmed removed from `TenantContextOrchestrator.php`.

---

## Human Verification Required

### 1. Custom domain resolution (RESV-01 partial)

**Test:** Configure a tenant with a custom domain (e.g. `acme-corp.com`) in the `domain` column of the Tenant entity. Send a request to `acme-corp.com` and observe whether the bundle resolves it to the correct tenant.
**Expected:** Per the explicit deferral in `02-CONTEXT.md` and `02-RESEARCH.md`, this will NOT resolve in Phase 2. A future phase will implement `Tenant.domain` column lookup.
**Why human:** Requires a running database, DNS/hosts file configuration, and an actual HTTP request; cannot be verified programmatically from the codebase alone. This is flagged for awareness, not as a blocker, since the deferral is intentional and documented.

### 2. ConsoleResolver --tenant option end-to-end

**Test:** In a Symfony application using TenancyBundle, run `bin/console some:command --tenant=acme` against a live database.
**Expected:** The tenant with slug `acme` is resolved, its bootstrappers run, `TenantResolved` is dispatched with `resolvedBy = ConsoleResolver::class`.
**Why human:** Requires a running kernel with a real Doctrine EM, cache, and console command; the unit tests mock all dependencies and cannot verify the rebind/bind sequence against a real Symfony application.

### 3. Priority ordering in chain under real DI compilation

**Test:** Boot a real application (with Doctrine configured), retrieve the `ResolverChain` service, and inspect its `$resolvers` array.
**Expected:** `[HostResolver, HeaderResolver, QueryParamResolver]` in that order (priorities 30, 20, 10).
**Why human:** The integration test uses a minimal test kernel with `ReplaceTenancyProviderPass`. The priority test (`testResolverChainResolverPriorityOrder`) does verify this, but with a synthetic provider — human spot-check on a real app would confirm no DI compilation surprises.

---

## Gaps Summary

No gaps. All 18 observable truths are verified against the actual codebase. All artifacts exist with substantive implementations and are properly wired. All five requirement IDs (RESV-01 through RESV-05) are covered. The partial custom-domain scope for RESV-01 is a deliberate, documented deferral — not a gap introduced by incomplete implementation.

---

_Verified: 2026-03-18T22:00:00Z_
_Verifier: Claude (gsd-verifier)_
