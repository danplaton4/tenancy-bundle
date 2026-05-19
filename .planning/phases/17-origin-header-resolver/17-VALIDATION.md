---
phase: 17
slug: origin-header-resolver
status: draft
nyquist_compliant: true
wave_0_complete: true
created: 2026-05-15
updated: 2026-05-15
---

# Phase 17 — Validation Strategy

> Per-phase validation contract for feedback sampling during execution.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | PHPUnit 11 |
| **Config file** | phpunit.xml.dist |
| **Quick run command** | `vendor/bin/phpunit --testsuite unit` |
| **Full suite command** | `vendor/bin/phpunit` |
| **Estimated runtime** | ~15 seconds full suite (unit ~5s, integration ~10s) |

---

## Sampling Rate

- **After every task commit:** Run `vendor/bin/phpunit --testsuite unit`
- **After every plan wave:** Run `vendor/bin/phpunit`
- **Before `/gsd-verify-work`:** Full suite must be green
- **Max feedback latency:** ~15 seconds

---

## Per-Task Verification Map

| Task ID | Plan | Wave | Requirement | Threat Ref | Secure Behavior | Test Type | Automated Command | File Exists | Status |
|---------|------|------|-------------|------------|-----------------|-----------|-------------------|-------------|--------|
| 17-P01-T1 | 01 | 1 | RESV-06 | — | RecordingLogger fixture records PSR-3 calls without coupling to monolog | static+structural | `php -l tests/Unit/Resolver/Support/RecordingLogger.php && grep -q 'final class RecordingLogger' tests/Unit/Resolver/Support/RecordingLogger.php` | ✅ W0 | ⬜ pending |
| 17-P01-T2 | 01 | 1 | RESV-06 | T-17-04, T-17-05, T-17-06 | OriginHeaderResolver: preflight short-circuit, parse-failure → null, mismatch warning, exception swallow semantics | static+type | `php -l src/Resolver/OriginHeaderResolver.php && vendor/bin/phpstan analyse src/Resolver/OriginHeaderResolver.php --level=9 --no-progress` | ✅ W0 | ⬜ pending |
| 17-P01-T3 | 01 | 1 | RESV-06 | T-17-04, T-17-05, T-17-06 | 10 unit cases lock the resolver's runtime contract end-to-end (D-22) | unit | `vendor/bin/phpunit --filter OriginHeaderResolverTest --no-coverage` | ✅ W0 | ⬜ pending |
| 17-P02-T1 | 02 | 1 | RESV-06 | T-17-02, T-17-03 | Compile-time guard with verbatim error messages from CONTEXT.md `<specifics>` | static+type | `php -l src/DependencyInjection/Compiler/OriginHeaderResolverConfigPass.php && vendor/bin/phpstan analyse src/DependencyInjection/Compiler/OriginHeaderResolverConfigPass.php --level=9 --no-progress` | ✅ W0 | ⬜ pending |
| 17-P02-T2 | 02 | 1 | RESV-06 | T-17-02, T-17-03 | 14 compiler-pass cases lock every invalid shape rejection + the no-op short-circuit + normalization output (D-23) | unit | `vendor/bin/phpunit --filter OriginHeaderResolverConfigPassTest --no-coverage` | ✅ W0 | ⬜ pending |
| 17-P03-T1 | 03 | 2 | RESV-06 | — | `'origin' => OriginHeaderResolver::class` reachable via ResolverChainPass short-name map | static+structural | `php -l src/DependencyInjection/Compiler/ResolverChainPass.php && grep -q "'origin' => OriginHeaderResolver::class," src/DependencyInjection/Compiler/ResolverChainPass.php` | ✅ W0 | ⬜ pending |
| 17-P03-T2 | 03 | 2 | RESV-06 | T-17-03 | TenancyBundle wires resolver service (priority 25, three args), origin config node with shorthand→map normalization, compiler pass registered unconditionally; opt-in preserved (default resolvers list unchanged) | unit+structural | `vendor/bin/phpunit --testsuite unit --no-coverage && vendor/bin/phpstan analyse src/TenancyBundle.php --level=9 --no-progress && grep -qF "'priority' => 25" src/TenancyBundle.php && grep -qF "in_array('origin'" src/TenancyBundle.php && grep -qF "->isRequired()" src/TenancyBundle.php && grep -qF "->cannotBeEmpty()" src/TenancyBundle.php` | ✅ W0 | ⬜ pending |
| 17-P04-T1 | 04 | 3 | RESV-06 | — | Integration test fixtures (StubTenant/StubTenantProvider/RecordingLogger) namespace-coherent | static | `php -l tests/Integration/Resolver/Support/StubTenant.php tests/Integration/Resolver/Support/StubTenantProvider.php tests/Integration/Resolver/Support/RecordingLogger.php` | ✅ W0 | ⬜ pending |
| 17-P04-T2 | 04 | 3 | RESV-06 | T-17-01, T-17-02, T-17-03, T-17-04, T-17-05 | 5 integration scenarios prove Plans 01+02+03 work together on a real Symfony kernel — exact match, wildcard match, preflight passthrough, mismatch warning end-to-end, empty allow-list fails at boot | integration | `vendor/bin/phpunit --filter OriginHeaderResolverIntegrationTest --no-coverage` | ✅ W0 | ⬜ pending |
| 17-P05-T1 | 05 | 3 | RESV-06 | T-17-01 | Trust Model docs section locks the verbatim spoofability caveat — adopter informed before adoption | docs+structural | `test -f docs/user-guide/origin-header-resolver.md && grep -q "Origin is a browser-locked routing hint, not an authentication credential. Pair this resolver with your real auth layer." docs/user-guide/origin-header-resolver.md` | ✅ W0 | ⬜ pending |
| 17-P05-T2 | 05 | 3 | RESV-06 | — | CHANGELOG Unreleased section enumerates new symbols | docs+structural | `awk '/^## \\[Unreleased\\]/,/^## \\[0.2.1\\]/' CHANGELOG.md \| grep -q "OriginHeaderResolverConfigPass"` | ✅ W0 | ⬜ pending |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

---

## Wave 0 Requirements

No Wave 0 stub work needed — existing PHPUnit + PHPStan + php-cs-fixer infrastructure covers all phase requirements. All `<verify>` blocks point at already-installed tools.

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|

*All phase behaviors have automated verification — the resolver class, compiler pass, bundle wiring, integration boot, docs presence, and CHANGELOG hygiene all map to a single command in the table above.*

---

## Source Coverage Audit

| Source ID | Type | Description | Covered by | Status |
|-----------|------|-------------|------------|--------|
| **GOAL** | Phase goal | SPA-friendly resolver, priority 25, trust model docs | P01 (resolver), P02 (guard), P03 (wiring), P05 (docs) | ✅ |
| **REQ-RESV-06** | Requirement | Full RESV-06 acceptance criteria | P01-P05 (all plans tag `requirements: [RESV-06]`) | ✅ |
| **SC-1** | Success criterion | Known Origin resolves the tenant | P01-T3 (unit), P04-T2 (integration) | ✅ |
| **SC-2** | Success criterion | OPTIONS preflight returns null | P01-T3 (unit), P04-T2 (integration) | ✅ |
| **SC-3** | Success criterion | Empty/invalid allow-list fails at compile time | P02-T2 (unit), P04-T2 (integration boot) | ✅ |
| **SC-4** | Success criterion | Mismatch with X-Tenant-ID logs warning, Origin wins | P01-T3 (unit), P04-T2 (integration) | ✅ |
| **SC-5** | Success criterion | Trust Model docs section | P05-T1 (docs) | ✅ |
| **D-01** | Decision | Allow-list shape (explicit + wildcard shorthand) | P02 (compiler-pass normalization), P03 (configure node) | ✅ |
| **D-02** | Decision | Port normalization | P02-T1 + P02-T2 (`testValidMixedAllowListIsNormalized`) | ✅ |
| **D-03** | Decision | http+https permitted | P02-T2 (`testThrowsOnSchemeOtherThanHttpHttps` proves only ftp etc rejected) | ✅ |
| **D-04** | Decision | Wildcard label slug | P01-T2 + P01-T3 (`testWildcardAllowListEntryResolvesViaLeftmostLabel`) | ✅ |
| **D-05** | Decision | Wildcard cardinality ≤ 1 | P02-T2 (3 cases: mid-string, multi-label, pure-star) | ✅ |
| **D-06** | Decision | No path/query/fragment | P02-T2 (3 cases: path, query, user-info) | ✅ |
| **D-07** | Decision | OPTIONS short-circuit | P01-T2 (impl) + P01-T3 (unit) + P04-T2 (integration) | ✅ |
| **D-08** | Decision | Absent/empty Origin → null | P01-T3 (2 unit tests) | ✅ |
| **D-09** | Decision | Unparseable Origin → null silently | P01-T3 (`testReturnsNullOnUnparseableOrigin`) | ✅ |
| **D-10** | Decision | TenantNotFoundException swallow / TenantInactiveException bubble | P01-T3 (2 unit tests) | ✅ |
| **D-11** | Decision | Mismatch warning structured context | P01-T3 + P04-T2 (asserts exact 4-key payload) | ✅ |
| **D-12** | Decision | LoggerInterface with NullLogger default | P01-T2 (impl) | ✅ |
| **D-13** | Decision | `'origin' => OriginHeaderResolver::class` map entry | P03-T1 | ✅ |
| **D-14** | Decision | Origin opt-in (NOT in default resolvers) | P03-T2 (defaultValue line unchanged — asserted by acceptance criterion grep) | ✅ |
| **D-15** | Decision | Compiler-pass gating (unconditional registration, self-gates) | P02-T1 + P03-T2 (build() registers; P02-T2 `testNoOpWhenOriginNotInResolvers` proves self-gate) | ✅ |
| **D-16** | Decision | Service registered only when origin opted-in (3 args) | P03-T2 | ✅ |
| **D-17** | Decision | Allow-list parameter normalized shape | P02-T1 + P02-T2 (`testValidMixedAllowListIsNormalized`) | ✅ |
| **D-18** | Decision | YAML shape (mirrors host:) | P03-T2 (configure() block) | ✅ |
| **D-19** | Decision | beforeNormalization() shorthand→map | P03-T2 (configure() callback) + P04-T2 (integration kernel loads a shorthand entry) | ✅ |
| **D-20** | Decision | Docs page in this phase (no nav wiring) | P05-T1 | ✅ |
| **D-21** | Decision | Trust Model section minimum content | P05-T1 (verbatim sentence + spoofability vectors + opt-in note) | ✅ |
| **D-22** | Decision | Unit-test file path + cases | P01-T3 | ✅ |
| **D-23** | Decision | Compiler-pass test file path + cases | P02-T2 | ✅ |
| **D-24** | Decision | Integration test file path + scenarios | P04-T2 | ✅ |
| **D-25** | Decision | Do not modify shared TestKernel | P04-T2 (uses inline OriginResolverTestKernel) | ✅ |
| **T-17-01** | Threat | Spoofed Origin from non-browser client | P05-T1 (docs Trust Model — accept-with-mitigation) | ✅ |
| **T-17-02** | Threat | Mid-string wildcard | P02-T2 + P04-T2 | ✅ |
| **T-17-03** | Threat | Empty allow-list with origin enabled | P02-T2 + P04-T2 (real-boot scenario) | ✅ |
| **T-17-04** | Threat | Origin/X-Tenant-ID mismatch | P01-T3 + P04-T2 | ✅ |
| **T-17-05** | Threat | CORS preflight | P01-T3 + P04-T2 | ✅ |
| **T-17-06** | Threat | Origin: null / unparseable | P01-T2 + P01-T3 | ✅ |

**Coverage:** 100%. No source item is unplanned.

---

## Validation Sign-Off

- [x] All tasks have `<automated>` verify or Wave 0 dependencies
- [x] Sampling continuity: no 3 consecutive tasks without automated verify
- [x] Wave 0 covers all MISSING references — none required (existing tooling)
- [x] No watch-mode flags
- [x] Feedback latency < 15s
- [x] `nyquist_compliant: true` set in frontmatter

**Approval:** approved (autonomous — planner sign-off; executor to flip Status column ⬜→✅ per task)
