---
phase: 21-demo-app
reviewed: 2026-05-22T00:00:00Z
depth: standard
files_reviewed: 39
files_reviewed_list:
  - .github/workflows/demo-smoke.yml
  - .gitignore
  - README.md
  - examples/saas/.dockerignore
  - examples/saas/.env
  - examples/saas/.env.example
  - examples/saas/.gitignore
  - examples/saas/Caddyfile
  - examples/saas/Dockerfile
  - examples/saas/README.md
  - examples/saas/bin/console
  - examples/saas/bin/smoke.sh
  - examples/saas/compose.yaml
  - examples/saas/composer.json
  - examples/saas/config/bundles.php
  - examples/saas/config/packages/doctrine.yaml
  - examples/saas/config/packages/framework.yaml
  - examples/saas/config/packages/mailer.yaml
  - examples/saas/config/packages/tenancy.yaml
  - examples/saas/config/packages/twig.yaml
  - examples/saas/config/packages/web_profiler.yaml
  - examples/saas/config/routes.yaml
  - examples/saas/docker/entrypoint.sh
  - examples/saas/docker/php.ini
  - examples/saas/public/index.php
  - examples/saas/src/Command/SeedDemoCommand.php
  - examples/saas/src/Controller/DemoMailController.php
  - examples/saas/src/Controller/HealthController.php
  - examples/saas/src/Controller/LandlordController.php
  - examples/saas/src/Controller/TenantController.php
  - examples/saas/src/DataFixtures/LandlordTenantsFixture.php
  - examples/saas/src/Entity/Landlord/DemoTenant.php
  - examples/saas/src/Entity/Tenant/Post.php
  - examples/saas/src/Kernel.php
  - examples/saas/src/Repository/PostRepository.php
  - examples/saas/templates/base.html.twig
  - examples/saas/templates/landlord/index.html.twig
  - examples/saas/templates/tenant/index.html.twig
  - phpstan.neon
findings:
  critical: 3
  warning: 2
  info: 3
  total: 8
status: issues_found
---

# Phase 21: Code Review Report

**Reviewed:** 2026-05-22T00:00:00Z
**Depth:** standard
**Files Reviewed:** 39
**Status:** issues_found

## Summary

Reviewed the `examples/saas/` demo app and its supporting CI workflow. The implementation is solid overall — correct Docker layering, idempotent seed command design, appropriate security posture for a localhost demo, and accurate README docs. Three blockers found that will cause the CI smoke pipeline to fail immediately: one GitHub Actions action version that does not exist, one logical conflict that makes a smoke assertion permanently fail, and one class-loading failure if `APP_ENV` is ever set to anything other than `dev`. Two warnings cover a double-clear on exception path and fixture non-idempotency. Three info items cover CSS context escaping, committed credential defaults, and identical `.env`/`.env.example` files.

## Critical Issues

### CR-01: `actions/checkout@v5` does not exist — CI workflow will always fail

**File:** `.github/workflows/demo-smoke.yml:18`
**Issue:** The checkout step references `actions/checkout@v5`. As of the review date the latest published release is `v4`; `v5` does not exist. GitHub Actions will fail at the "Checkout" step with a "Unable to resolve action `actions/checkout@v5`" error, blocking every push and PR on `master`.
**Fix:**
```yaml
- name: Checkout
  uses: actions/checkout@v4
```

---

### CR-02: Origin-resolver smoke assertion always produces a 404 — CI smoke test is permanently broken

**File:** `examples/saas/bin/smoke.sh:43-49`
**Issue:** The smoke test sends `Host: tenancy.localhost` (the landlord apex domain) alongside `Origin: https://acme.tenancy.localhost` and asserts the response body contains "Acme Corporation". The resolver chain processes `HostResolver` (priority 30) first; it returns null for the apex domain (no slug label). `OriginHeaderResolver` (priority 25) fires next and correctly resolves "acme" from the `Origin` header, setting `TenantContext` to the acme tenant. The Symfony router then dispatches to `LandlordController::index()` (only route matching `Host: tenancy.localhost`). That controller has an explicit guard on line 28:

```php
if ($tenantContext->hasTenant()) {
    throw $this->createNotFoundException('Unexpected tenant resolved on landlord domain.');
}
```

Because `hasTenant()` is now true, a 404 is returned. `bin/smoke.sh` uses `curl --fail`, so `body=$($CURL ...)` exits non-zero via `set -euo pipefail`, terminating the script before the `grep` is even reached. The assertion can never pass while both the OriginHeaderResolver and the landlord guard coexist.

The correct way to demonstrate the Origin-resolver is to send the request to a tenant subdomain host (where `TenantController` renders), not to the landlord apex. For example:

```bash
# Option A: hit the tenant subdomain (HostResolver resolves it directly;
# no need for Origin header at all)
body=$($CURL -H "Host: acme.tenancy.localhost" "$BASE/")
grep -q 'Acme Corporation' <<<"$body" || { echo "FAIL"; exit 1; }

# Option B: hit the landlord page WITHOUT a tenant Origin to assert the landlord
# path is NOT affected by the Origin resolver (regression guard for the guard itself)
body=$($CURL -H "Host: tenancy.localhost" "$BASE/")
grep -q 'tenancy.localhost' <<<"$body" || { echo "FAIL: landlord page missing"; exit 1; }
```

If the intent is truly to demonstrate that `Origin` resolves a tenant even when `Host` is the apex domain, the `LandlordController` guard must be relaxed (or a separate route without the guard added), but that would be a design change requiring a plan-level decision.

**Fix (minimal — removes the always-failing assertion without breaking the intent):**
```bash
# In bin/smoke.sh, replace lines 41-49 with:

# Verify that OriginHeaderResolver resolves tenant context when Host is the subdomain
echo "==> OriginHeaderResolver (tenant subdomain path)"
body=$($CURL \
    -H "Host: acme.tenancy.localhost" \
    -H "Origin: https://globex.tenancy.localhost" \
    "$BASE/")
# HostResolver wins (priority 30 > 25); Acme's content is served regardless of Origin
grep -q 'Acme Corporation' <<<"$body" || {
    echo "FAIL: HostResolver should win over OriginHeaderResolver"; exit 1;
}
```

Alternatively, rewrite the assertion to target the landlord page WITHOUT an Origin header and verify it lists all tenants (the landlord template does output "Acme Corporation" in the `{{ tenant.name }}` loop when no tenant is resolved).

---

### CR-03: `SeedDemoCommand` depends on `DoctrineFixturesBundle` classes that are only loaded in `dev`/`test`

**File:** `examples/saas/src/Command/SeedDemoCommand.php:7-11,52`
**Issue:** `SeedDemoCommand` imports and injects `LandlordTenantsFixture`, which extends `Doctrine\Bundle\FixturesBundle\Fixture`. `DoctrineFixturesBundle` is registered only for `dev` and `test` environments in `config/bundles.php:9`. The command is registered in `all` environments (no environment guard). The container entrypoint currently runs with `APP_ENV=dev` (set in `compose.yaml:48`), so the demo works today.

However, if `APP_ENV` is set to `prod` (e.g., by someone testing production-shaped behavior), the container build succeeds but `bin/console app:seed-demo` will throw a fatal error at service instantiation: `Class "Doctrine\Bundle\FixturesBundle\Fixture" not found` (or a Symfony DI exception about missing service `App\DataFixtures\LandlordTenantsFixture`). Because the entrypoint runs the seed command before starting FrankenPHP, the container will immediately crash and the healthcheck will never pass.

The simplest fix is to move the tenant seeding logic out of the fixtures class into the command itself (dropping the `Fixture` base class) and removing the `doctrine/doctrine-fixtures-bundle` dependency from `require-dev`, or alternatively restricting the command to `dev`/`test` only:

**Fix:**
```php
// Option A: restrict the command to dev/test with an env check at the top of execute()
protected function execute(InputInterface $input, OutputInterface $output): int
{
    if (!in_array($this->getApplication()?->getKernel()->getEnvironment(), ['dev', 'test'], true)) {
        $output->writeln('<error>app:seed-demo is only available in dev/test environments.</error>');
        return Command::FAILURE;
    }
    // ...
}
```

```php
// Option B (preferred for demos): inline the fixture data directly in the command
// and remove the LandlordTenantsFixture dependency entirely.
// Replace the constructor parameter and the executor block with direct persist calls.
```

## Warnings

### WR-01: Double-clear on exception path in `SeedDemoCommand`

**File:** `examples/saas/src/Command/SeedDemoCommand.php:132-141`
**Issue:** On the exception path, `catch` explicitly calls `$this->tenantContext->clear()` and `$this->bootstrapperChain->clear()` (lines 134-135), then `return Command::FAILURE`. Because `finally` always executes even after a `return` inside `catch`, lines 139-140 call `clear()` a second time. `TenantContext::clear()` is idempotent (`$this->currentTenant = null` twice is harmless). `BootstrapperChain::clear()` calls `$bootstrapper->clear()` on each bootstrapper in reverse — calling it twice means every bootstrapper's `clear()` runs twice. Whether this causes visible bugs depends on the bootstrapper implementations (e.g., if `DatabaseBootstrapper::clear()` calls `$connection->close()` twice). For the current demo the effect is benign, but it is incorrect by design and sets a bad pattern for any user who copies this command as a template.

**Fix:** Remove the redundant clear calls from the `catch` block and let `finally` handle cleanup unconditionally:
```php
} catch (\Throwable $e) {
    $io->writeln(sprintf(' <error>✗</error> %s (%s)', $tenant->getSlug(), $e->getMessage()));
    return Command::FAILURE;
} finally {
    $this->tenantContext->clear();
    $this->bootstrapperChain->clear();
}
```

---

### WR-02: `LandlordTenantsFixture::load()` is not idempotent — re-runs create duplicate tenant rows

**File:** `examples/saas/src/DataFixtures/LandlordTenantsFixture.php:13-58`
**Issue:** `SeedDemoCommand` calls `$executor->execute([$this->landlordsFixture], true)` with `append: true`. The `ORMPurger` is therefore skipped, which prevents wiping the table. However, `load()` unconditionally calls `$manager->persist(new DemoTenant(...))` for each slug without first checking whether that slug already exists. On a second run (e.g., container restart without `down -v`), three new `DemoTenant` rows are inserted, resulting in six tenants. This means `findAll()` in `LandlordController` lists six tenants and the smoke test's landlord-page assertions still pass (slugs appear twice), but the per-tenant pages will see duplicate tenants and the seed count check `$this->tenantEm->getRepository(Post::class)->count([]) === 0` still passes (because the posts are in a different DB), so duplicate seeding is silently compounded on every restart.

**Fix:**
```php
public function load(ObjectManager $manager): void
{
    $repository = $manager->getRepository(DemoTenant::class);

    foreach ($tenants as $data) {
        // Idempotency guard: skip if slug already exists
        if ($repository->findOneBy(['slug' => $data['slug']]) !== null) {
            continue;
        }
        $tenant = new DemoTenant($data['slug'], $data['name']);
        // ... setters ...
        $manager->persist($tenant);
    }

    $manager->flush();
}
```

## Info

### IN-01: `brandColor` is rendered into a CSS custom property with no CSS-context validation

**File:** `examples/saas/templates/base.html.twig:7`, `examples/saas/templates/tenant/index.html.twig:3`, `examples/saas/templates/landlord/index.html.twig:9`
**Issue:** Twig's auto-escaping applies HTML entity encoding, not CSS encoding. The pattern:
```
:root { --brand-color: {{ tenant.brandColor ?? '#0f172a' }}; }
```
and:
```html
<strong style="color: {{ tenant.brandColor ?? '#0f172a' }}">
```
allow a `brandColor` value like `red</style><script>alert(1)</script>` or `red; background: url(//evil.com)` to inject into the CSS. For this demo the values are hardcoded seed fixtures (valid hex strings) so no practical attack exists. But the `DemoTenant::setBrandColor()` setter applies no validation, and the column is `length: 7` (too short to store most injection payloads, but not a security guarantee). Flag as info because the context note designates intentional demo weaknesses as info-only.
**Fix:** Add a regex guard in the setter or restrict it in the template:
```php
public function setBrandColor(?string $c): self
{
    if (null !== $c && !preg_match('/^#[0-9a-fA-F]{3,8}$/', $c)) {
        throw new \InvalidArgumentException('brandColor must be a valid CSS hex color');
    }
    $this->brandColor = $c;
    return $this;
}
```

---

### IN-02: `.env` and `.env.example` are byte-for-byte identical

**File:** `examples/saas/.env`, `examples/saas/.env.example`
**Issue:** The `.env.example` file is intended to serve as a safe committed template showing which variables must be set, with placeholder values rather than real/default credentials. Currently both files are identical, including the actual credentials (`MARIADB_ROOT_PASSWORD=root`, `MARIADB_PASSWORD=tenancy`). A user who copies `.env.example` to set up a real deployment will copy live credentials without a prompt to change them. The README's security section does note these are demo-only credentials, but the file itself gives no hint.
**Fix:** Replace secret values in `.env.example` with obvious placeholders:
```dotenv
APP_ENV=dev
APP_SECRET=change-me-before-deploying
APP_DEBUG=1

TENANCY_DOMAIN_BASE=tenancy.localhost

MARIADB_ROOT_PASSWORD=CHANGE_ME
MARIADB_USER=tenancy
MARIADB_PASSWORD=CHANGE_ME

DATABASE_URL_LANDLORD="mysql://tenancy:CHANGE_ME@db:3306/landlord?serverVersion=mariadb-11.0.0&charset=utf8mb4"

MAILER_DSN=smtp://mailpit:1025

PORT_HTTP=80
PORT_HTTPS=443
```

---

### IN-03: `phpstan.neon` sets `maximumNumberOfProcesses: 1` — OOM workaround suppresses parallel analysis permanently

**File:** `phpstan.neon:11`
**Issue:** `parallel.maximumNumberOfProcesses: 1` was added as a fix for a PHPStan 128 MB OOM in CI (noted in the context note for phase 21-01). Forcing single-process analysis serializes all analysis work and will noticeably slow PHPStan runs as the bundle grows. This is a quality/DX concern, not a correctness issue. The underlying cause is likely that the memory limit is too low for the CI runner, not that PHPStan itself needs single-process mode.
**Fix:** Investigate raising the runner/process memory limit via `phpstan.neon` instead:
```neon
parameters:
    parallel:
        maximumNumberOfProcesses: 4   # restore parallelism
    # Or add: memory_limit: 256M
```
If the CI runner is genuinely constrained to <128 MB, document the constraint explicitly in `phpstan.neon` so future contributors understand why the setting exists.

---

_Reviewed: 2026-05-22T00:00:00Z_
_Reviewer: Claude (gsd-code-reviewer)_
_Depth: standard_
