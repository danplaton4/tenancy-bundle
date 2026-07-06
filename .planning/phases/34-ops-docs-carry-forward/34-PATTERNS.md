# Phase 34: Ops Docs & Carry-Forward - Pattern Map

**Mapped:** 2026-07-06
**Files analyzed:** 12 new/modified files
**Analogs found:** 12 / 12

---

## File Classification

| New/Modified File | Role | Data Flow | Closest Analog | Match Quality |
|---|---|---|---|---|
| `docs/ops/parallel-migrations.md` | doc-command-reference | transform | `docs/user-guide/cli-commands.md` | exact |
| `docs/ops/maintenance-mode.md` | doc-feature-reference | request-response | `docs/user-guide/mailer-bootstrapper.md` + `docs/user-guide/shared-db.md` | exact |
| `docs/ops/health-checks.md` | doc-feature-reference | request-response | `docs/user-guide/mailer-bootstrapper.md` | role-match |
| `mkdocs.yml` | config | — | `mkdocs.yml` lines 87–91 (existing group insertion pattern) | exact |
| `scripts/docs-lint.sh` | config/ci | — | `scripts/docs-lint.sh` lines 22–33, 38–42 | exact |
| `UPGRADE.md` | doc-upgrade | — | `UPGRADE.md` lines 252–353 (`0.2 → 0.3` BC-break section) | exact |
| `examples/saas/composer.json` | config | — | `examples/saas/composer.json` lines 44–51 (`config` block) | role-match |
| `examples/saas/composer.lock` | config | — | regenerated artifact (no hand-editing) | n/a |
| `docs/contributor-guide/test-infrastructure.md` | doc-policy | — | existing page (extend, not replace) | role-match |
| `.planning/phases/31-parallel-migrations/31-VALIDATION.md` | planning-artifact | — | `.planning/phases/32-maintenance-mode/32-VALIDATION.md` | exact |
| `tests/Unit/Command/SharedEntityResyncCommandTest.php` | test | request-response | existing methods in same file (lines 127–181) | exact |
| `tests/Unit/PHPStan/ExtensionInstallerContractTest.php` | test | — | `tests/Unit/PHPStan/Rule/MutualExclusionRuleTest.php` | role-match |

---

## Pattern Assignments

### `docs/ops/parallel-migrations.md` (doc-command-reference)

**Analog:** `docs/user-guide/cli-commands.md`

**Why this analog:** cli-commands.md is the canonical command reference page. It uses the exact section shape (H2 per command, Usage block, Flags table, Behavior prose, How It Works list) that the parallel-migrations page should follow. The `tenancy:migrate` section (lines 126–183) is the direct predecessor of the parallel flag documentation.

**H1 + overview pattern** (lines 1–8 of cli-commands.md):
```markdown
# CLI Commands

The bundle provides five commands: a one-shot setup command — `tenancy:install` — ...

## tenancy:install

One-command setup. ...
```
Replicate: `# Parallel Migrations` with a concise one-paragraph overview, then H2 sections.

**Flags table pattern** (lines 32–36 of cli-commands.md):
```markdown
| Flag | Effect |
|------|--------|
| `--dry-run` | Prints the proposed ... without writing either file. Safe to run on any project... |
| `--force`   | Forwarded to the delegated `tenancy:init` call so an existing ... is overwritten. |
```
Replicate with the parallel-migrations flags: `--parallel`, `--concurrency` (default 4, clamped 1–32), `--dry-run`, `--format` (txt/json), `--tenant`.

**Behavior bullet list pattern** (lines 162–171 of cli-commands.md):
```markdown
### Behavior

- **Continue on failure**: The command does not stop on the first error. ...
- **Exit code**: Returns `1` if any tenant migration failed, `0` if all succeeded.
- **No-op tenants**: Tenants with no pending migrations are silently skipped.
- **Shared-DB guard**: Running `tenancy:migrate` with `driver: shared_db` returns an error
  immediately ...
```
Replicate with parallel-migrations-specific bullets: subprocess pool, null-exit=FAILURE, `--parallel + shared_db` guard returning FAILURE before any subprocess spawns.

**Requirements admonition pattern** (lines 129–137 of cli-commands.md):
```markdown
!!! tip "Prerequisites"
    `tenancy:migrate` requires **both**:

    - `tenancy.database.enabled: true` (database-per-tenant driver)
    - `doctrine/migrations` package installed: `composer require doctrine/migrations`

    The command is silently unavailable if either requirement is missing.
```
Replicate for parallel-migrations: note that `--parallel` is a flag on the existing `tenancy:migrate` command (not a separate command), and the shared-db guard.

**See Also footer pattern** (lines 261–266 of cli-commands.md):
```markdown
## See Also

- [Installation](installation.md) — initial setup with tenancy:init
- [Database-per-Tenant](database-per-tenant.md) — connection switching mechanics
```
Replicate with links to the other two ops pages and the CLI Commands reference page.

**Target depth:** 150–220 lines (cli-commands.md is 266; the parallel-migrations page covers one command's new flags, not five commands).

---

### `docs/ops/maintenance-mode.md` (doc-feature-reference)

**Analog:** `docs/user-guide/mailer-bootstrapper.md` (structure) + `docs/user-guide/shared-db.md` (admonition idioms)

**Why this analog:** mailer-bootstrapper.md is the established "how a bootstrapper works + how to configure it + migration path" template. shared-db.md adds the `!!! danger` and `!!! note` admonition patterns. Maintenance mode maps directly: how the listener works, HTTP behavior, allow-list config, commands, BC break path.

**Page opener pattern** (lines 1–6 of mailer-bootstrapper.md):
```markdown
# Mailer Bootstrapper

Per-tenant SMTP transport ... When tenant `acme` triggers an email — whether it goes out
immediately on `kernel.terminate` or sits on a Messenger queue ... — the message is
delivered through **`acme`'s** SMTP credentials, never the landlord's, never another
tenant's.

This page covers the four things you need to know to operate the bootstrapper in
production: ...
```
Replicate: lead with the maintenance-mode value proposition (503 + Retry-After + allow-list bypass), then "this page covers: enabling/disabling, HTTP behavior, allow-list bypass, cross-cutting health-checks note, cache invalidation timing, BC break".

**How it works / section divider pattern** (lines 9–16 of mailer-bootstrapper.md):
```markdown
---

## How it works

`MailerBootstrapper` implements `TenantBootstrapperInterface` and is registered
unconditionally in the bundle's DI container, guarded by `interface_exists(...)`.
```
Replicate with `TenantMaintenanceModeListener` priority 16, firing AFTER the orchestrator at priority 20, and the content-negotiated response (HTML/JSON/Twig override).

**Danger admonition pattern** (lines 36–39 of shared-db.md):
```markdown
!!! danger "Never combine `shared_db` with `database.enabled: true`"
    Setting both `driver: shared_db` AND `database.enabled: true` is rejected at compile
    time with a clear error. ...
```
Replicate as a `!!! warning` admonition for the CDN caching risk: CDNs must not cache 503 responses; `Cache-Control: no-store, no-cache, must-revalidate` is set by the listener but operators must ensure their CDN/proxy does not override it.

**Note admonition** (lines 191–194 of shared-db.md):
```markdown
!!! note "No-op is intentional"
    If you later migrate from `shared_db` to `database_per_tenant`, add `#[Shared]` to
    your global-data entities ...
```
Replicate as a `!!! note` for the cache invalidation timing: the enable/disable commands delete the `tenancy.tenant.<slug>` cache key immediately; the effect is visible on the next request (not after the 300-second TTL).

**Configuration YAML/PHP tab pattern** (lines 17–35 of shared-db.md):
```markdown
=== "YAML"
    ```yaml
    # config/packages/tenancy.yaml
    tenancy:
        driver: shared_db
        strict_mode: true
    ```
=== "PHP"
    ```php
    // config/packages/tenancy.php
    return static function (...) { ... };
    ```
```
Replicate for the `tenancy.maintenance.*` config keys (retry_after, allow_ips, allow_routes, allow_paths) with YAML + PHP tabs.

**Target depth:** 180–240 lines. Three sections: Overview/How it works, Configuration (allow-list + commands), Runbook (deploy-time enable + operator bypass). Plus BC break migration note at the end.

**Critical content requirements (from D-03/CONTEXT):**
- HTTP 503 + `Retry-After: {seconds}` + `Cache-Control: no-store, no-cache, must-revalidate`
- Three commands: `tenancy:maintenance:enable`, `tenancy:maintenance:disable`, `tenancy:maintenance:status`
- Allow-list: `allow_ips` (IP/CIDR), `allow_routes` (exact `_route`), `allow_paths` (prefix match)
- Health probes cross-dependency: `/_tenancy/health` prefix MUST be in `allow_paths` (link to health-checks page)
- Cache invalidation timing note
- BC break: `TenantInterface::isInMaintenance(): bool` — link to UPGRADE.md `0.4 → 0.5`

---

### `docs/ops/health-checks.md` (doc-feature-reference)

**Analog:** `docs/user-guide/mailer-bootstrapper.md`

**Why this analog:** Same "how it works + configuration + operations" structure. The health-checks page has an additional requirement: embed Kubernetes probe YAML (D-03). The mailer page's separator + section headers provide the structural skeleton.

**Section skeleton to replicate** (from mailer-bootstrapper.md structure):
```markdown
# Health Checks

<one-paragraph value prop>

This page covers: opt-in route setup, endpoint contract, k8s probe YAML,
CDN caching warning, CLI command, fleet dashboard, LiipMonitor integration.

---

## How it works

## Enabling health endpoints (opt-in)

## Endpoint reference

## Kubernetes integration

## CLI: tenancy:health

## Fleet dashboard

## LiipMonitorBundle integration (optional)

## See Also
```

**Required k8s YAML block (D-03):**
```yaml
# Liveness probe — zero-I/O, cheap, poll frequently
livenessProbe:
  httpGet:
    path: /_tenancy/health/live
    port: 80
  initialDelaySeconds: 10
  periodSeconds: 10
  failureThreshold: 3

# Readiness probe — real DB SELECT 1, poll conservatively
readinessProbe:
  httpGet:
    path: /_tenancy/health/ready/my-tenant
    port: 80
  initialDelaySeconds: 15
  periodSeconds: 30
  failureThreshold: 2
```

Inline explanation of why the two probes have different `periodSeconds`: liveness is zero-I/O and completes in <1ms (aggressive polling is safe); per-tenant readiness does a real DB round-trip (~5-50ms) and would hammer the DB if polled at the same rate with a full pod fleet.

**CDN warning admonition (D-03):**
```markdown
!!! warning "CDN / proxy caching"
    Health probe 503 responses (and maintenance 503s) must not be cached by a CDN
    or reverse proxy. The endpoints set `Cache-Control: no-store` ... Ensure your
    CDN is configured to pass `Cache-Control` from origin and not apply TTL-based
    caching to 5xx responses — a cached 503 would leave a tenant permanently "down"
    after the condition clears.
```

**Endpoint table (from RESEARCH.md verified data):**
```markdown
| Endpoint | Method | Status codes | Response type |
|---|---|---|---|
| `/_tenancy/health/live` | GET | 200 | `application/health+json` `{"status":"ok"}` |
| `/_tenancy/health/ready/{slug}` | GET | 200 / 503 / 404 | `application/health+json` |
| `/_tenancy/health` (fleet) | GET | 200 | `application/health+json` paginated |
```

Note: 404 = unknown slug; 503 = known-but-unhealthy OR known-but-inactive. Fleet endpoint is NOT a k8s probe target — dashboard-only.

**Target depth:** 200–250 lines (the k8s YAML + endpoint reference + CDN warning add length beyond a plain feature page).

---

### `mkdocs.yml` (config — nav group insertion)

**Analog:** `mkdocs.yml` lines 87–91 (existing `Examples` group insertion pattern)

**Why this analog:** The existing nav groups show the exact YAML indentation and ordering expected. The new `Operations` group follows the same two-space indent, title-colon format.

**Insertion point and pattern** (lines 67–108 of mkdocs.yml):
```yaml
nav:
  - Home: index.md
  - User Guide:
    - user-guide/index.md
    - Installation: user-guide/installation.md
    # ... 16 more entries ...
  - Operations:                          # <-- NEW: insert after User Guide
    - Parallel Migrations: ops/parallel-migrations.md
    - Maintenance Mode: ops/maintenance-mode.md
    - Health Checks: ops/health-checks.md
  - Examples:
    - SaaS Subdomain: examples/saas-subdomain.md
    - API Header: examples/api-header.md
    - SaaS Demo (runnable): examples/saas-demo.md
  - Contributor Guide:
    # ...
```

**Exact group format to copy** (lines 91–95 of mkdocs.yml — Examples group):
```yaml
  - Examples:
    - SaaS Subdomain: examples/saas-subdomain.md
    - API Header: examples/api-header.md
    - SaaS Demo (runnable): examples/saas-demo.md
```

Each page entry is `    - Page Title: path/to/file.md` (4-space indent under the group). No index page needed for the Operations group (Examples has no index file either).

The GOV-02 policy note may also require adding an entry under `Contributor Guide` if a new page is created. If the note goes into the existing `test-infrastructure.md`, no new nav entry is needed. If a new `contributor-guide/validation-policy.md` is created, add it as:
```yaml
  - Contributor Guide:
    # ...
    - Validation Policy: contributor-guide/validation-policy.md
```

---

### `scripts/docs-lint.sh` (config/ci — check() invocations)

**Analog:** `scripts/docs-lint.sh` lines 22–42 (the `check()` helper definition and its first five invocations)

**Why this analog:** This IS the file to modify; the pattern to copy is the existing `check()` call style already present at lines 38–42.

**The `check()` helper signature** (lines 22–33):
```bash
check() {
    local pattern="$1"
    local desc="$2"
    shift 2
    local targets=("$@")

    if grep -rnE --color=auto -- "$pattern" "${targets[@]}" 2>/dev/null; then
        echo ""
        echo "ERROR: $desc — remove these occurrences or justify via an inline comment."
        EXIT=1
    fi
}
```

`check()` is a **NEGATIVE** check — it sets `EXIT=1` when the pattern IS found. Use it to guard against wrong/stale forms, NOT to assert presence of correct forms.

**Existing call style to copy** (lines 38–42):
```bash
check 'wrapperClass'     "Found 'wrapperClass' (v0.1 DBAL approach — use doctrine.middleware tag)" "${TARGETS[@]}"
check 'wrapper_class'    "Found 'wrapper_class' (v0.1 YAML form — remove from doctrine.yaml samples)" "${TARGETS[@]}"
check 'ReflectionProperty' "Found 'ReflectionProperty' (v0.1 hack — middleware replaces it)" "${TARGETS[@]}"
check 'TenantConnection' "Found 'TenantConnection' (class deleted in v0.2 — reference the middleware)" "${TARGETS[@]}"
check 'sqlite://'        "Found 'sqlite://' URL form (use discrete driver:/path: params instead)" "${TARGETS[@]}"
```

**New ops-terms guard to append** (D-04) — add after line 42, before the `# D-15` block:
```bash
# D-04 (Phase 34): Ops-terms consistency guards.
# Guard against wrong/stale forms of the new ops commands and endpoint paths.
OPS_TARGETS=(docs/)
check 'tenancy:maintenance:activated'   "Wrong command name (use tenancy:maintenance:enable)" "${OPS_TARGETS[@]}"
check 'tenancy:maintenance:deactivated' "Wrong command name (use tenancy:maintenance:disable)" "${OPS_TARGETS[@]}"
check 'health/liveness'                 "Wrong endpoint path segment (use /_tenancy/health/live, not liveness)" "${OPS_TARGETS[@]}"
check 'cache_control_no_store'          "Underscore form (use Cache-Control: no-store header name)" "${OPS_TARGETS[@]}"
```

**Critical pitfall (from RESEARCH.md §Pitfall 1):** Do NOT write `check '/_tenancy/health/live' ...` — that would fire whenever the CORRECT path appears in docs, which is always. Only guard WRONG forms. The four guards above match only misspellings/wrong forms that will never appear in correct docs.

**Scoping:** Use `OPS_TARGETS=(docs/)` (docs-only), NOT `TARGETS=(docs/ src/Command/TenantInitCommand.php)`. UPGRADE.md and CHANGELOG.md are not under `docs/` so they are automatically excluded (consistent with existing D-04/D-15 precedents).

---

### `UPGRADE.md` (doc-upgrade — BC break section)

**Analog:** `UPGRADE.md` lines 252–353 (the `## 0.2 to 0.3` section — TenantInterface BC break with two migration paths)

**Why this analog:** The 0.2→0.3 section is the project's only previous `TenantInterface` BC-break section. The 0.4→0.5 section has the same structure: one new interface method, a recommended trait path, a manual implementation path, and a DB migration note. D-05 explicitly cites this as the template.

**Section header and intro pattern** (lines 252–257):
```markdown
## 0.2 to 0.3

Phase 20 introduces per-tenant Mailer support. The contract change is a
**BC break** on `TenantInterface`: three new abstract methods are required.
Two migration paths are documented below — pick whichever fits your codebase.
```

Replicate:
```markdown
## 0.4 to 0.5

Phase 32 introduces per-tenant maintenance mode. The contract change is a
**BC break** on `TenantInterface`: one new method is required.
Two migration paths are documented below, or no action if you do not use maintenance mode.
```

**Migration path A: trait (recommended)** (lines 278–298):
```markdown
### Migration path A: use TenantMailerConfigTrait (recommended)

The bundle ships a drop-in trait that adds the three nullable columns and
the six getter/setter methods at once:

```php
use Tenancy\Bundle\Mailer\TenantMailerConfigTrait;

class Tenant implements TenantInterface
{
    use TenantMailerConfigTrait; // satisfies getMailerDsn/From/ReplyTo + adds 3 columns
    // ... your existing properties and methods ...
}
```

The trait declares `#[ORM\Column(...)]` on each ... Running
`bin/console doctrine:migrations:diff` after adding the trait will generate a
migration adding the columns ... to your tenants table.
```

Replicate with `TenantMaintenanceConfigTrait`, which adds `bool $inMaintenance = false` and the `isInMaintenance(): bool` method plus the `in_maintenance` Doctrine column.

**Migration path B: manual** (lines 300–331):
```markdown
### Migration path B: manual implementation

If you want different column names ... implement the methods by hand:

```php
#[ORM\Column(type: 'string', length: 255, nullable: true)]
private ?string $mailerDsn = null;
// ...
public function getMailerDsn(): ?string { return $this->mailerDsn; }
```
```

Replicate with a minimal `isInMaintenance()` manual implementation:
```php
public function isInMaintenance(): bool
{
    return false; // or: return $this->inMaintenance;
}
```

**No-action note** (inline in `0.3 → 0.4`, lines 84–88):
```markdown
#### No action required if filesystem isolation is not needed

The `tenancy.filesystem.enabled` parameter defaults to `false`. Projects that don't opt in
see zero behaviour change when upgrading from v0.3 to v0.4.
```

Replicate: "No action required if you do not use maintenance mode — `TenantMaintenanceConfigTrait` returns `false` by default; any class returning `false` from `isInMaintenance()` is fully compatible with v0.5."

**Insert position:** At the top of UPGRADE.md, before the current `## 0.4.0 to 0.4.1` section (newest first ordering — consistent with the file's existing top-to-bottom = newest-to-oldest ordering).

---

### `examples/saas/composer.json` (config — platform pin)

**Analog:** `examples/saas/composer.json` lines 44–51 (the existing `config` block)

**Why this analog:** The file already has a `"config"` key; the change is adding `"platform": {"php": "8.2.99"}` inside it.

**Current `config` block** (lines 44–51):
```json
"config": {
    "allow-plugins": {
        "symfony/runtime": true,
        "symfony/flex": false
    },
    "optimize-autoloader": true,
    "sort-packages": true
},
```

**Target `config` block after D-06:**
```json
"config": {
    "allow-plugins": {
        "symfony/runtime": true,
        "symfony/flex": false
    },
    "optimize-autoloader": true,
    "platform": {
        "php": "8.2.99"
    },
    "sort-packages": true
},
```

The `"platform"` key goes INSIDE `"config"` (not alongside it at the top level). Key ordering follows `sort-packages: true` alphabetical convention: `allow-plugins` → `optimize-autoloader` → `platform` → `sort-packages`.

**After editing:** run `cd examples/saas && composer update` (without `--no-dev`) to regenerate the lock. The regenerated lock must have NO packages with `php: >=8.4` requirement. Verify: `python3 -c "import json; lock=json.load(open('composer.lock')); fails=[p for p in lock['packages']+lock['packages-dev'] if '8.4' in p.get('require',{}).get('php','')]; print(fails)"` from within `examples/saas/`.

---

### `examples/saas/composer.lock` (config — regenerated)

**No hand-editing.** This file is regenerated entirely by `composer update` after the `config.platform.php` pin is added. The executor must run `composer update` (not `composer update --lock`) inside `examples/saas/` to fully re-resolve all packages against the PHP 8.2 constraint.

---

### `docs/contributor-guide/test-infrastructure.md` (doc-policy — GOV-02 note)

**Analog:** `docs/contributor-guide/test-infrastructure.md` existing content (the file to extend)

**Why this analog:** The file already covers PHPUnit test suites and patterns. A "VALIDATION.md policy" note is a natural extension at the end of the file, after the existing test pattern documentation.

**Page section structure** (lines 1–20 of test-infrastructure.md):
```markdown
# Test Infrastructure

The bundle has a comprehensive test suite ... Understanding the patterns used
here is important for writing tests that fit the existing structure.

## Test Organization
...
## The 7 Test Kernels
...
## Pattern: `setUpBeforeClass` / `tearDownAfterClass`
...
```

**New section to append:**
```markdown
## Nyquist Validation Artifacts (`VALIDATION.md`)

Each v0.5 phase directory may contain a `VALIDATION.md` — a lightweight
coverage artifact that maps requirements to test signals. These files are
**advisory only**: the live green PHPUnit suite is the real phase gate.

`nyquist_validation: true` in `.planning/config.json` governs the Nyquist
*discovery workflow* (surfacing gaps for a human to review), not a blocking gate.
Phases that ship a green PHPUnit suite are considered complete regardless of
whether a `VALIDATION.md` was written. This is the de-facto policy from v0.4,
now made explicit in v0.5.

See `.planning/phases/32-maintenance-mode/32-VALIDATION.md` and
`.planning/phases/33-health-checks/33-VALIDATION.md` for format examples.
```

---

### `.planning/phases/31-parallel-migrations/31-VALIDATION.md` (planning-artifact — D-10 backfill)

**Analog:** `.planning/phases/32-maintenance-mode/32-VALIDATION.md` (frontmatter + body structure)

**Why this analog:** Phase 32's VALIDATION.md is the first v0.5 example. The Phase 31 backfill should follow the same frontmatter schema and record that Phase 31 completed via its VERIFICATION.md.

**Phase 32 VALIDATION.md frontmatter pattern** (from RESEARCH.md confirmed read):
```yaml
---
nyquist_compliant: false
wave_0_complete: false
status: complete
---
```

Phase 31 backfill should use:
```yaml
---
nyquist_compliant: false
wave_0_complete: true
status: complete
---
# Phase 31 Parallel Migrations — Validation (Retrospective)

**Status:** Complete (verified 2026-06-26 via `31-VERIFICATION.md`)
**Policy:** This artifact is advisory only — see `docs/contributor-guide/test-infrastructure.md`.
```

---

### `tests/Unit/Command/SharedEntityResyncCommandTest.php` (test — QA-01 confirm-yes path)

**Analog:** Same file, `testForceSkipsConfirmation()` method (lines 154–181) and `testLiveRunPromptsConfirmDefaultNoAbortsCleanly()` (lines 131–152)

**Why this analog:** These two methods are the exact structural predecessors of the new test. They share the same `makeCommand()`, `makeTenant()`, `wireSharedClasses()`, and `CommandTester` construction pattern.

**CommandTester construction pattern** (lines 120–124 and 146–151):
```php
$command = $this->makeCommand();
$tester = new CommandTester($command);
$exitCode = $tester->execute(['--dry-run' => true]);
$this->assertSame(Command::SUCCESS, $exitCode);

// Alternate: non-interactive
$exitCode = $tester->execute([], ['interactive' => false]);
```

**New method to add — `testLiveRunConfirmYesProceedsToApply()`:**
```php
/**
 * SHARE-02-c: Live run with TTY confirm 'yes' proceeds to apply (QA-01 close).
 * CommandTester::setInputs(['yes']) feeds the answer to $io->confirm().
 */
public function testLiveRunConfirmYesProceedsToApply(): void
{
    $tenant = $this->makeTenant('acme');
    $this->tenantProvider->method('findAll')->willReturn([$tenant]);

    $entity = new \stdClass();
    $this->wireSharedClasses(['App\Entity\Config'], [$entity]);
    $this->copier->method('classifyRow')->willReturn('insert');

    // Key assertion: applyRow IS called when user confirms 'yes'
    $this->copier->expects($this->atLeastOnce())->method('applyRow');

    $tenantEm = $this->createMock(EntityManagerInterface::class);
    $this->registry->method('getManager')->with('tenant')->willReturn($tenantEm);

    $command = $this->makeCommand();
    $tester = new CommandTester($command);
    $tester->setInputs(['yes']);   // feeds $io->confirm('Proceed with live resync?', false)
    $exitCode = $tester->execute([], ['interactive' => true]);

    $this->assertSame(Command::SUCCESS, $exitCode);
}
```

**Placement:** Insert after `testLiveRunPromptsConfirmDefaultNoAbortsCleanly()` (line 152), before `testForceSkipsConfirmation()` (line 154). Maintain the `SHARE-02-c` docblock tag (it is the UAT item being closed).

**Pitfall (from RESEARCH.md §Pitfall 3):** Use `$tester->setInputs(['yes'])` (string), NOT `[true]` or `[1]`. `SymfonyStyle::confirm()` reads a raw string token from stdin.

---

### `tests/Unit/PHPStan/ExtensionInstallerContractTest.php` (test — QA-01 PHPStan auto-load)

**Analog:** `tests/Unit/PHPStan/Rule/MutualExclusionRuleTest.php` (namespace, class structure, `getAdditionalConfigFiles()` path pattern)

**Why this analog:** The three rule test classes in `tests/Unit/PHPStan/Rule/` load `extension.neon` via `getAdditionalConfigFiles()` and prove the rules work. The new contract test sits one level up in `tests/Unit/PHPStan/` and proves the metadata that `phpstan/extension-installer` reads (the `extra.phpstan.includes` key in root `composer.json`).

**Namespace and class structure to copy** (lines 1–16 of MutualExclusionRuleTest.php):
```php
<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Unit\PHPStan\Rule;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use PHPUnit\Framework\Attributes\Group;
use Tenancy\Bundle\PHPStan\Rule\MutualExclusionRule;

/**
 * @extends RuleTestCase<MutualExclusionRule>
 */
#[Group('phpstan-extension')]
final class MutualExclusionRuleTest extends RuleTestCase
```

**New class:**
```php
<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Unit\PHPStan;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('phpstan-extension')]
final class ExtensionInstallerContractTest extends TestCase
```

Note: This class extends `TestCase` (not `RuleTestCase`), since it is a metadata contract test, not a static analysis rule execution test.

**extension.neon path pattern** (from MutualExclusionRuleTest.php line 28–30):
```php
public static function getAdditionalConfigFiles(): array
{
    return [__DIR__.'/../../../../extension.neon'];
}
```

The new test lives at `tests/Unit/PHPStan/ExtensionInstallerContractTest.php` (one level shallower), so the path to `extension.neon` is:
```php
__DIR__.'/../../../extension.neon'  // tests/Unit/PHPStan/ → package root
```

**Test body pattern (Option A — metadata contract):**
```php
public function testComposerJsonDeclaresExtensionNeonInPhpstanIncludes(): void
{
    $composerJson = json_decode(
        file_get_contents(__DIR__.'/../../../composer.json'),
        true,
        512,
        \JSON_THROW_ON_ERROR
    );
    $includes = $composerJson['extra']['phpstan']['includes'] ?? [];
    $this->assertContains('extension.neon', $includes);
}

public function testExtensionNeonExistsAtDeclaredPath(): void
{
    $neonPath = __DIR__.'/../../../extension.neon';
    $this->assertFileExists($neonPath);
}

public function testExtensionNeonDeclaresThreeRuleClasses(): void
{
    $neon = file_get_contents(__DIR__.'/../../../extension.neon');
    $this->assertStringContainsString('MutualExclusionRule', $neon);
    $this->assertStringContainsString('TenantIdDriftRule', $neon);
    $this->assertStringContainsString('SharedEntityLeakRule', $neon);
}
```

This is the RESEARCH.md-recommended Option A approach: file existence + string-contains assertions. No live PHPStan invocation, no dependency on `nette/neon` API parsing.

---

## Shared Patterns

### Markdown Page Structure (all three ops pages)

**Source:** `docs/user-guide/mailer-bootstrapper.md` + `docs/user-guide/cli-commands.md`
**Apply to:** `docs/ops/parallel-migrations.md`, `docs/ops/maintenance-mode.md`, `docs/ops/health-checks.md`

Every ops page must follow this skeleton:
1. `# Page Title` — feature name as H1
2. One-paragraph value proposition (what it does, when to use it)
3. Optional: "This page covers: X, Y, Z" orientation sentence
4. `---` horizontal rule
5. `## How it works` — internal mechanism, request lifecycle, priority/ordering
6. `## Configuration` — config keys + YAML/PHP tab examples
7. `## [Command / Endpoint] Reference` — flags table or endpoint table
8. `## Runbook: [specific scenario]` — 1-2 concrete operational walkthrough(s)
9. `## See Also` — 3-5 cross-links

### MkDocs Admonition Pattern

**Source:** `docs/user-guide/shared-db.md` lines 36–39, 125–131
**Apply to:** All three ops pages

Use `!!! danger` for data-loss/security-critical warnings, `!!! warning` for operational pitfalls (CDN caching, cache TTL delay), `!!! note` for clarifications and cross-references, `!!! tip` for prerequisites.

### CommandTester Test Method Structure

**Source:** `tests/Unit/Command/SharedEntityResyncCommandTest.php` lines 105–181
**Apply to:** New `testLiveRunConfirmYesProceedsToApply()` method

Pattern: `makeCommand()` → `makeCommandTester()` → optional `setInputs()` → `execute()` → `assertSame(Command::SUCCESS, $exitCode)` → assert mock interactions.

### PHPStan Test Namespace

**Source:** `tests/Unit/PHPStan/Rule/MutualExclusionRuleTest.php` lines 1–6
**Apply to:** `tests/Unit/PHPStan/ExtensionInstallerContractTest.php`

Namespace is `Tenancy\Bundle\Tests\Unit\PHPStan` (drop the `Rule` sub-namespace since the new test is not a rule test). `declare(strict_types=1)` required on every PHP file (CLAUDE.md convention).

---

## No Analog Found

All files have a close analog in the codebase. The only file with no hand-editable analog is:

| File | Role | Data Flow | Reason |
|---|---|---|---|
| `examples/saas/composer.lock` | config | — | Regenerated artifact; not hand-edited. Run `composer update` from within `examples/saas/` after adding the platform pin. |

---

## Metadata

**Analog search scope:** `docs/`, `scripts/`, `tests/Unit/Command/`, `tests/Unit/PHPStan/`, `examples/saas/`, `UPGRADE.md`, `mkdocs.yml`
**Files read:** 11
**Pattern extraction date:** 2026-07-06
