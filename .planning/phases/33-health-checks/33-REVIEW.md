---
phase: 33-health-checks
reviewed: 2026-07-06T08:10:35Z
depth: standard
files_reviewed: 32
files_reviewed_list:
  - composer.json
  - config/routes/health.php
  - config/routes/health_fleet.php
  - config/services.php
  - src/Bootstrapper/BootstrapperChain.php
  - src/Bootstrapper/DatabaseSwitchBootstrapper.php
  - src/Command/TenantHealthCommand.php
  - src/Controller/TenantHealthController.php
  - src/DependencyInjection/Compiler/HealthCheckIntegrationPass.php
  - src/Driver/SharedDriver.php
  - src/Health/BootstrapperHealthResult.php
  - src/Health/HealthCheckBootstrapperInterface.php
  - src/Health/HealthResponseSanitizer.php
  - src/Health/HealthStatus.php
  - src/Health/Liip/TenantConnectivityCheck.php
  - src/Health/TenantHealthChecker.php
  - src/Health/TenantHealthCheckerInterface.php
  - src/Health/TenantHealthReport.php
  - src/TenancyBundle.php
  - tests/Integration/Health/HealthChecksNoLiipTest.php
  - tests/Integration/Health/HealthEndpointsIntegrationTest.php
  - tests/Integration/Health/TenantHealthCheckerProbeTest.php
  - tests/Integration/Support/MakeHealthServicesPublicPass.php
  - tests/Unit/Bootstrapper/BootstrapperChainHealthCheckTest.php
  - tests/Unit/Command/TenantHealthCommandTest.php
  - tests/Unit/Container/NullableProviderInjectionContractTest.php
  - tests/Unit/Controller/TenantHealthControllerTest.php
  - tests/Unit/DependencyInjection/Compiler/HealthCheckIntegrationPassTest.php
  - tests/Unit/Health/BootstrapperHealthResultTest.php
  - tests/Unit/Health/HealthResponseSanitizerTest.php
  - tests/Unit/Health/TenantHealthCheckerTest.php
  - tests/Unit/Health/TenantHealthReportTest.php
findings:
  critical: 3
  warning: 5
  info: 4
  total: 12
resolved:
  - CR-01
  - CR-02
  - CR-03
  - WR-01
  - WR-02
  - IN-02
status: resolved
---

# Phase 33: Code Review Report

**Reviewed:** 2026-07-06T08:10:35Z
**Depth:** standard
**Files Reviewed:** 32
**Status:** resolved (all 3 blockers + WR-01/WR-02/IN-02 fixed; remaining warnings/info advisory)

## Resolution Log (2026-07-06)

Applied during execute-phase, verified by the full suite (966 tests, PHPStan L9,
cs-fixer all clean):

- **CR-01** (`3223f77`) — DSN redaction regex widened for password-only and
  slash-containing passwords; 3 regression tests added.
- **CR-02 / WR-01 / WR-02** (`dc0bcef`) — `ready()` catches `TenantInactiveException`
  (→ sanitized 503); `fleet()` guards `findAll()` for the always-200 contract; null
  top-level `output` no longer emitted; IN-02 integration docstrings/assertions
  corrected and strengthened.
- **CR-03** (`5172e4c`) — `HealthCheckIntegrationPass` now also guards on
  `tenancy.provider` existence; negative + alias tests added.

**Remaining (advisory, not addressed — no success-criterion impact):** WR-03
(worst-output extraction triplication — redaction is already centralized so the leak
risk is closed; the remaining concern is selection-rule drift), WR-04 (unbounded liip
`check()` — documentation/bounding follow-up), WR-05 (silent null-provider in
`fleet()`), IN-01 (`DateTimeImmutable` per-result), IN-03 (FQCN vs import), IN-04
(last-probed connection left open until next `boot()`).

## Summary

Reviewed the Phase 33 health-check surface: contract layer (enum, VOs, sibling probe
interface, sanitizer), probe engine (`TenantHealthChecker`, `BootstrapperChain::healthCheck()`,
two driver `check()` implementations), HTTP controller + two route files, CLI command,
liip adapter, the `HealthCheckIntegrationPass` wiring, and the supporting tests.

The set→probe→clear-in-finally invariant (`TenantHealthChecker::checkOne`) is implemented
correctly and well-tested — context is cleared even on exception, `boot()` is never called,
and no events are dispatched during a probe. Worst-of aggregation and the liveness zero-I/O
contract are also sound.

However, three BLOCKER-class defects break stated contracts:

1. **Credential redaction leaks two real DSN shapes.** The sanitizer is the *only* scrubber
   on every health output path, and it fails to redact password-only DSNs (e.g.
   `redis://:pw@host`) and passwords containing `/`. A driver `check()` exception message
   quoting such a DSN leaks the secret to the HTTP wire, CLI stdout, and liip result strings.
2. **`ready()` does not handle `TenantInactiveException`.** An inactive tenant slug produces a
   stock 403 error page instead of the documented 404/503/200 health+json contract, and
   bypasses the sanitizer entirely.
3. **The liip compiler pass hard-references `tenancy.provider`,** which only exists when
   Doctrine ORM is installed. liip-present + Doctrine-absent breaks container compilation —
   violating the project's optional-Doctrine invariant.

The `fleet()` endpoint's "always HTTP 200" contract is also not actually enforced against an
exception from `findAll()` (see WR-01), and two integration-test docstrings assert behavior the
code does not implement.

## Critical Issues

### CR-01: DSN credential redaction leaks password-only and slash-containing passwords

**File:** `src/Health/HealthResponseSanitizer.php:34-38` (delegates to `src/Mailer/DsnSanitizer.php:28`)
**Issue:**
`HealthResponseSanitizer` is the single scrubber applied to every health output path
(controller `ready()`/`fleet()` bodies, CLI txt/json output, and liip result messages). It
delegates to `DsnSanitizer::REDACTION_REGEX = '/(:\/\/[^:\/@]+:)[^@\/]+(@)/'`. That pattern
fails to redact two credential shapes that reach these paths via driver `check()` exception
messages (`DatabaseSwitchBootstrapper::check()` line 61 and `SharedDriver::check()` line 60
both put the raw `$e->getMessage()` into the result `output`):

- **Password-only auth** (no username), e.g. Redis `AUTH`: `redis://:s3cr3tpw@cache.host` —
  the `[^:\/@]+` username group requires ≥1 char before the `:`, so nothing matches and the
  password is emitted verbatim.
- **Password containing `/`**, valid for MySQL/Postgres: `mysql://user:pa/ss@db.host/tenant` —
  the `[^@\/]+` password group stops at the first `/`, so nothing matches and the password
  leaks.

Verified empirically:
```
NOT REDACTED: redis://:s3cr3tpw@cache.host:6379
NOT REDACTED: mysql://user:pa/ss@db.host/tenant
```
Per CLAUDE.md, "a data leak across tenants is a security incident"; leaking a tenant DB/cache
password over an unauthenticated readiness endpoint is a security defect.

**Fix:** Tighten the shared redaction pattern so the username is optional and the password
group tolerates `/`. Terminate the password at `@` only:
```php
// DsnSanitizer.php
public const REDACTION_REGEX = '#(://[^:/@]*:)[^@]+(@)#';
```
`[^:/@]*` (zero-or-more) allows the empty username of `redis://:pw@`, and `[^@]+` lets the
password contain `/`. Re-verify against the composite `failover(smtp://u:p@h1 smtp://u:p@h2)`
case that the WR-07 comment claims to support, and add sanitizer test cases for
`redis://:pw@host` and `mysql://u:pa/ss@host` (both currently absent from
`HealthResponseSanitizerTest`, which is why the gap shipped).

### CR-02: `ready()` does not catch `TenantInactiveException` — inactive tenant bypasses the health contract

**File:** `src/Controller/TenantHealthController.php:100-109`
**Issue:**
`DoctrineTenantProvider::findBySlug()` throws `TenantInactiveException` for a known-but-inactive
tenant (`src/Provider/DoctrineTenantProvider.php:51-53`). `ready()` only catches
`TenantNotFoundException` (line 102). `TenantInactiveException` extends `\RuntimeException` and
implements `HttpExceptionInterface` with `getStatusCode() === 403`, so it propagates uncaught
out of the action. The result is a stock Symfony 403 error page — **not** the documented
health+json body, and **not** run through `HealthResponseSanitizer`. The readiness contract
documented in the class docblock and route file (200 pass/warn, 503 fail, 404 unknown) has no
defined behavior for an inactive tenant, and an operator polling `/ready/{slug}` for a disabled
tenant gets an unsanitized, non-health-shaped response. The CLI command handles both exceptions
(`TenantHealthCommand.php:103`) — the controller is inconsistent with it.

**Fix:** Catch `TenantInactiveException` explicitly and map it to a sanitized health+json body
with a deliberate status code (503 is the natural readiness answer — an inactive tenant is not
ready to serve):
```php
use Tenancy\Bundle\Exception\TenantInactiveException;

try {
    $tenant = $this->provider->findBySlug($slug);
} catch (TenantNotFoundException) {
    $body = $this->sanitizer->sanitizeArray([
        'status' => HealthStatus::Fail->value,
        'output' => sprintf("Tenant '%s' not found", $slug),
    ]);
    return new JsonResponse($body, 404, ['Content-Type' => self::CONTENT_TYPE]);
} catch (TenantInactiveException) {
    $body = $this->sanitizer->sanitizeArray([
        'status' => HealthStatus::Fail->value,
        'output' => sprintf("Tenant '%s' is inactive", $slug),
    ]);
    return new JsonResponse($body, 503, ['Content-Type' => self::CONTENT_TYPE]);
}
```
Add a controller unit test covering the inactive-slug path.

### CR-03: `HealthCheckIntegrationPass` hard-references `tenancy.provider`, breaking liip-present + Doctrine-absent

**File:** `src/DependencyInjection/Compiler/HealthCheckIntegrationPass.php:60-68`
**Issue:**
The pass guards only on `interface_exists(Laminas\Diagnostics\Check\CheckInterface::class)`
(liip present). When liip is present, it registers `TenantConnectivityCheck` with
`new Reference('tenancy.provider')` (line 63) — an *exception-on-invalid* reference. But
`tenancy.provider` is registered **only** when Doctrine ORM is present
(`config/services.php:68-76`, guarded by `interface_exists(EntityManagerInterface::class)`).
`TenantConnectivityCheck::__construct` also declares a **non-nullable** `TenantProviderInterface`.
Therefore, in an app that has `liip/monitor-bundle` but not `doctrine/orm`, the container
compiles a `liip_monitor.check`-tagged service pointing at a non-existent `tenancy.provider`,
producing a `ServiceNotFoundException` at compile time. This violates the project's core
optional-Doctrine invariant ("Doctrine dependencies are optional — always guard with
`class_exists()`/`interface_exists()`"). No test exercises the liip-present + Doctrine-absent
matrix cell, so it shipped undetected.

**Fix:** Only register the check when the provider service actually exists, and/or degrade the
reference:
```php
if (!interface_exists(\Laminas\Diagnostics\Check\CheckInterface::class)) {
    return;
}
// Provider only exists when Doctrine ORM is installed; the check is meaningless without it.
if (!$container->hasDefinition('tenancy.provider') && !$container->hasAlias('tenancy.provider')) {
    return;
}

$definition = new Definition(TenantConnectivityCheck::class);
$definition->setArguments([
    new Reference('tenancy.health.checker'),
    new Reference('tenancy.provider'),
    new Reference('tenancy.health.sanitizer'),
]);
```
Add an integration-test kernel that boots with liip present and Doctrine absent and asserts the
container compiles (mirrors the pattern of `HealthChecksNoLiipTest`).

## Warnings

### WR-01: `fleet()` "always HTTP 200" contract is not enforced against a `findAll()` exception

**File:** `src/Controller/TenantHealthController.php:139`
**Issue:**
`fleet()` documents (line 122-123, route file line 22-23, D-08) that it always returns HTTP 200
and "a failing tenant does NOT 503 the whole page." But line 139
(`$allTenants = null !== $this->provider ? $this->provider->findAll() : [];`) does not guard
against `findAll()` throwing. Any provider error (e.g. landlord DB down) propagates out of the
action as an unhandled 500 — the opposite of the stated always-200 dashboard contract, and the
error page is not run through the sanitizer. The `HealthEndpointsIntegrationTest`
docstring (line 316-317) even asserts the controller "returns 200 with an empty fleet" when
`findAll()` throws, which is factually incorrect — `NullTenantProvider::findAll()` throws a
`RuntimeException` that is only masked into a 500 by `handle_all_throwables`, and the test's
weak `assertNotSame(404, ...)` assertion passes on that 500. `checkOne()` per tenant cannot
throw (it wraps everything), so `findAll()` is the one uncovered failure mode.

**Fix:** Wrap the roster fetch so the dashboard degrades to an empty/erroring aggregate at 200:
```php
try {
    $allTenants = null !== $this->provider ? $this->provider->findAll() : [];
} catch (\Throwable $e) {
    $body = $this->sanitizer->sanitizeArray([
        'total' => 0, 'offset' => 0, 'limit' => $limit,
        'summary' => ['pass' => 0, 'warn' => 0, 'fail' => 0],
        'tenants' => [],
        'output' => 'Tenant roster unavailable: '.$e->getMessage(),
    ]);
    return new JsonResponse($body, 200, ['Content-Type' => self::CONTENT_TYPE]);
}
```

### WR-02: `buildReadyBody()` can emit a null top-level `output` on a failing report

**File:** `src/Controller/TenantHealthController.php:216-218`
**Issue:**
When the aggregate status is `Fail` but every component result has a null `output` (e.g. a
bootstrapper returns `BootstrapperHealthResult::fail($c, '')` upstream, or a future probe emits
Fail with no message), `extractWorstOutput()` returns null and line 217 sets
`$body['output'] = null`. This serializes as `"output": null` in the health+json body — an
IETF `output` field is meant to be a string when present. The `Fail`-with-no-output path is not
covered by any controller test.

**Fix:** Only add the key when a message is available:
```php
if (HealthStatus::Fail === $report->status) {
    $worst = $this->extractWorstOutput($report);
    if (null !== $worst) {
        $body['output'] = $worst;
    }
}
```

### WR-03: Duplicated worst-output extraction logic across three files

**File:** `src/Controller/TenantHealthController.php:241-256`, `src/Health/Liip/TenantConnectivityCheck.php:126-142`, and the CLI accumulation in `src/Command/TenantHealthCommand.php:216-226`
**Issue:**
The "prefer Fail-level output, else first non-null output, sanitize it" logic is implemented
three times with subtly different shapes: `extractWorstOutput()` (controller, does NOT
sanitize — relies on the later `sanitizeArray`), `extractSanitizedOutput()` (liip, sanitizes
inline), and an inline loop in the command (sanitizes each and joins with `; `). This
duplication is a maintenance hazard: the CR-01 redaction fix and any future change to the
worst-of selection rule must be applied in three places, and they can drift (the controller
version currently relies entirely on the outer `sanitizeArray`, so if a future caller forgets
that wrapper it leaks).

**Fix:** Add a single method to `TenantHealthReport` (e.g. `worstOutput(): ?string`) returning
the un-sanitized worst message, and have all three callers sanitize the single result. Keeps
one selection rule and one place to reason about redaction.

### WR-04: liip `check()` may be unbounded across the full tenant roster

**File:** `src/Health/Liip/TenantConnectivityCheck.php:59-70`
**Issue:**
`TenantConnectivityCheck::check()` iterates `$this->provider->findAll()` with no bound and probes
every tenant sequentially (each opening a DB connection). The HTTP fleet endpoint deliberately
clamps to `fleet_max_limit` (Pitfall 6, controller line 134) precisely to avoid a
thundering-herd / exhaustion pattern, but the liip check — which is triggered by liip's own
HTTP/CLI monitor endpoints — has no such bound. On a large tenant roster this is effectively an
unauthenticated way to force N sequential DB connects per monitor poll. The CLI `--all` is
documented as an accepted unbounded operator action; the liip check is auto-fired by a
monitoring endpoint and should not be.

**Fix:** Either document this as an accepted risk with the same rationale as CLI `--all`, or
bound the liip iteration (e.g. sample the first `fleet_max_limit` tenants, or short-circuit on
first failure). At minimum, note the exposure in the class docblock so operators firewall the
liip monitor endpoint accordingly.

### WR-05: Health services registered unconditionally can be injected with a null provider silently

**File:** `config/services.php:284-317`
**Issue:**
`tenancy.health.controller` and `tenancy.command.health` are registered unconditionally and take
`service('tenancy.provider')->nullOnInvalid()`. In a no-Doctrine app the provider resolves to
null. The command handles this (guard at `TenantHealthCommand.php:79`) and `ready()` handles it
(line 90), but `fleet()` treats a null provider as "empty fleet, HTTP 200" (line 139) with no
signal to the operator that health checks are simply non-functional because no provider is
wired. An operator hitting `/_tenancy/health` on a misconfigured deploy gets a cheerful
`{"total":0,...}` rather than any indication the provider is missing. This is a silent-degradation
quality issue, not a crash.

**Fix:** In `fleet()`, when `null === $this->provider`, include an informational `output` field
(e.g. `"No tenant provider configured."`) alongside the empty aggregate so the misconfiguration
is visible, mirroring the `ready()` null-provider branch.

## Info

### IN-01: `DateTimeImmutable` re-instantiated per result in a loop

**File:** `src/Controller/TenantHealthController.php:200`
**Issue:** `buildReadyBody()` calls `new \DateTimeImmutable()` inside the `foreach` over results,
producing a slightly different `time` per component even though they belong to one probe run.
Minor inconsistency; the IETF `time` field should reflect the check instant.
**Fix:** Compute `$now = new \DateTimeImmutable()` once before the loop and reuse its formatted value.

### IN-02: Integration-test docstrings assert behavior the code does not implement

**File:** `tests/Integration/Health/HealthEndpointsIntegrationTest.php:316-317` (and 269-272)
**Issue:** The fleet-route docstring claims the controller "returns 200 with an empty fleet"
when `findAll()` throws (it actually 500s via `handle_all_throwables` — see WR-01), and the
readiness docstring correctly notes the `TenantNotFoundException` catch "does NOT match
RuntimeException" but the test then only asserts `!== 404`, so it never verifies the intended
404/503 contract. Misleading docstrings mask CR-02 and WR-01.
**Fix:** Correct the docstrings and strengthen assertions once CR-02/WR-01 are fixed (assert the
actual expected status codes, not merely `!== 404`).

### IN-03: `TenantConnectivityCheck` uses a fully-qualified inline type instead of an import

**File:** `src/Health/Liip/TenantConnectivityCheck.php:126`
**Issue:** `private function extractSanitizedOutput(\Tenancy\Bundle\Health\TenantHealthReport $report)`
uses the FQCN inline while the rest of the file imports its types via `use`. Inconsistent with
the `@Symfony` ruleset style used elsewhere.
**Fix:** Add `use Tenancy\Bundle\Health\TenantHealthReport;` and reference it as `TenantHealthReport`.

### IN-04: Probe leaves the last-probed tenant DB connection open after the run

**File:** `src/Health/TenantHealthChecker.php:53-56`; `src/Bootstrapper/DatabaseSwitchBootstrapper.php:53-63`
**Issue:** `checkOne()`'s `finally` clears `TenantContext` but does not close the tenant DBAL
connection. `DatabaseSwitchBootstrapper::check()` does `close()` then `executeQuery('SELECT 1')`,
so after a probe an open socket to the last-probed tenant DB remains until the next `boot()`
close/reconnect. Correctness is preserved (the next real request's `boot()` forces a reconnect,
proven by `testReconnectsCleanlyAfterProbe`), so this is not a leak of tenant *state*, but in the
fleet/`--all` path it briefly holds a connection to whichever tenant was probed last. Resource
behavior is out of v1 scope; noted for awareness since it borders on the probe-safety concern.
**Fix (optional):** Consider closing the connection in the driver `check()` `finally`, or document
that the probe intentionally relies on the next `boot()` to reset the socket.

## Structural Findings (fallow)

No `<structural_findings>` block was provided with this review; no structural pre-pass to reconcile.

---

_Reviewed: 2026-07-06T08:10:35Z_
_Reviewer: Claude (gsd-code-reviewer)_
_Depth: standard_
