---
phase: 23
slug: tech-debt-closure
name: v0.3 Tech-Debt Closure
created: 2026-05-29
discuss_mode: audit-driven (no gray areas to negotiate; scope is fixed by .planning/v0.3-MILESTONE-AUDIT.md)
canonical_refs:
  - path: .planning/v0.3-MILESTONE-AUDIT.md
    note: "Source of truth for scope. INT-01, CR-01, WR-01, IN-01..IN-05, smoke.sh mailer assertion, CHANGELOG promotion — all derive from this audit."
  - path: .planning/phases/18-tenancy-install/18-VERIFICATION.md
    note: "Source of CR-01 / WR-01..WR-04 / IN-01..IN-05 advisory findings (lines 19-50 frontmatter)."
  - path: .planning/phases/19-profiler-tab/19-VERIFICATION.md
    note: "Profiler-tab artifact map (TenantDataCollector, TenantProfilerStash, tenant.html.twig) — DX-02 surface that the INT-01 fix lives in."
  - path: .planning/phases/20-mailer-bootstrapper/20-VERIFICATION.md
    note: "BOOT-04 surface — mailer subsection D-08 originates here, integration checker found the Twig contract drift."
  - path: .planning/phases/21-demo-app/21-VERIFICATION.md
    note: "Live-stack pass 3 — sources the demo's bin/smoke.sh that needs the mailer-isolation assertion."
  - path: CHANGELOG.md
    note: "Unreleased section currently empty; needs 0.3.2 entry (Phase 21 live-stack pass 3 + AbstractTenant split) AND 0.3.3 entry (Phase 22 nikic require + Phase 23 closure)."
  - path: UPGRADE.md
    note: "Already current — has 0.3.1→0.3.2 and 0.3.2→0.3.3 sections. Do NOT touch; the closure phase only adds CHANGELOG, not UPGRADE."
  - path: .planning/REQUIREMENTS.md
    note: "RESV-06 / DEMO-01 / DOC-19 traceability checkboxes need flipping to [x] (cosmetic — complete-milestone workflow handles in archival, but doing it inline here for cleanliness)."
  - path: src/Resources/views/Collector/tenant.html.twig
    note: "Single file edit for INT-01 — restructure mailer subsection (L108-149 today) out of the resolved-only branch."
  - path: src/Profiler/TenantDataCollector.php
    note: "Reference only — verify the data shape that the new Twig structure must accommodate. No edits expected."
  - path: tests/Unit/Profiler/TenantDataCollectorMailerSectionTest.php
    note: "INT-01 test refresh — must shift from data-shape assertion to rendered-HTML assertion."
  - path: src/Resolver/{Host,Header,QueryParam,Console}Resolver.php
    note: "CR-01 — 4 read-path resolver sites. 3 of 4 currently have `= null` default; ConsoleResolver does not. Goal: consistency."
  - path: src/Command/TenantRunCommand.php
    note: "CR-01 + WR-01 + WR-04 — nullable no-default today; convert to default-null and swap RuntimeException for MissingTenantProviderException. Shell-injection trust-boundary docblock at Process::fromShellCommandline."
  - path: src/Messenger/TenantWorkerMiddleware.php
    note: "CR-01 + WR-01 + IN-05 — same dual signature/exception swap as TenantRunCommand, plus explicit `use TenantStamp` import."
  - path: tests/Unit/Container/NullableProviderInjectionContractTest.php
    note: "CR-01 — strengthen to assert `= null` default AND `?TenantProviderInterface` on all 6 sites. Currently only checks the nullable type, not the default."
  - path: tests/Integration/ZeroConfigKernelBootTest.php
    note: "IN-01..IN-04 — drop @group canary-red + class-docblock framing, fix double-removal in tearDownAfterClass, add PID to cache-dir hash."
  - path: examples/saas/bin/smoke.sh
    note: "Smoke.sh mailer assertion — extend to POST /_demo/send-test-mail per tenant and curl Mailpit /api/v1/messages for per-tenant From: isolation."
  - path: examples/saas/compose.yaml
    note: "Reference only — confirms Mailpit service + port mapping for the smoke.sh assertion."
  - path: .github/workflows/demo-smoke.yml
    note: "Reference only — CI gate runs the extended smoke.sh; no workflow edits expected (smoke.sh is the only file touched)."
---

# Phase 23 — Context

## Domain

**Audit-driven tech-debt closure before tagging v0.3.3.**

Phase 23 is a closure phase, not a feature phase. Its scope was derived from `.planning/v0.3-MILESTONE-AUDIT.md` (2026-05-29), which audited the v0.3 milestone and produced status `tech_debt` — 0 BLOCKERs, but 17 deferred items across phases 18/19/20/21, 1 cross-phase WARNING (INT-01), and pre-tag housekeeping (CHANGELOG promotion, REQUIREMENTS checkbox refresh).

Phase 23 does not deliver any new requirement. It closes deferred items so the v0.3 milestone tag (v0.3.3) ships clean.

## Decisions (locked — no gray areas to negotiate)

### INT-01 — Twig contract drift fix

**Decision:** Move the entire `{% if collector.data.mailer is defined %}` block (currently `tenant.html.twig` L108-149, nested inside `state == 'resolved'`) to a new top-level section that renders unconditionally after the resolved/error/null branching when the mailer data key exists.

**Why:** `TenantDataCollector::collectMailerState()` populates the 10-key scalar array for every request when `LruTransportCache` is wired — including null/error states. Cache hit/eviction counters are useful on landlord/health-check routes too. The current nesting hides them.

**Test refresh:** `TenantDataCollectorMailerSectionTest::testMailerKeyPresentWhenNoTenantButCacheWired` currently asserts the data array. Strengthen to assert against the rendered HTML — render via `TwigBundle::render('@Tenancy/Collector/tenant.html.twig', ['collector' => ...])` and grep the output for the mailer block. The data-array assertion stays as a finer-grained check; the new HTML assertion proves the Twig+data contract.

**Rejected:** Collector-side guard (skip populating mailer on null state). Bad because cache metrics are exactly what the operator needs to see on null state (no tenant active but cache still has connections).

### CR-01 — Nullable-provider drift guard

**Decision:** Two-part fix.

1. **Code consistency:** All 6 sites use identical signature `?TenantProviderInterface $tenantProvider = null`. Today 3/6 sites (HostResolver, HeaderResolver, QueryParamResolver) have `= null` defaults; 3/6 (ConsoleResolver, TenantRunCommand, TenantWorkerMiddleware) do not. Add `= null` to the latter three.
2. **Contract test strengthening:** `NullableProviderInjectionContractTest` already exists (added by Phase 18 gap closure). Strengthen it to assert BOTH the `?TenantProviderInterface` type AND the `= null` default value on all 6 reflection sites — currently it only checks the type.

**Why:** Consistency is the architectural goal. The container always passes null via `nullOnInvalid()`, so functionally `= null` adds no behavior — but it locks the API contract at the type level and at the test level, preventing a future contributor from dropping the default and breaking zero-config boot.

**Rejected:** PHPStan rule. Out of scope for closure phase; PHPStan rule is a maintenance liability without the right primitives. The reflection-based contract test is cheaper and covers the invariant.

### WR-01 — MissingTenantProviderException for misconfiguration

**Decision:** Introduce `Tenancy\Bundle\Exception\MissingTenantProviderException extends \LogicException`. Swap `\RuntimeException` for it in `TenantRunCommand::execute()` and `TenantWorkerMiddleware::handle()`. Update CHANGELOG `### Changed` for 0.3.3 noting the swap (no BC concern — these throw from internal sites, no user-code catches them).

**Why:** `\RuntimeException` is the wrong semantic for a permanent misconfiguration. In particular, Messenger middleware retry policy treats `RuntimeException` as a transient failure and may retry the message — pointless when the container is misconfigured. `\LogicException` is the correct semantic for "programmer/operator error" and is excluded from retry by Symfony's default retry strategy.

**Sub-class name:** `MissingTenantProviderException` — explicit, greppable, and self-documenting in stack traces. Lives under `src/Exception/`.

**Test:** Add `tests/Unit/Messenger/TenantWorkerMiddlewareTest::testMissingProviderThrowsLogicExceptionNotRuntime` + similar for TenantRunCommand. Add `tests/Integration/Messenger/RetryBehaviorTest` if Messenger's retry contract is reachable from PHPUnit (research will confirm; skip if it's not).

### WR-02, WR-03, WR-04 — Intra-bundle consistency nits

- **WR-02 (ConsoleResolver guard ordering):** Add a defensive comment block above the Application-mutation site (`addOption('--tenant', ...)`) noting that the null-guard at line 31 MUST stay above the mutation — and pin this with a comment-anchored test that fails if anyone refactors the guard below the mutation. Minimal change.
- **WR-03 (QueryParamResolver pattern alignment):** Change empty-string check from `null === $value || '' === $value` to `is_string($value) && '' !== trim($value)` to match `ConsoleResolver`'s pattern. Idiomatic and stricter (rejects whitespace-only).
- **WR-04 (TenantRunCommand shell-injection docblock):** Add a `@security` block above the `Process::fromShellCommandline($commandString)` call: "Trust boundary: caller is a developer at the CLI. `$commandString` flows from `$input->getArgument('command')` and is not escaped. Do NOT expose this command via HTTP or any context where untrusted input can reach it."

### IN-01 through IN-05 — ZeroConfigKernelBootTest cosmetics

- **IN-01:** Drop `@group canary-red` annotation + stale class-docblock framing ("MUST fail on master before plans 18-09/18-10 land"). The test is now green canary in default suite — annotation is misleading.
- **IN-02:** Replace `setCatchExceptions(false)` + diagnostic-assertion pattern with `expectException(\Symfony\Component\Console\Exception\RuntimeException::class)->expectExceptionMessage(...)` to surface a clear message on regression.
- **IN-03:** Add `getmypid()` to the cache-dir hash (`static::class . $env . getmypid()`) — prevents race on parallel-PHPUnit processes.
- **IN-04:** Remove the duplicate `Filesystem::remove($parentDir)` call in `tearDownAfterClass`'s cleanup loop. Second removal is a no-op; cosmetically misleading.
- **IN-05:** Add explicit `use Tenancy\Bundle\Messenger\TenantStamp;` import in `TenantWorkerMiddleware.php` — currently relies on same-namespace implicit resolution.

### Smoke.sh mailer assertion (Phase 21 INFO follow-up)

**Decision:** Extend `examples/saas/bin/smoke.sh` with a new section after the existing assertions:

```bash
# ==> Per-tenant mailer isolation
for slug in acme globex; do
  curl -fsS -X POST -H "Host: ${slug}.tenancy.localhost" \
    "http://localhost:${BASE_PORT}/_demo/send-test-mail" >/dev/null
done

MESSAGES=$(curl -fsS "http://127.0.0.1:${PORT_MAILPIT_UI:-8025}/api/v1/messages")
echo "$MESSAGES" | jq -e '.messages[] | select(.From.Address == "noreply@acme.example")' >/dev/null
echo "$MESSAGES" | jq -e '.messages[] | select(.From.Address == "noreply@globex.example")' >/dev/null
```

**Why:** Phase 21 verified per-tenant mailer isolation manually via Mailpit UI. A regression in `TenantMessageDecorator` (Phase 20) would NOT fail demo-smoke CI today. Adding the assertion turns Phase 21's human-UAT into a CI gate.

**Caveat:** Requires `jq` on the CI runner. GitHub Actions `ubuntu-latest` has it pre-installed; documenting this in `demo-smoke.yml` is unnecessary (not a project-specific dependency).

**Initech tenant skipped:** Smoke.sh smoke-tests two tenants; testing 3 doesn't add value beyond the 2-tenant case.

### CHANGELOG promotion (pre-tag housekeeping)

**Decision:** Add TWO new sections to `CHANGELOG.md` under `[Unreleased]`:

1. **`## [0.3.2] — 2026-05-22`** — Phase 21 live-stack pass 3 fixes (AbstractTenant split, BOOT-01..BOOT-07 docker layout fixes, demo-bundles boot path). Source: Phase 21 VERIFICATION lines 22-32 enumerate the 7 BOOT-* gap closures verbatim — copy into CHANGELOG `### Fixed` block.
2. **`## [0.3.3] — 2026-05-29`** — Phase 22 nikic require move (DEC-INST-02 reversal) + Phase 23 closure items. Source: Phase 22 SUMMARY frontmatter and this Phase 23 SUMMARY (forthcoming).

**Date convention:** Match the actual ship date for each tagged release. v0.3.2 ships under 2026-05-22 because that's when Phase 21 lifted master to its current state. v0.3.3 ships under today's date 2026-05-29 because Phase 23 closure lands today.

**Unreleased section:** Leave empty after promotion. Will gather v0.4 work.

### REQUIREMENTS.md checkbox refresh

**Decision:** Flip RESV-06, DEMO-01, DOC-19 from `[ ]` to `[x]` in `.planning/REQUIREMENTS.md`. The complete-milestone workflow handles this in archival, but doing it inline now makes the milestone-archive commit a pure file move (cleaner git history).

**Skipped:** GOV-01 (skipped status preserved). DX-06 / DX-02 / BOOT-04 already at `[x]`.

## Code Context

| Surface | File | Lines | Action |
|---------|------|-------|--------|
| Twig template | `src/Resources/views/Collector/tenant.html.twig` | L108-149 | Move mailer block out of resolved branch |
| Profiler test | `tests/Unit/Profiler/TenantDataCollectorMailerSectionTest.php` | full file | Add rendered-HTML assertion |
| Resolver consistency | `src/Resolver/ConsoleResolver.php` | constructor | Add `= null` default |
| Command consistency | `src/Command/TenantRunCommand.php` | constructor | Add `= null` default; swap exception class; @security docblock |
| Middleware consistency | `src/Messenger/TenantWorkerMiddleware.php` | constructor + use block | Add `= null` default; swap exception; `use TenantStamp` import |
| New exception | `src/Exception/MissingTenantProviderException.php` | NEW | `extends \LogicException` |
| Contract test | `tests/Unit/Container/NullableProviderInjectionContractTest.php` | full file | Strengthen to assert `= null` default |
| Resolver lint | `src/Resolver/QueryParamResolver.php` | empty-string check | Align to `is_string()` pattern |
| Resolver lint | `src/Resolver/ConsoleResolver.php` | guard site | Defensive comment + comment-anchored test |
| Canary cleanup | `tests/Integration/ZeroConfigKernelBootTest.php` | docblock + tearDown + cache-dir | IN-01/02/03/04 cleanup |
| Smoke gate | `examples/saas/bin/smoke.sh` | append at end | Mailpit jq assertion |
| Release notes | `CHANGELOG.md` | L8-9 | Add 0.3.2 + 0.3.3 sections |
| Traceability | `.planning/REQUIREMENTS.md` | traceability table | Flip 3 checkboxes |

## Plan Wave Suggestion

Phase 23 has no hard dependencies between most items — they're independent fixes. Suggested wave decomposition for the planner:

- **Wave 1 (parallel):** INT-01 + CR-01 + WR-01 + WR-02/03/04 + IN-01..05 (independent code edits)
- **Wave 2 (depends on Wave 1):** Smoke.sh mailer assertion (no code dependency, but better tested after the bundle is green) + CHANGELOG promotion + REQUIREMENTS.md checkbox refresh
- **Wave 3 (depends on Wave 2):** Full PHPUnit + PHPStan level 9 + php-cs-fixer + smoke.sh local-stack run (integration smoke against demo)

## Deferred Ideas

None. Audit-driven scope. Out-of-scope items (Mailpit-add-on docs, APM observability, parallel migrations) belong in v0.4–v0.6 per `PROJECT.md#later-milestones`.

## Universal Anti-Pattern Acknowledgments

The audit found 0 BLOCKERs and 1 cross-phase WARNING (INT-01). No universal anti-patterns are at risk in Phase 23 — it's bounded mechanical work driven by a verified audit.

---

_Created: 2026-05-29_
_Created by: Claude (gsd-discuss-phase, audit-driven mode)_
