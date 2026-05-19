# Security Verification Report

**Phase:** 10 — dependency-compatibility-audit
**ASVS Level:** 1
**Verification Date:** 2026-04-10
**Auditor:** gsd-secure-phase

---

## Summary

**Threats Closed:** 6/6
**Threats Open:** 0/6
**Unregistered Flags:** 0

All six threats in the phase threat register are closed. Five `mitigate` threats have confirmed implementation evidence. One `accept` threat (T-10-06) is documented in the accepted risks log below.

---

## Threat Verification

| Threat ID | Category | Disposition | Status | Evidence |
|-----------|----------|-------------|--------|----------|
| T-10-01 | Information Disclosure | mitigate | CLOSED | `src/TenancyBundle.php:144` `interface_exists(MessageBusInterface::class)`; `src/DependencyInjection/Compiler/MessengerMiddlewarePass.php:28` `interface_exists(\Symfony\Component\Messenger\MessageBusInterface::class)`; `no-doctrine` job present at `.github/workflows/ci.yml:92` |
| T-10-02 | Denial of Service | mitigate | CLOSED | All 11 Symfony constraints read `^7.4\|\|^8.0` in `composer.json` lines 22-29, 39-41; zero `^7.0` patterns remain |
| T-10-03 | Denial of Service | mitigate | CLOSED | `phpunit.xml.dist:23` `<env name="SYMFONY_DEPRECATIONS_HELPER" value="max[direct]=0"/>` |
| T-10-04 | Information Disclosure | mitigate | CLOSED | `no-messenger` job at `.github/workflows/ci.yml:132` removes `symfony/messenger` and runs unit tests excluding `tests/Unit/Messenger`; guard at `src/DependencyInjection/Compiler/MessengerMiddlewarePass.php:28` confirmed present |
| T-10-05 | Denial of Service | mitigate | CLOSED | `prefer-lowest` job at `.github/workflows/ci.yml:112` uses `--prefer-lowest --prefer-stable` with `SYMFONY_REQUIRE: '7.4.*'` at line 128 |
| T-10-06 | Denial of Service | accept | CLOSED | See accepted risks log below |

---

## Accepted Risks Log

### T-10-06 — DoctrineBundle 3.x API change breaks Symfony 8 path

| Field | Value |
|-------|-------|
| **Threat ID** | T-10-06 |
| **Category** | Denial of Service |
| **Component** | DoctrineBundle 3.x integration on Symfony 8.0 path |
| **Accepted By** | Phase 10 plan author (10-02-PLAN.md) |
| **Acceptance Date** | 2026-04-10 |
| **Rationale** | DoctrineBundle 3.x API is stable; breaking changes are unlikely. The existing `tests` job matrix include entry (`php: '8.4'`, `symfony: '8.0.*'` at `.github/workflows/ci.yml:21-22`) exercises this path on every CI run. Composer platform resolution automatically selects DoctrineBundle 3.x on PHP 8.4 + Symfony 8.0, so any breaking change would be caught immediately by CI before it reaches a release. Risk is low and continuously monitored. |
| **Residual Risk** | Low — CI covers this path on every push/PR. |
| **Review Trigger** | Any DoctrineBundle 3.x minor or patch release that changes public API surfaces used by the bundle (entity manager registration, connection wrappers). |

---

## Unregistered Threat Flags

None. Neither 10-01-SUMMARY.md nor 10-02-SUMMARY.md contains a `## Threat Flags` section with unregistered flags. The 10-02-SUMMARY.md `Threat Surface Scan` section explicitly states no new network endpoints, auth paths, file access patterns, or schema changes were introduced.

---

## Evidence Reference

| File | Verified Pattern | Line(s) |
|------|-----------------|---------|
| `composer.json` | `^7.4\|\|^8.0` for all 11 Symfony packages | 22-29, 39-41, 48 |
| `composer.json` | Zero occurrences of `^7.0` | — (grep confirms 0 matches) |
| `phpunit.xml.dist` | `SYMFONY_DEPRECATIONS_HELPER` value `max[direct]=0` | 23 |
| `src/TenancyBundle.php` | `interface_exists(MessageBusInterface::class)` guard before `MessengerMiddlewarePass` registration | 144 |
| `src/DependencyInjection/Compiler/MessengerMiddlewarePass.php` | `interface_exists(\Symfony\Component\Messenger\MessageBusInterface::class)` early return | 28 |
| `.github/workflows/ci.yml` | `no-doctrine` job (Doctrine guard CI) | 92 |
| `.github/workflows/ci.yml` | `prefer-lowest` job with `--prefer-lowest --prefer-stable` and `SYMFONY_REQUIRE: '7.4.*'` | 112, 126, 128 |
| `.github/workflows/ci.yml` | `no-messenger` job with `composer remove --dev symfony/messenger` | 132, 147 |
| `.github/workflows/ci.yml` | Symfony 8.0 include entry (`php: '8.4'`, `symfony: '8.0.*'`) | 21-22 |
