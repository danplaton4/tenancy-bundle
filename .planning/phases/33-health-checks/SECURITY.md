---
phase: 33-health-checks
audited: 2026-07-06
asvs_level: 2
threats_total: 14
threats_closed: 14
threats_open: 0
status: SECURED
---

# Phase 33 Security Audit — Health Checks

**Phase:** 33 — OPS-02 Health Checks
**Audited:** 2026-07-06
**ASVS Level:** 2
**Threats Closed:** 14/14
**Threats Open:** 0

---

## Threat Verification

| Threat ID | Category | Disposition | Status | Evidence |
|-----------|----------|-------------|--------|----------|
| T-33-04 | Information Disclosure | mitigate | CLOSED | `src/Mailer/DsnSanitizer.php:32` — widened regex `#(://[^:/@]*:)[^@]+(@)#` (CR-01 fix: optional username group `[^:/@]*`, password group `[^@]+` tolerates `/`). `src/Health/HealthResponseSanitizer.php:35-36` delegates to `DsnSanitizer::REDACTION_REGEX` + `REPLACEMENT`. All four output paths sanitize: controller `ready()` (lines 96, 107, 114, 124), `fleet()` (lines 156, 192), CLI txt (lines 154, 167), CLI json (line 220), liip adapter (lines 131, 137). |
| T-33-CONTRACT | Tampering | accept | CLOSED | Accepted risk — see Accepted Risks log below. |
| T-33-03 | Tampering/Elevation | mitigate | CLOSED | `src/Health/TenantHealthChecker.php:45` — `setTenant()`. Line 48 — `healthCheck()` (never `boot()`). Line 53-55 — `finally { $this->tenantContext->clear(); }`. No path exits without clearing. |
| T-33-STATE | Tampering | mitigate | CLOSED | `src/Bootstrapper/DatabaseSwitchBootstrapper.php:56-61` — `check()` calls `close()` then `executeQuery('SELECT 1')` inside try/catch. Class docblock line 21: "This class holds no tenant-specific state." Catch branch returns `BootstrapperHealthResult::fail()` — no residual state on exception path. |
| T-33-PROP | Denial of Service | mitigate | CLOSED | `src/Bootstrapper/BootstrapperChain.php:68-72` — `healthCheck()` wraps each probe in `try { $results[] = $bootstrapper->check($tenant); } catch (\Throwable $e) { $results[] = BootstrapperHealthResult::fromException(..., $e); }`. One failing driver cannot abort the chain. |
| T-33-ENUM | Information Disclosure | accept | CLOSED | Accepted risk — see Accepted Risks log below. |
| T-33-LIVE-DOS | Denial of Service | mitigate | CLOSED | `src/Controller/TenantHealthController.php:68-75` — `live()` returns a hardcoded `JsonResponse(['status' => 'ok'], 200, ...)` with zero DB calls, no checker/provider invocations, no TenantContext touch. |
| T-33-FLEET-DOS | Denial of Service | mitigate | CLOSED | `src/Controller/TenantHealthController.php:145` — `$limit = max(1, min($rawLimit, $this->fleetMaxLimit))`. Hard cap is `fleet_max_limit` (default 200, configurable). `array_slice` at line 169 bounds the probe loop. |
| T-33-SLUG | Input Validation | mitigate | CLOSED | `config/routes/health.php:44` — `->requirements(['slug' => '[a-z0-9\-]+'])`. Unknown slug caught by `TenantNotFoundException` → 404 at controller line 106-112. Inactive slug caught by `TenantInactiveException` → sanitized 503 at lines 113-120 (CR-02 fix). |
| T-33-CLI-CTX | Tampering | mitigate | CLOSED | `src/Command/TenantHealthCommand.php` — constructor takes no `TenantContext` dependency (line 43-48: only `?TenantProviderInterface`, `TenantHealthCheckerInterface`, `HealthResponseSanitizer`). All probe calls delegate to `$this->checker->checkOne($tenant)` (lines 142, 207). No `setTenant`/`clear` calls anywhere in the command. |
| T-33-CLI-BOUND | Denial of Service | accept | CLOSED | Accepted risk — see Accepted Risks log below. |
| T-33-SC | Tampering (supply chain) | mitigate | CLOSED | `.planning/phases/33-health-checks/33-RESEARCH.md` Package Legitimacy Audit: `liip/monitor-bundle` — 13+ yrs, 9M+ installs, Approved [VERIFIED: Packagist]. `laminas/laminas-diagnostics` — 10+ yrs (ZF lineage), Approved [VERIFIED: Packagist]. `composer.json:41` — `liip/monitor-bundle` in `require-dev` only. `composer.json:65` — in `suggest` only. Not in hard `require`. CI composer audit gate: `.github/workflows/ci.yml:213`. |
| T-33-07 | Availability | mitigate | CLOSED | `src/DependencyInjection/Compiler/HealthCheckIntegrationPass.php:62` — first guard: `if (!interface_exists(\Laminas\Diagnostics\Check\CheckInterface::class)) { return; }`. Line 70 — second guard (CR-03 fix): `if (!$container->hasDefinition('tenancy.provider') && !$container->hasAlias('tenancy.provider')) { return; }`. Both guards must pass before any service registration. |
| T-33-CTX | Tampering | mitigate | CLOSED | `src/Health/Liip/TenantConnectivityCheck.php` — no `use` import of `TenantContext` (grep confirms zero occurrences). No `setTenant()`/`clear()` calls. Probe lifecycle delegated entirely to `$this->checker->checkOne($tenant)` (line 72). Docblock line 24-25 explicitly prohibits direct TenantContext access. |

---

## Accepted Risks Log

| Threat ID | Category | Rationale | Owner | Review |
|-----------|----------|-----------|-------|--------|
| T-33-CONTRACT | Tampering | `HealthCheckBootstrapperInterface` is a sibling read-only interface. `check()` is a read-only probe by contract — no existing bootstrapper is forced to implement it (zero BC break). No privileged surface is added. The interface imposes no mutation capability and carries no execution authority. Risk: negligible. | Phase 33 | Next security audit |
| T-33-ENUM | Information Disclosure | The fleet endpoint enumerates tenant slugs. This is intentional: the endpoint is a separate importable route file (`config/routes/health_fleet.php`, D-02) that operators must explicitly import — it is never auto-registered. Operators who need to hide the roster can omit the fleet route file entirely. The endpoint is bounded by cap+pagination (D-08) to prevent unbounded enumeration in a single request. Risk: operator-controlled exposure. | Phase 33 | Next security audit |
| T-33-CLI-BOUND | Denial of Service | `tenancy:health --all` is deliberately unbounded (D-09). This is an explicit operator action run interactively or in a CI/batch context — not an auto-fired probe. Bounding CLI iteration would reduce its operational utility for fleet-wide health checks. The HTTP fleet endpoint has a hard cap; the CLI does not need one because it is not exposed to unauthenticated callers. Risk: operator-controlled. | Phase 33 | Next security audit |

---

## Unregistered Flags

`33-05-SUMMARY.md` `## Threat Flags` section declares no new security-relevant surface beyond what was declared in the plan threat model:

> "No new security-relevant surface beyond what was declared in the plan's threat model."

No unregistered flags to record.

---

## Code Review Security Findings (Resolved)

The following BLOCKER-class security defects were identified by the code reviewer (33-REVIEW.md) and resolved before this audit:

| Finding | Threat Mapping | Resolution Commit |
|---------|---------------|-------------------|
| CR-01: DSN regex leaked password-only (`redis://:pw@`) and slash-containing passwords | T-33-04 | `3223f77` — `REDACTION_REGEX` widened to `#(://[^:/@]*:)[^@]+(@)#` with 3 regression tests |
| CR-02: `ready()` did not catch `TenantInactiveException` (stock 403 page, bypassing sanitizer) | T-33-SLUG | `dc0bcef` — explicit `catch (TenantInactiveException)` → sanitized 503 health+json body |
| CR-03: `HealthCheckIntegrationPass` referenced `tenancy.provider` unconditionally (breaks liip-present + Doctrine-absent) | T-33-07 | `5172e4c` — second guard added: `hasDefinition('tenancy.provider') \|\| hasAlias('tenancy.provider')` |

All three BLOCKERs are verified CLOSED in this audit (see Threat Verification table above).

---

## Notes

- ASVS Level 2 requirements verified: input validation (slug route requirement), output encoding (HealthResponseSanitizer on all output paths), error handling (per-probe try/catch, finally-clear), supply chain (audit CI gate), optional-dependency isolation (two-guard compiler pass).
- Advisory warnings WR-03 (worst-output extraction duplication), WR-04 (unbounded liip check), WR-05 (silent null-provider in fleet), IN-01/IN-03/IN-04 are non-security quality concerns not mapped to threat IDs and not blocking phase shipment.
