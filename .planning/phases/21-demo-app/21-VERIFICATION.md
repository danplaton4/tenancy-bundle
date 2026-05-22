---
phase: 21-demo-app
verified: 2026-05-22T15:30:00Z
status: gaps_found
score: 4/5 must-haves verified
overrides_applied: 0
gaps:
  - truth: "bin/smoke.sh exits 0 when run against the live stack — Origin-resolver assertion always returns 404"
    status: failed
    reason: >
      The Origin-resolver smoke check (lines 41-49 of bin/smoke.sh) sends
      Host: tenancy.localhost + Origin: https://acme.tenancy.localhost.
      OriginHeaderResolver (priority 25) fires after HostResolver returns null for the
      apex domain and correctly resolves the acme tenant. However, Symfony then
      dispatches to LandlordController (the only route matching Host: tenancy.localhost),
      which has an explicit guard: if ($tenantContext->hasTenant()) throw 404.
      Because hasTenant() is now true, curl --fail receives a 404 and the script exits
      non-zero via set -euo pipefail before the grep is reached. This assertion can
      never pass while both the OriginHeaderResolver and the LandlordController guard
      coexist unchanged. This was independently confirmed by CR-02 in 21-REVIEW.md.
      Result: bin/smoke.sh will always fail in CI on the OriginHeaderResolver step.
      Success criterion 2 ("bin/smoke.sh verifies isolation — DNS-independent, works in CI")
      cannot be met until this is fixed.
    artifacts:
      - path: "examples/saas/bin/smoke.sh"
        issue: >
          Lines 41-49: Origin-resolver assertion sends Host: tenancy.localhost which
          routes to LandlordController. That controller throws 404 when hasTenant()
          is true (which it is after OriginHeaderResolver resolves acme). curl --fail
          causes set -euo pipefail to abort the script.
      - path: "examples/saas/src/Controller/LandlordController.php"
        issue: >
          Lines 28-30: Guard throws 404 if hasTenant() is true. This is correct
          behaviour for the landlord apex, but is logically incompatible with the
          smoke test that proves the Origin resolver populates tenant context on
          the apex domain. Fix must be in smoke.sh (change assertion target), not
          in the controller.
    missing:
      - >
        Replace the Origin-resolver smoke assertion (smoke.sh lines 41-49) with one
        that either: (a) sends Host: acme.tenancy.localhost + Origin: https://globex.tenancy.localhost
        and asserts HostResolver wins (Acme content returned), confirming the resolver
        priority chain works; or (b) tests the Origin path against a route that does
        NOT guard on hasTenant() (e.g., add a minimal /origin-test endpoint with no
        host constraint); or (c) accept the REVIEW.md CR-02 recommendation and rewrite
        the assertion to verify the landlord page lists all three tenants WITHOUT the
        Origin header, using it as a regression guard rather than an Origin-resolver proof.

human_verification:
  - test: "docker compose up -d --wait --build exits 0 within 2 minutes on a fresh clone"
    expected: >
      All three services (db, mailpit, php) reach healthy state within ~110s cold.
      The php service healthcheck (curl /health) passes only after app:seed-demo
      completes seeding all three tenant databases and posts.
    why_human: >
      Cannot run Docker in this sandbox. Requires a real Docker daemon, network egress
      for image pulls, and ~2 minutes of wall-clock time.
  - test: "http://acme.tenancy.localhost/ and http://globex.tenancy.localhost/ serve distinct content in a browser"
    expected: >
      Acme page: orange accent (#f97316), headline 'Acme Corporation', 3 seeded posts.
      Globex page: blue accent (#2563eb), headline 'Globex Industries', 2 seeded posts.
      Initech page: green accent (#16a34a), headline 'Initech LLC', 2 seeded posts.
      Landlord page (tenancy.localhost): lists all three tenants with subdomain links.
    why_human: >
      Requires a running Docker stack and a real browser (or curl against live :80).
      Visual appearance, browser-native *.localhost resolution, and WDT rendering
      cannot be automated in this sandbox.
  - test: "WDT Tenancy panel renders correctly on tenant and landlord pages"
    expected: >
      On acme.tenancy.localhost/: panel shows slug:acme, driver:database_per_tenant,
      connection_name:tenant, resolved_by:Tenancy\Bundle\Resolver\HostResolver, bootstrapper list.
      On tenancy.localhost/: panel renders in no-tenant state without throwing.
    why_human: >
      Requires running stack and a real browser with devtools. WDT rendering is visual
      and cannot be verified by grep.
  - test: "Mailpit shows three distinct From: addresses after POST /_demo/send-test-mail x3"
    expected: >
      http://localhost:8025/ shows three emails with From: noreply@acme.example,
      noreply@globex.example, noreply@initech.example respectively.
    why_human: >
      Requires running stack, live Mailpit UI, and actual Mailer dispatch through
      Phase 20 TenantMessageDecorator. Cannot verify SMTP behaviour without a running container.
  - test: "bin/smoke.sh (after CR-02 fix) exits 0 when run against the live stack"
    expected: >
      All assertions pass: /health ready, landlord index lists 3 slugs, each tenant
      page returns its body marker, and the fixed Origin-resolver or HostResolver
      assertion passes.
    why_human: >
      Requires running Docker stack. smoke.sh currently contains the CR-02 bug and will
      fail on the Origin assertion even if the stack is up. Must be fixed first, then
      re-run manually.
---

# Phase 21: Demo App Verification Report

**Phase Goal:** A new user runs `git clone … && cd examples/saas && docker compose up` and within 2 minutes can hit two tenant subdomains in a browser (or curl) and see isolated tenant data. The same script runs in CI on every push to `master` and blocks the merge on failure.
**Verified:** 2026-05-22T15:30:00Z
**Status:** gaps_found
**Re-verification:** No — initial verification

## Goal Achievement

### Observable Truths (from ROADMAP.md Phase 21 Success Criteria)

| #  | Truth                                                                                                         | Status      | Evidence                                                                                                   |
|----|---------------------------------------------------------------------------------------------------------------|-------------|-----------------------------------------------------------------------------------------------------------|
| 1  | `docker compose up` on a fresh clone brings up the demo; two tenant subdomains serve clearly distinct content | ? UNCERTAIN | All artifacts exist and are correctly wired. Cannot run Docker in sandbox — deferred to human verification. |
| 2  | `bin/smoke.sh` makes Host:-header curl requests and verifies isolation (DNS-independent, works in CI)        | FAILED      | Script exists, is executable, has correct structure for assertions 1-3 — but the Origin-resolver assertion (lines 41-49) will always produce a 404 due to LandlordController guard. CR-02 confirmed by code review. |
| 3  | README documents three-step fallback ladder with copy-paste snippets                                          | VERIFIED    | `## Three-step fallback` heading present; Step 1 (curl Host:), Step 2 (/etc/hosts), Step 3 (browser-native) all documented with copy-paste commands. |
| 4  | `.github/workflows/demo-smoke.yml` runs `bin/smoke.sh` on every push to master; smoke failure blocks merge   | VERIFIED    | File exists, YAML valid, triggers on push/PR to master, runs bash bin/smoke.sh, uses correct working-directory. Note: actions/checkout@v5 is valid — it matches the ci.yml convention (bumped at 12:29 before Plan 04 ran at 14:46 on same day). CR-01 from REVIEW.md is moot. |
| 5  | Demo's `composer.json` references the bundle via path repository                                              | VERIFIED    | `"type": "path"`, `"url": "../../"`, `"symlink": true`, `"danplaton4/tenancy-bundle": "0.3.x-dev"` all confirmed in examples/saas/composer.json. |

**Score:** 3/5 truths verified outright (SC3, SC4, SC5). SC1 is UNCERTAIN (Docker-dependent). SC2 is FAILED (CR-02 bug).

---

### Required Artifacts

| Artifact                                                          | Expected                                                | Status     | Details                                                                                                 |
|-------------------------------------------------------------------|---------------------------------------------------------|------------|--------------------------------------------------------------------------------------------------------|
| `examples/saas/compose.yaml`                                      | Three-service stack (db, mailpit, php)                 | VERIFIED   | mariadb:11 + mailpit:v1.20 + php (FrankenPHP build). All healthchecks present. depends_on service_healthy. Mailpit UI on 127.0.0.1:8025. MariaDB port not published. |
| `examples/saas/Dockerfile`                                        | FrankenPHP build with composer install + bundle COPY   | VERIFIED   | Repo-root build context, /srv/+/srv/demo/ layout, build-time composer install, start-period=120s healthcheck. |
| `examples/saas/Caddyfile`                                         | Wildcard vhost *.tenancy.localhost + tls internal      | VERIFIED   | `auto_https disable_redirects`, `*.tenancy.localhost, tenancy.localhost, localhost`, `tls internal`, `php_server`. |
| `examples/saas/docker/entrypoint.sh`                              | Seed-before-serve (Pitfall 5 mitigation)               | VERIFIED   | `set -euo pipefail`, `bin/console app:seed-demo --no-interaction` before `exec frankenphp run`. |
| `examples/saas/docker/php.ini`                                    | OPcache dev config                                     | VERIFIED   | `opcache.validate_timestamps=1`, `opcache.revalidate_freq=0`. |
| `examples/saas/bin/smoke.sh`                                      | DNS-independent curl smoke, exit non-zero on failure   | PARTIAL    | File exists, executable, syntactically valid (`bash -n` passes), `set -euo pipefail`, `--retry 5 --retry-all-errors`, correct body markers for SC1-3 assertions. CR-02 bug: Origin-resolver assertion (lines 41-49) will always fail — see gap. |
| `.github/workflows/demo-smoke.yml`                                | CI gate on push/PR to master                           | VERIFIED   | YAML valid, triggers on push+PR to master, working-directory set, actions/checkout@v5, timeout-minutes:10, --wait --build, log dump on failure, teardown always. |
| `examples/saas/README.md`                                         | Three-step fallback + walkthroughs                     | VERIFIED   | `## Three-step fallback` present; Profiler, Mailer, Origin, HTTPS-optional, dev-loop, security sections all present. |
| `README.md` (root)                                                | "Try the demo" pointer to examples/saas/README.md     | VERIFIED   | "Try the demo" section present, links to `examples/saas/README.md` (confirmed 2 references). |
| `examples/saas/src/Command/SeedDemoCommand.php`                   | app:seed-demo — idempotent provisioning                | VERIFIED   | CREATE DATABASE IF NOT EXISTS, GRANT ALL, boot/clear pattern (setTenant → boot → try/finally → clear), SchemaTool::updateSchema, seed posts with count guard. |
| `examples/saas/src/Controller/LandlordController.php`             | GET / on tenancy.localhost, lists all tenants          | VERIFIED   | Host constraint, landlord EM injection, findAll() DemoTenant, renders landlord/index.html.twig. |
| `examples/saas/src/Controller/TenantController.php`               | GET / on {slug}.tenancy.localhost, tenant data         | VERIFIED   | Host wildcard constraint, tenant EM injection, renders tenant/index.html.twig with posts. |
| `examples/saas/src/Entity/Landlord/DemoTenant.php`               | Extends bundle Tenant, adds brandColor                 | VERIFIED   | extends Tenant, same tenancy_tenants table, brandColor column. |
| `examples/saas/src/Entity/Tenant/Post.php`                        | Per-tenant post entity                                 | VERIFIED   | id/title/body/createdAt, App\Entity\Tenant namespace. |
| `examples/saas/src/DataFixtures/LandlordTenantsFixture.php`       | Seeds acme/globex/initech with correct data            | VERIFIED   | All three tenants, correct brand colors, mailerDsn=smtp://mailpit:1025, connectionConfig with dbname. |
| `examples/saas/composer.json`                                     | Path repo to ../../ with symlink:true                  | VERIFIED   | type:path, url:../../, symlink:true, danplaton4/tenancy-bundle:@dev. No symfony/flex in require. No doctrine-migrations. |
| `examples/saas/config/packages/tenancy.yaml`                      | driver, host, origin allow-list, strict_mode, entity  | VERIFIED   | database_per_tenant, app_domain:tenancy.localhost, strict_mode:true, tenant_entity_class:App\Entity\Landlord\DemoTenant, origin allow_list with both http:// and https:// wildcards. |
| `.gitignore` (root)                                               | Ignores examples/saas/var/ and vendor/                 | VERIFIED   | Both entries present. |

---

### Key Link Verification

| From                                     | To                                         | Via                              | Status   | Details                                                           |
|------------------------------------------|--------------------------------------------|----------------------------------|----------|------------------------------------------------------------------|
| `compose.yaml`                           | `examples/saas/Dockerfile`                | `build.context: ../../`          | VERIFIED | context:../../, dockerfile:examples/saas/Dockerfile confirmed.   |
| `docker/entrypoint.sh`                   | `SeedDemoCommand.php`                     | `bin/console app:seed-demo`      | VERIFIED | Exact command present in entrypoint.sh line 12.                  |
| `compose.yaml` php healthcheck           | `HealthController.php`                    | `curl /health`                   | VERIFIED | HEALTHCHECK in Dockerfile calls http://localhost/health.         |
| `bin/smoke.sh`                           | LandlordController + TenantController     | `curl -H "Host: ..."` headers    | PARTIAL  | Host: header routing works for landlord and tenant assertions. Origin assertion is broken (CR-02). |
| `.github/workflows/demo-smoke.yml`       | `examples/saas/bin/smoke.sh`              | `bash bin/smoke.sh`              | VERIFIED | Step "Run smoke" runs `bash bin/smoke.sh`.                       |
| `examples/saas/composer.json`            | `../../` (bundle source root)             | path repo url:../../             | VERIFIED | url:../../, symlink:true, bundle at 0.3.x-dev.                  |
| `SeedDemoCommand.php`                    | `LandlordTenantsFixture.php`              | Constructor injection            | VERIFIED | `private readonly LandlordTenantsFixture $landlordsFixture` present. |
| `SeedDemoCommand.php`                    | `BootstrapperChain` (bundle)              | boot/clear pattern               | VERIFIED | `$this->bootstrapperChain->boot($tenant)` + finally clear confirmed. |

---

### Data-Flow Trace (Level 4)

| Artifact                     | Data Variable  | Source                                     | Produces Real Data    | Status      |
|------------------------------|----------------|--------------------------------------------|-----------------------|-------------|
| `LandlordController`         | `$tenants`     | `landlordEntityManager->getRepository(DemoTenant::class)->findAll()` | MariaDB landlord DB (populated by SeedDemoCommand via LandlordTenantsFixture) | FLOWING     |
| `TenantController`           | `$posts`       | `tenantEm->getRepository(Post::class)->findAll()` | Per-tenant MariaDB DB (populated by SeedDemoCommand seedPostsFor()) | FLOWING     |
| `LandlordTenantsFixture`     | tenant rows    | `new DemoTenant()` + persist                | Hardcoded fixture data | FLOWING (seed data) |
| `SeedDemoCommand::execute()` | tenant DBs     | `CREATE DATABASE IF NOT EXISTS` via root DBAL | MariaDB root connection | FLOWING     |

All data-flow paths trace from the seed command through to controller rendering. No hollow props or disconnected data sources found.

---

### Behavioral Spot-Checks

| Behavior                                   | Command                                          | Result               | Status |
|--------------------------------------------|--------------------------------------------------|----------------------|--------|
| smoke.sh syntax valid                      | `bash -n examples/saas/bin/smoke.sh`             | exit 0               | PASS   |
| smoke.sh is executable                     | `test -x examples/saas/bin/smoke.sh`             | true                 | PASS   |
| CI workflow YAML valid                     | `python3 yaml.safe_load(...)`                    | YAML_VALID           | PASS   |
| composer.json is valid JSON                | `php -r json_decode(...)`                        | JSON_VALID           | PASS   |
| PHP syntax: all PHP files                  | `php -l *.php`                                   | No syntax errors     | PASS   |
| Root README links to demo README           | `grep -c 'examples/saas/README.md' README.md`   | 2                    | PASS   |
| Three-step fallback heading present        | `grep -c '^## Three-step fallback$' examples/saas/README.md` | 1         | PASS   |
| .gitignore has demo entries                | `grep -c 'examples/saas/var/'` etc.              | 1 each               | PASS   |
| smoke.sh origin assertion: will fail live  | Logic trace: Host: tenancy.localhost + Origin → OriginHeaderResolver resolves acme → LandlordController guard throws 404 → curl --fail → script aborts | FAIL (CR-02) | FAIL   |
| docker compose up (live)                   | Cannot run Docker in sandbox                     | N/A                  | SKIP   |

---

### Probe Execution

No `scripts/*/tests/probe-*.sh` probes declared or found for Phase 21. Step 7c: SKIPPED (no conventional probe paths; phase is a demo app, not a library or tooling phase).

---

### Requirements Coverage

| Requirement | Source Plan   | Description                                                            | Status        | Evidence                                                                                 |
|-------------|---------------|------------------------------------------------------------------------|---------------|------------------------------------------------------------------------------------------|
| DEMO-01     | 21-01 through 21-04 | Runnable two-tenant Symfony app gated on CI smoke test         | PARTIAL       | SC1 (docker compose up): UNCERTAIN (human-dependent). SC2 (smoke gate): FAILED (CR-02 bug). SC3 (README fallback ladder): VERIFIED. SC4 (CI workflow): VERIFIED. SC5 (path repo): VERIFIED. |

**DEMO-01 Acceptance Criteria breakdown:**

| Sub-criterion | Acceptance Text                                                                     | Status    | Notes                                              |
|---------------|-------------------------------------------------------------------------------------|-----------|----------------------------------------------------|
| AC-1          | FrankenPHP + Caddy + MariaDB 11 composition, single docker compose up              | UNCERTAIN | Artifacts in place; Docker run required for confirmation. |
| AC-2          | *.tenancy.localhost subdomain routing via Caddy + internal CA                      | UNCERTAIN | Caddyfile correct; browser test required.          |
| AC-3          | README documents three-step fallback ladder for Firefox/Safari/WSL2                | VERIFIED  | All three steps with copy-paste snippets confirmed. |
| AC-4          | bin/smoke.sh exercises both tenants via Host: header (DNS-independent)             | FAILED    | Script has CR-02 bug: Origin assertion always fails. Landlord + per-tenant assertions would pass if Origin block were removed/fixed. |
| AC-5          | .github/workflows/demo-smoke.yml runs smoke on every push to master; failure blocks | VERIFIED  | Workflow correctly wired. Note: actions/checkout@v5 is valid — ci.yml was already bumped to v5 on 2026-05-22 12:29 before Plan 04 ran at 14:46. REVIEW.md CR-01 is a false positive. |
| AC-6          | demo composer.json references bundle via path repository; source changes reflect   | VERIFIED  | Path repo confirmed. Bind-mount in compose.yaml for dev loop confirmed. |

---

### Anti-Patterns Found

| File                                              | Line    | Pattern                                          | Severity  | Impact                                                                                                                                     |
|---------------------------------------------------|---------|--------------------------------------------------|-----------|-------------------------------------------------------------------------------------------------------------------------------------------|
| `examples/saas/bin/smoke.sh`                      | 41-49   | Logically impossible assertion (CR-02)           | BLOCKER   | Origin-resolver assertion will always produce 404 due to LandlordController guard. Script never reaches "All smoke assertions PASSED" line. |
| `examples/saas/src/Command/SeedDemoCommand.php`   | 132-141 | Double-clear in catch+finally (WR-01)            | WARNING   | clear() called twice on bootstrapperChain on error path. Benign in current bootstrappers but incorrect by design and sets a bad template. |
| `examples/saas/src/DataFixtures/LandlordTenantsFixture.php` | 45-55 | Non-idempotent fixture — no slug uniqueness check (WR-02) | WARNING | On container restart without `down -v`, three new DemoTenant rows are inserted each time. Causes duplicate tenants after second `app:seed-demo` run. |

No unreferenced `TBD`, `FIXME`, or `XXX` debt markers found in phase-modified files.

---

### Human Verification Required

#### 1. docker compose up — Full Stack Boot

**Test:** From `examples/saas/` on a machine with Docker installed: `docker compose up -d --wait --build`
**Expected:** Exits 0 within ~110s cold / ~50s warm. All three services reach healthy state. `docker compose ps` shows all healthy.
**Why human:** Cannot run Docker daemon in this sandbox.

#### 2. Browser tenant isolation — distinct content per subdomain

**Test:** After stack is up, open `http://acme.tenancy.localhost/`, `http://globex.tenancy.localhost/`, `http://initech.tenancy.localhost/`, and `http://tenancy.localhost/` in a browser.
**Expected:** Each tenant page shows distinct brand color, name, and seeded posts. Landlord page lists all three tenants with subdomain links. No cross-tenant data leaks.
**Why human:** Visual appearance and browser-native *.localhost resolution cannot be automated from this sandbox.

#### 3. WDT / Profiler panel on tenant and landlord pages

**Test:** On `http://acme.tenancy.localhost/` click the Tenancy panel in the WDT. Then visit `http://tenancy.localhost/`.
**Expected:** Tenant page shows slug:acme, driver:database_per_tenant, resolved_by HostResolver. Landlord page shows panel in no-tenant state without error.
**Why human:** WDT rendering is visual and requires a running dev stack.

#### 4. Mailpit per-tenant From: addresses

**Test:** `curl -X POST -H "Host: acme.tenancy.localhost" http://localhost/_demo/send-test-mail` (and globex, initech). Open `http://localhost:8025/`.
**Expected:** Three emails with distinct From: addresses (noreply@acme.example, noreply@globex.example, noreply@initech.example).
**Why human:** Requires running Mailpit + live Mailer dispatch.

#### 5. bin/smoke.sh after CR-02 fix

**Test:** After fixing the Origin-resolver assertion in smoke.sh (see Gaps Summary), run `bash bin/smoke.sh` against the live stack.
**Expected:** "==> All smoke assertions PASSED" printed; exit code 0.
**Why human:** Requires running stack. Must fix CR-02 first.

---

### Gaps Summary

**1 BLOCKER — smoke gate is permanently broken (CR-02)**

The smoke script's Origin-resolver assertion (lines 41-49) is logically impossible to satisfy. It sends `Host: tenancy.localhost` and `Origin: https://acme.tenancy.localhost` to `localhost:80`. The OriginHeaderResolver correctly resolves the acme tenant from the Origin header. However, Symfony then dispatches to `LandlordController::index()` (the only route matching `Host: tenancy.localhost`), which throws a 404 because `$tenantContext->hasTenant()` is true. The `curl --fail` flag causes `set -euo pipefail` to abort the script before the body `grep` is reached. This assertion can never pass.

**Required fix:** Replace lines 41-49 of `examples/saas/bin/smoke.sh` with an assertion that is achievable. The minimal correct option (per REVIEW.md CR-02) is:

```bash
# Verify HostResolver wins over OriginHeaderResolver (priority 30 > 25)
echo "==> OriginHeaderResolver (priority check)"
body=$($CURL \
    -H "Host: acme.tenancy.localhost" \
    -H "Origin: https://globex.tenancy.localhost" \
    "$BASE/")
grep -q 'Acme Corporation' <<<"$body" || {
    echo "FAIL: HostResolver should win — acme host should serve acme content"; exit 1;
}
```

This confirms the resolver chain priority (HostResolver at 30 beats OriginHeaderResolver at 25) which is the meaningful invariant. The Origin-resolver's function is already proven by the bundle's own integration tests.

**Secondary issues (warnings, not blockers for the phase gate but should be fixed before CI runs)**

- **WR-01 Double-clear:** `SeedDemoCommand` calls `clear()` in both `catch` and `finally`. Since `finally` always runs after `return` in `catch`, `clear()` is called twice on error. Fix: remove the redundant clear calls from the `catch` block.
- **WR-02 Non-idempotent fixture:** `LandlordTenantsFixture::load()` inserts tenants unconditionally. On container restart (without `down -v`), the seed command runs again and inserts 3 more rows. Fix: add a `findOneBy(['slug' => $data['slug']])` guard before each persist call.

**CR-01 from REVIEW.md is a false positive:** The review claimed `actions/checkout@v5` does not exist. However, commit `2d5e889` (2026-05-22 12:29) bumped all GitHub-owned actions to v5 in `ci.yml` — before Plan 04 was committed at 14:46 on the same day. The demo-smoke.yml correctly uses the same version as the rest of the repo. No action required.

---

_Verified: 2026-05-22T15:30:00Z_
_Verifier: Claude (gsd-verifier)_
