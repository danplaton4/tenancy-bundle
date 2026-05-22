# Phase 21: Demo App — Pattern Map

**Mapped:** 2026-05-22
**Files analyzed:** 28 new files (`examples/saas/**`, `.github/workflows/demo-smoke.yml`)
**Analogs found:** 18 in-repo strong matches, 6 external (`/Users/danplaton/dev/hype/tests/symfony74-demo`) reference matches, 4 no-analog
**Read-first rule for executors:** prefer **in-repo** analogs as the source of truth. External `hype/tests/symfony74-demo/*` is a *reference* (verification asset per CONTEXT D-17) — copy its **shape** (file layout, fixture pattern) but adapt the stack to FrankenPHP + Caddy + MariaDB 11 per DEC-DEMO-01.

---

## File Classification

| New File | Role | Data Flow | Closest Analog | Match |
|----------|------|-----------|----------------|-------|
| `examples/saas/composer.json` | config | static | `/Users/danplaton/dev/hype/tests/symfony74-demo/composer.json` (external) | shape-match (external) |
| `examples/saas/compose.yaml` | config | static | `/Users/danplaton/dev/hype/tests/symfony74-demo/compose.yaml` (external — diverge: FrankenPHP not nginx+FPM; MariaDB not MySQL; add Mailpit) | role-match (external) |
| `examples/saas/Dockerfile` | config | build-step | — | **no in-repo analog** — use RESEARCH §"Pattern 3" + FrankenPHP docs |
| `examples/saas/Caddyfile` | config | edge-routing | — | **no in-repo analog** — use RESEARCH §"Pattern 2" |
| `examples/saas/docker/entrypoint.sh` | utility | bootstrap-script | — | **no in-repo analog** — use RESEARCH §"Pitfall 5" recommendation |
| `examples/saas/.env.example` | config | static | bundle root `.env` if present; otherwise idiomatic Symfony skeleton | role-match |
| `examples/saas/config/bundles.php` | config | static | `/Users/danplaton/dev/hype/tests/symfony74-demo/config/bundles.php` (external); `tests/Fixtures/BundlesPhpCorpus/skeleton/bundles.php` (in-repo) | exact (external) |
| `examples/saas/config/packages/tenancy.yaml` | config | static | `/Users/danplaton/dev/hype/tests/symfony74-demo/config/packages/tenancy.yaml` (external); **also** RESEARCH §"Phase 17 OriginHeaderResolver" config block | exact (external) |
| `examples/saas/config/packages/doctrine.yaml` | config | static | `/Users/danplaton/dev/hype/tests/symfony74-demo/config/packages/doctrine.yaml` (external) | exact (external) |
| `examples/saas/config/packages/framework.yaml` | config | static | `tests/Integration/TestKernel.php` framework loadFromExtension (in-repo, lines 44–53) | role-match |
| `examples/saas/config/packages/twig.yaml` | config | static | external symfony74-demo `twig.yaml` | exact (external) |
| `examples/saas/config/packages/mailer.yaml` | config | static | — (Phase 20 is the source of truth) | use RESEARCH §"Phase 20: Mailer" config block |
| `examples/saas/config/packages/web_profiler.yaml` | config | static | external symfony74-demo `web_profiler.yaml` | exact (external) |
| `examples/saas/config/routes.yaml` | config | static | — | use idiomatic Symfony 7.4 attribute-route loader |
| `examples/saas/src/Kernel.php` | controller | bootstrap | `/Users/danplaton/dev/hype/tests/symfony74-demo/src/Kernel.php` (external); `tests/Integration/TestKernel.php` (in-repo) | exact (external) |
| `examples/saas/src/Command/SeedDemoCommand.php` | controller (CLI) | batch + per-tenant boot/clear | **`src/Command/TenantMigrateCommand.php`** (in-repo, lines 97–123 and 125–147) | **exact (in-repo)** — copy `setTenant → boot → try/finally → clear` pattern verbatim |
| `examples/saas/src/Controller/LandlordController.php` | controller | request-response | `/Users/danplaton/dev/hype/tests/symfony74-demo/src/Controller/LandlordController.php` (external) | exact (external) |
| `examples/saas/src/Controller/TenantController.php` | controller | request-response | `/Users/danplaton/dev/hype/tests/symfony74-demo/src/Controller/DashboardController.php` (external, lines 17–37) | exact (external) |
| `examples/saas/src/Controller/DemoMailController.php` | controller | request-response (mailer dispatch) | — (Phase 20 surface) | use Phase 20 service `Symfony\Component\Mailer\MailerInterface`; see RESEARCH §"Phase 20" code block |
| `examples/saas/src/Controller/HealthController.php` | controller | request-response | — | trivial; use RESEARCH §"Open Questions §3" recommendation |
| `examples/saas/src/Entity/DemoTenant.php` (or `App\Entity\Tenant`) | model | persistence | **`src/Entity/Tenant.php`** (in-repo, full file) | **exact (in-repo)** — extend OR copy-and-implement-TenantInterface |
| `examples/saas/src/Entity/Post.php` | model | persistence | — (greenfield); follow Doctrine ORM idioms (attribute mapping, `readonly` constructor) | role-match (see bundle's `Tenant.php` for attribute mapping conventions) |
| `examples/saas/src/Repository/PostRepository.php` | model (repository) | CRUD-read | — | use Doctrine `ServiceEntityRepository` skeleton |
| `examples/saas/src/DataFixtures/LandlordTenantsFixture.php` | utility | batch-write | `/Users/danplaton/dev/hype/tests/symfony74-demo/src/Command/SeedTenantsCommand.php` (external, lines 45–94 — for tenant data shape) | shape-match (external) |
| `examples/saas/templates/base.html.twig` | component | static-render | external symfony74-demo `templates/base.html.twig` | shape-match (external) |
| `examples/saas/templates/landlord/index.html.twig` | component | static-render | external symfony74-demo equivalent | shape-match (external) |
| `examples/saas/templates/tenant/index.html.twig` | component | static-render | external symfony74-demo `dashboard/index.html.twig` | shape-match (external) |
| `examples/saas/public/index.php` | controller | bootstrap | Symfony skeleton standard | — |
| `examples/saas/bin/console` | utility | CLI entry | Symfony skeleton standard | — |
| `examples/saas/bin/smoke.sh` | utility | smoke-test | — | **no in-repo analog** — use RESEARCH §"Smoke script" code block verbatim |
| `examples/saas/README.md` | docs | static | — | use CONTEXT D-12 three-step ladder + RESEARCH §"Common Pitfalls" |
| `.github/workflows/demo-smoke.yml` | config (CI) | event-driven | **`.github/workflows/ci.yml`** (in-repo, lines 1–33 for trigger+checkout+setup-php shape) | role-match (in-repo) — but heavily diverges (no PHP matrix; uses `docker compose up --wait`) |

---

## Pattern Assignments

### `examples/saas/src/Command/SeedDemoCommand.php` (controller/CLI, per-tenant batch)

**Analog (in-repo, EXACT):** `src/Command/TenantMigrateCommand.php`

> **This is the most load-bearing pattern in the entire phase.** RESEARCH §"Critical Discrepancy" verified that `tenancy:migrate --create-dbs` does NOT exist. The demo command MUST replicate the boot/clear pattern from `TenantMigrateCommand` because that is the only correct way to iterate tenants in a single PHP process.

**Imports + class skeleton** (TenantMigrateCommand lines 1–36):
```php
<?php
declare(strict_types=1);

namespace App\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Tenancy\Bundle\Bootstrapper\BootstrapperChain;
use Tenancy\Bundle\Context\TenantContext;
use Tenancy\Bundle\Provider\TenantProviderInterface;
use Tenancy\Bundle\TenantInterface;

#[AsCommand(name: 'app:seed-demo', description: 'Seed demo tenants, create per-tenant DBs and schemas, insert posts')]
final class SeedDemoCommand extends Command
{
    public function __construct(
        private readonly TenantProviderInterface $tenantProvider,
        private readonly BootstrapperChain $bootstrapperChain,
        private readonly TenantContext $tenantContext,
        private readonly Connection $landlordConnection,   // DBAL landlord connection
        // ... EntityManagerInterface for tenant EM, plus a list of demo tenant rows
    ) {
        parent::__construct();
    }
```

**Per-tenant boot/clear pattern — COPY VERBATIM** (TenantMigrateCommand lines 97–108 + 125–128):
```php
foreach ($tenants as $tenant) {
    try {
        // === inlined from runMigrationsForTenant() ===
        $this->tenantContext->setTenant($tenant);
        $this->bootstrapperChain->boot($tenant);

        // do per-tenant work here:
        //   - issue CREATE DATABASE IF NOT EXISTS via $this->landlordConnection
        //     (BEFORE setTenant if the user lacks GRANT privileges — see external
        //      SeedTenantsCommand lines 36–43 for root-credential fallback)
        //   - run SchemaTool->createSchema(metadata for Post::class) on the tenant EM
        //   - persist seed Posts and flush

        $io->writeln(sprintf(' <info>✓</info> %s', $tenant->getSlug()));
    } catch (\Throwable $e) {
        $failures[] = $tenant->getSlug();
        $io->writeln(sprintf(' <error>✗</error> %s (%s)', $tenant->getSlug(), $e->getMessage()));
    } finally {
        $this->tenantContext->clear();
        $this->bootstrapperChain->clear();   // reverse-order clear
    }
}
```

**Driver-guard pattern** (TenantMigrateCommand lines 52–66) — optional for the demo since the demo is always database_per_tenant, but cargo it if defensive:
```php
if ('shared_db' === $this->driver) {
    // ... write to stderr, return Command::FAILURE
}
```

**CREATE DATABASE pattern (from external prototype, in scope):**
- Source: `/Users/danplaton/dev/hype/tests/symfony74-demo/src/Command/SeedTenantsCommand.php` lines 36–43, 78–80
- Pattern: open a *separate* DBAL connection with root credentials via `DriverManager::getConnection([...])` because the runtime `tenancy` user typically lacks `CREATE DATABASE` privileges. Issue `CREATE DATABASE IF NOT EXISTS` + `GRANT ALL` once, then close.
- Idempotency: `IF NOT EXISTS` everywhere; check `TenantProviderInterface::findAll()` for empty and short-circuit if already seeded (external prototype lines 29–33).

**Schema-create pattern (planner picks, RESEARCH §"Pattern 1" suggests `SchemaTool`):**
```php
$schemaTool = new \Doctrine\ORM\Tools\SchemaTool($this->tenantEm);
$schemaTool->createSchema([$this->tenantEm->getClassMetadata(Post::class)]);
```

---

### `examples/saas/src/Entity/DemoTenant.php` (model, persistence)

**Analog (in-repo, EXACT):** `src/Entity/Tenant.php`

**Decision per RESEARCH §"Open Questions §1":** subclass `Tenancy\Bundle\Entity\Tenant` with one extra column.

**Class declaration pattern** (in-repo Tenant.php lines 1–14):
```php
<?php
declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Tenancy\Bundle\Entity\Tenant;

#[ORM\Entity]
#[ORM\Table(name: 'tenancy_tenants')]   // same table — single-table extension
class DemoTenant extends Tenant
{
    #[ORM\Column(type: 'string', length: 7, nullable: true)]
    private ?string $brandColor = null;

    public function getBrandColor(): ?string { return $this->brandColor; }
    public function setBrandColor(?string $c): self { $this->brandColor = $c; return $this; }
}
```

**Column conventions to mirror** (in-repo Tenant.php lines 14–48):
- `string` length tuned (slug=63, name=255, mailer*=255, domain=253)
- `nullable: true` for optional columns
- `private readonly` is NOT used on Doctrine columns (Doctrine needs to write via reflection); plain `private`
- Mailer columns (mailerDsn, mailerFrom, mailerReplyTo) are already inherited from `Tenant` — DemoTenant does NOT redeclare them
- `#[ORM\HasLifecycleCallbacks]` + `onPrePersist` / `onPreUpdate` are inherited; do not re-add

**Wire-up:** `config/packages/tenancy.yaml` adds `tenant_entity_class: App\Entity\DemoTenant`.

---

### `examples/saas/src/Controller/LandlordController.php` (controller, request-response)

**Analog (external, EXACT):** `/Users/danplaton/dev/hype/tests/symfony74-demo/src/Controller/LandlordController.php`

**Full file** (29 lines — copy verbatim, adjusting entity import to `App\Entity\DemoTenant`):
```php
<?php
declare(strict_types=1);

namespace App\Controller;

use App\Entity\DemoTenant;  // CHANGED from Tenancy\Bundle\Entity\Tenant
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class LandlordController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {}

    #[Route('/', name: 'landlord_index')]
    public function index(): Response
    {
        $tenants = $this->entityManager->getRepository(DemoTenant::class)->findAll();

        return $this->render('landlord/index.html.twig', ['tenants' => $tenants]);
    }
}
```

**Note for executor:** The landlord controller MUST resolve only when no tenant is identified. CONTEXT D-02 + RESEARCH FIX-02 invariant — `ResolverChain` returns null for `tenancy.localhost` (no leftmost label), `TenantContextOrchestrator` tolerates null, controller proceeds. No special routing is needed — the same `'/'` route resolves differently based on Host (because the tenant controller is on a DIFFERENT route or guarded by a tenant-required check).

---

### `examples/saas/src/Controller/TenantController.php` (controller, request-response)

**Analog (external, role-match):** `/Users/danplaton/dev/hype/tests/symfony74-demo/src/Controller/DashboardController.php` lines 17–37.

**Injection + tenant-EM access pattern** (lines 17–24, copy verbatim):
```php
public function __construct(
    #[Autowire(service: 'doctrine.orm.tenant_entity_manager')]
    private readonly EntityManagerInterface $tenantEm,
    private readonly TenantContext $tenantContext,
) {}
```

**Read-only landing handler** (CONTEXT D-01 is read-only — adapt lines 26–36 minus the project/task complexity):
```php
#[Route('/', name: 'tenant_landing')]
public function index(): Response
{
    $tenant = $this->tenantContext->getTenant();   // never null on this route (host wildcard)
    $posts = $this->tenantEm->getRepository(Post::class)->findAll();

    return $this->render('tenant/index.html.twig', [
        'tenant' => $tenant,
        'posts' => $posts,
    ]);
}
```

**REJECT** the prototype's `createProject` POST handler (lines 56–71) — CONTEXT D-01 deliberately keeps the demo read-only.

---

### `examples/saas/src/Controller/DemoMailController.php` (controller, mailer dispatch)

**Analog:** none in-repo; Phase 20 is the source of truth.

**Pattern (from RESEARCH §"Phase 20: Mailer"):**
```php
<?php
declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Attribute\Route;
use Tenancy\Bundle\Context\TenantContext;

class DemoMailController extends AbstractController
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly TenantContext $tenantContext,
    ) {}

    #[Route('/_demo/send-test-mail', name: 'demo_send_mail', methods: ['POST'])]
    public function send(): Response
    {
        $tenant = $this->tenantContext->getTenant();
        // From + Reply-To injected by Phase 20 TenantMessageDecorator — controller is tenant-agnostic
        $this->mailer->send(
            (new Email())
                ->to('demo@example.test')
                ->subject(sprintf('Test from %s', $tenant?->getName() ?? 'landlord'))
                ->text('This email demonstrates per-tenant mailer dispatch.')
        );

        return new Response('Email queued — see http://localhost:8025', 202);
    }
}
```

**Important:** the controller does NOT set From/Reply-To explicitly. Phase 20 `TenantMessageDecorator` does that, reading from the active tenant. See `src/Mailer/MailerBootstrapper.php` (priority -20) and `src/Mailer/TenantMailerConfigTrait.php` for the contract.

---

### `examples/saas/src/Controller/HealthController.php` (controller, trivial 200)

**No analog** — write trivially per RESEARCH §"Open Questions §3":

```php
#[Route('/health', name: 'health', methods: ['GET'])]
public function health(): Response
{
    return new Response('OK');
}
```

**Pitfall mitigation (RESEARCH §"Pitfall 5"):** make `/health` 200 only AFTER seed completion. Either:
- Sentinel file: `file_exists('/app/var/seeded') ? 200 : 503`
- OR run `app:seed-demo` BEFORE `exec frankenphp run` in `entrypoint.sh` (RECOMMENDED — simpler).

---

### `examples/saas/config/packages/tenancy.yaml` (config)

**Analog (external base + in-repo Phase 17 config):** combine `/Users/danplaton/dev/hype/tests/symfony74-demo/config/packages/tenancy.yaml` (lines 1–9) with the RESEARCH §"Phase 17" YAML block.

**Combined target shape:**
```yaml
tenancy:
    driver: database_per_tenant
    database:
        enabled: true
    landlord_connection: landlord
    host:
        app_domain: tenancy.localhost
    strict_mode: true                # CONTEXT D-04 implies strong isolation; CLAUDE.md says default ON
    tenant_entity_class: App\Entity\DemoTenant
    resolvers: [host, origin, header, query_param, console]
    origin:
        allow_list:
            - { origin: 'https://*.tenancy.localhost' }
            - { origin: 'http://*.tenancy.localhost' }
```

**Verify against bundle source:**
- `src/Resolver/OriginHeaderResolver.php` — header name + priority 25
- `src/DependencyInjection/Compiler/OriginHeaderResolverConfigPass.php` — compile-time validation (empty allow-list throws)
- `src/Resolver/HostResolver.php` — priority 30, leftmost-label slug extraction

---

### `examples/saas/config/packages/doctrine.yaml` (config)

**Analog (external, EXACT):** `/Users/danplaton/dev/hype/tests/symfony74-demo/config/packages/doctrine.yaml` (all 37 lines)

Two connections (`landlord`, `tenant`) and two EMs with attribute mappings to `src/Entity/Landlord` and `src/Entity/Tenant` dirs. Copy verbatim, **adapt**:
- `landlord` mapping dir: point at `src/Entity` (or split into `src/Entity/Landlord` + `src/Entity/Tenant` like the prototype). Recommended: split — keeps `Post.php` out of the landlord EM.
- `tenant` mapping prefix: `App\Entity\Tenant` (or wherever `Post` lives).
- Use `pdo_mysql` (MariaDB driver) — same as MySQL prototype.

---

### `examples/saas/compose.yaml` (config)

**Analog (external, shape-match — diverges substantially):** `/Users/danplaton/dev/hype/tests/symfony74-demo/compose.yaml`

**External pattern to mirror:**
- `depends_on: { db: { condition: service_healthy } }` (lines 8–10)
- Healthcheck shape on DB (lines 33–37): `mysqladmin ping` + interval/timeout/retries

**Diverge from external:**
| External | Phase 21 |
|----------|----------|
| `nginx:alpine` + separate `php` (FPM) service | Single `php` service using `dunglas/frankenphp` image |
| `mysql:8.0` | `mariadb:11` (DEC-DEMO-01) |
| Port `8074:80` | Port `80:80` and `443:443` (RESEARCH §"Open Questions §4") |
| No Mailpit | Add `axllent/mailpit:latest` (RESEARCH §Stack); bind to `127.0.0.1:8025` only (security §"Mailpit binding") |
| Bind-mount `.:/app` (host-aware) | Bind-mount `../../:/bundle:ro` (per RESEARCH §"Pattern 3" path-repo gotcha) + `.:/app` |

**Healthcheck upgrade (RESEARCH §"Pitfall 2"):** prefer MariaDB image's bundled `healthcheck.sh`:
```yaml
healthcheck:
    test: ['CMD', 'healthcheck.sh', '--connect', '--innodb_initialized']
```

---

### `examples/saas/Caddyfile` (config)

**No in-repo analog.** Use RESEARCH §"Pattern 2" verbatim:
```caddy
{
    frankenphp
    auto_https disable_redirects   # Pitfall 1 mitigation: HTTP-default walkthrough
}

*.tenancy.localhost, tenancy.localhost, localhost {
    tls internal
    root * public/
    encode zstd br gzip
    php_server
}
```

`localhost` is included so the in-container healthcheck (`curl http://localhost/health`) resolves.

---

### `examples/saas/docker/entrypoint.sh` (utility, bootstrap)

**No in-repo analog.** Compose from RESEARCH §"Pitfall 5" recommendation:
```bash
#!/usr/bin/env bash
set -euo pipefail

# (1) Composer install runs at IMAGE BUILD time in Dockerfile — not here.
# (2) Wait for DB is handled by compose depends_on.condition: service_healthy.
# (3) Seed BEFORE exec'ing FrankenPHP so /health is only green after seed.
bin/console app:seed-demo --no-interaction
exec frankenphp run --config /etc/caddy/Caddyfile
```

`chmod +x` MUST be set (Dockerfile or in-repo file mode).

---

### `examples/saas/bin/smoke.sh` (utility, smoke-test)

**No in-repo analog.** Use RESEARCH §"Smoke script" block (lines 686–738 of 21-RESEARCH.md) verbatim.

Key features:
- `set -euo pipefail` — exit on any failure
- `curl --fail --max-time 10 --retry 5 --retry-all-errors --retry-connrefused` — handle transient errors
- `/health` readiness loop (max 30 iterations × 1s = 30s timeout)
- Per-tenant body-marker assertion (`grep -q` exit code)
- Origin-resolver assertion (Host: `tenancy.localhost`, Origin: `https://acme.tenancy.localhost`)
- Exit non-zero on any FAIL

`chmod +x` required.

---

### `.github/workflows/demo-smoke.yml` (config, CI)

**Analog (in-repo, role-match):** `.github/workflows/ci.yml` lines 1–33.

**Pattern to copy (trigger + checkout):**
```yaml
on:
  push:
    branches: [master]
  pull_request:
    branches: [master]

jobs:
  smoke:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v5     # ci.yml line 21 — same version pin
```

**Diverge — no PHP matrix, no setup-php, no composer-install** (the demo's `composer install` runs inside the Docker image build). Full target from RESEARCH §"CI workflow" (lines 742–768 of 21-RESEARCH.md):
```yaml
name: demo-smoke

on:
  push:
    branches: [master]
  pull_request:
    branches: [master]

jobs:
  smoke:
    runs-on: ubuntu-latest
    timeout-minutes: 10
    defaults: { run: { working-directory: examples/saas } }
    steps:
      - uses: actions/checkout@v5
      - name: Build and start demo
        run: docker compose up -d --wait --build
      - name: Show container logs on failure
        if: failure()
        run: docker compose logs
      - name: Run smoke
        run: bash bin/smoke.sh
      - name: Tear down
        if: always()
        run: docker compose down -v
```

**Note:** `actions/checkout@v5` (matches in-repo ci.yml line 21 — NOT v4 as written in the RESEARCH excerpt).

---

### `examples/saas/config/bundles.php` (config)

**Analog (external, EXACT):** `/Users/danplaton/dev/hype/tests/symfony74-demo/config/bundles.php`

Full file (lines 1–11), adapted (drop migrations-bundle per CONTEXT D-05 "no doctrine/migrations"):
```php
<?php

return [
    Symfony\Bundle\FrameworkBundle\FrameworkBundle::class => ['all' => true],
    Tenancy\Bundle\TenancyBundle::class => ['all' => true],
    Doctrine\Bundle\DoctrineBundle\DoctrineBundle::class => ['all' => true],
    Symfony\Bundle\TwigBundle\TwigBundle::class => ['all' => true],
    Symfony\Bundle\WebProfilerBundle\WebProfilerBundle::class => ['dev' => true, 'test' => true],
    Doctrine\Bundle\FixturesBundle\DoctrineFixturesBundle::class => ['dev' => true, 'test' => true],
];
```

---

### `examples/saas/composer.json` (config)

**Analog (external, shape-match):** `/Users/danplaton/dev/hype/tests/symfony74-demo/composer.json` lines 1–89.

**Pattern to copy (path repo, lines 6–17):**
```json
"repositories": [
    {
        "type": "path",
        "url": "../../",
        "options": {
            "symlink": true,
            "versions": { "danplaton4/tenancy-bundle": "0.3.x-dev" }
        }
    }
],
```

**CRITICAL diverge** — per RESEARCH §"Pattern 3" the host-relative `../../` symlink will break inside the container. Instead either:
- Option A: Use `/bundle` as the `url` (matches bind-mount inside container)
- Option B: Keep `../../` and ensure Dockerfile COPYs the bundle source into the same relative position

**Recommend Option B** for simplicity — `composer install` runs at build time after `COPY ../../ /bundle-src` etc. Planner finalizes.

**Adapt the prototype's `require`/`require-dev`:**
- Drop `symfony/flex` (RESEARCH §State of the Art: bundle has no Flex recipe)
- Drop `doctrine/doctrine-migrations-bundle` (CONTEXT D-05)
- Add `symfony/mailer:7.4.*` (CONTEXT D-09)
- Add `doctrine/doctrine-fixtures-bundle:^4` to `require-dev` (CONTEXT D-06)
- Keep `symfony/web-profiler-bundle` in `require-dev` (CONTEXT D-07)

---

## Shared Patterns (cross-file)

### Pattern A: `final class`, `declare(strict_types=1)`, `private readonly` constructor injection

**Source:** `src/Command/TenantMigrateCommand.php` lines 1–36; `src/Entity/Tenant.php` lines 1–8.
**Apply to:** Every PHP file in `examples/saas/src/**`.
```php
<?php
declare(strict_types=1);

namespace App\…;

final class …
{
    public function __construct(
        private readonly Foo $foo,
        private readonly Bar $bar,
    ) {}
}
```
*Caveat:* Doctrine entity properties cannot be `readonly` (Doctrine writes via reflection). Plain `private` on entity columns (see `src/Entity/Tenant.php` — none of its columns are readonly).

### Pattern B: Per-tenant boot/clear lifecycle

**Source:** `src/Command/TenantMigrateCommand.php` lines 97–108 (the try/finally block).
**Apply to:** `SeedDemoCommand`, any future demo command that iterates tenants.
```php
foreach ($tenants as $tenant) {
    try {
        $this->tenantContext->setTenant($tenant);
        $this->bootstrapperChain->boot($tenant);
        // ... per-tenant work
    } finally {
        $this->tenantContext->clear();
        $this->bootstrapperChain->clear();   // reverse-order
    }
}
```
**Anti-pattern:** `TenantContextOrchestrator::executeAs(...)` does NOT exist (RESEARCH §"Critical Discrepancy"). Use the explicit pattern above.

### Pattern C: `SymfonyStyle` + ✓/✗ progress markers

**Source:** `src/Command/TenantMigrateCommand.php` lines 50, 100, 103, 111.
**Apply to:** `SeedDemoCommand` for human-friendly CLI output:
```php
$io = new SymfonyStyle($input, $output);
$io->writeln(sprintf(' <info>✓</info> %s', $tenant->getSlug()));
$io->writeln(sprintf(' <error>✗</error> %s (%s)', $tenant->getSlug(), $e->getMessage()));
```

### Pattern D: CI workflow trigger + checkout

**Source:** `.github/workflows/ci.yml` lines 1–6, 21.
**Apply to:** `.github/workflows/demo-smoke.yml`.
- Trigger: `push: branches: [master]` + `pull_request:` — matches existing ci.yml shape
- Checkout action version: `actions/checkout@v5` (in-repo ci.yml is on v5; do NOT downgrade to v4 as RESEARCH excerpt suggests)
- Reuse `if: failure()` / `if: always()` step-conditional idioms

### Pattern E: TenantInterface implementation contract

**Source:** `src/Entity/Tenant.php` lines 13, 50–169 (implements `TenantInterface`).
**Apply to:** `App\Entity\DemoTenant` — either inherits (subclass) OR implements from scratch.
Required methods: `getSlug()`, `getName()`, `getDomain()`, `getConnectionConfig()`, `isActive()`, `getMailerDsn()`, `getMailerFrom()`, `getMailerReplyTo()`.

### Pattern F: `tenancy.yaml` shape (driver + database + landlord_connection + host)

**Source:** `/Users/danplaton/dev/hype/tests/symfony74-demo/config/packages/tenancy.yaml` (external, 9 lines).
**Apply to:** `examples/saas/config/packages/tenancy.yaml`.
Add Phase 17 origin allow-list per RESEARCH §"Phase 17" code block.

---

## No Analog Found

Files genuinely new to the repo, no precedent inside the bundle:

| File | Role | Reason | Reference for Planner |
|------|------|--------|-----------------------|
| `examples/saas/Caddyfile` | edge config | No Caddy in bundle. | RESEARCH §"Pattern 2", https://caddyserver.com/docs/automatic-https, https://frankenphp.dev/docs/symfony/ |
| `examples/saas/Dockerfile` | image build | No Docker images built in-bundle (CI uses GH-hosted runners). | RESEARCH §"Pattern 3" (bind-mount the bundle source); FrankenPHP base image |
| `examples/saas/docker/entrypoint.sh` | bootstrap script | No analog. | RESEARCH §"Pitfall 5" recommendation: seed BEFORE `exec frankenphp run` |
| `examples/saas/bin/smoke.sh` | smoke harness | No bash smoke scripts in bundle. | RESEARCH §"Smoke script" code block (lines 686–738 of 21-RESEARCH.md) |

---

## Metadata

**Analog search scope:**
- `/Users/danplaton/dev/packages/[claude-code] symfony-multitenancy/src/**`
- `/Users/danplaton/dev/packages/[claude-code] symfony-multitenancy/tests/**`
- `/Users/danplaton/dev/packages/[claude-code] symfony-multitenancy/.github/workflows/**`
- `/Users/danplaton/dev/hype/tests/symfony74-demo/**` (external reference per CONTEXT D-17)

**Files read for pattern extraction:**
- (in-repo) `src/Command/TenantMigrateCommand.php` — exact analog for `SeedDemoCommand`
- (in-repo) `src/Entity/Tenant.php` — exact analog for `DemoTenant`
- (in-repo) `.github/workflows/ci.yml` — role-match for `demo-smoke.yml` trigger shape
- (in-repo) `tests/Integration/TestKernel.php` — framework config shape
- (external) `hype/tests/symfony74-demo/composer.json` — path-repo shape
- (external) `hype/tests/symfony74-demo/compose.yaml` — depends_on + healthcheck shape (diverges from FrankenPHP)
- (external) `hype/tests/symfony74-demo/config/packages/tenancy.yaml` — base tenancy config
- (external) `hype/tests/symfony74-demo/config/packages/doctrine.yaml` — two-EM (landlord + tenant) config
- (external) `hype/tests/symfony74-demo/config/bundles.php` — bundles array
- (external) `hype/tests/symfony74-demo/src/Kernel.php` — MicroKernelTrait kernel
- (external) `hype/tests/symfony74-demo/src/Controller/LandlordController.php` — landlord index pattern
- (external) `hype/tests/symfony74-demo/src/Controller/DashboardController.php` — tenant-EM injection pattern
- (external) `hype/tests/symfony74-demo/src/Command/SeedTenantsCommand.php` — CREATE DATABASE + GRANT pattern, demo tenant data shape

**Pattern extraction date:** 2026-05-22
**Phase:** 21 — Demo App
