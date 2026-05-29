# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.3.3] — 2026-05-29

v0.3.3 closes the v0.3 milestone tech-debt audit and finalizes the install
ergonomics promotion. This is the tag that ships v0.3 Adoption Surface in its
final shape. The next milestone is v0.4 Storage & Shared Entities.

### Changed

- **`nikic/php-parser` promoted from `require-dev`+`suggest` to `require`** —
  the bundle now hard-requires `nikic/php-parser ^5.0`. Rationale (DEC-INST-02
  reversal, Phase 22): `tenancy:install` is the canonical onboarding path and
  it depends on the AST detector. Asking users to `composer require --dev
  nikic/php-parser` before running `tenancy:install` was a stumbling block on
  the install funnel; the dep is small (~150 KB), zero-runtime-cost when
  unused, and is already a transitive dep of every Symfony-Flex-aware
  project. The bundle's own runtime never touches the parser at request time
  — it loads only during the one-time install command.

### Fixed

- **Profiler mailer subsection now renders on all panel states** — the
  `{% if collector.data.mailer is defined %}` block in
  `src/Resources/views/Collector/tenant.html.twig` was previously nested
  inside `{% if collector.data.state == 'resolved' %}`, hiding cache
  hit/eviction counters on landlord, public, and health-check routes where
  operators most need to see them. Hoisted to the top level of the panel
  block. INT-01 from `.planning/v0.3-MILESTONE-AUDIT.md`. Affects DX-02 + BOOT-04.
- **Nullable-provider drift guard strengthened** — all six
  `tenancy.provider->nullOnInvalid()` consumer constructors now use the
  identical signature `?TenantProviderInterface $tenantProvider = null`.
  Previously three of six (ConsoleResolver, TenantRunCommand,
  TenantWorkerMiddleware) declared the parameter nullable but omitted the
  `= null` default, allowing a future contributor to drop the `?` without
  tripping a downstream caller. `NullableProviderInjectionContractTest` now
  locks the default-null contract via reflection across all seven registered
  sites (the six listed plus TenantAwareTransportsDecorator). CR-01.
- **Messenger retry semantics for misconfiguration** — `TenantRunCommand` and
  `TenantWorkerMiddleware` were already throwing
  `MissingTenantProviderException` (which extends `\LogicException`, NOT
  `\RuntimeException`) since v0.3.0, but unit tests asserted only the
  concrete class. New tests pin the `\LogicException` base class so a future
  refactor cannot regress Messenger's retry semantics: Symfony Messenger's
  default retry strategy excludes `LogicException` from re-queue, treating
  it as a permanent config error. WR-01.

### Changed (internal)

- **`ConsoleResolver` guard-ordering tripwire** — defensive comment block
  marks the WR-02 invariant (`null === $this->tenantProvider` guard MUST
  precede the Application::addOption mutation that adds `--tenant` to the
  global definition); `ConsoleResolverGuardOrderingTest` asserts the source
  order via file-read line scan. WR-02.
- **`QueryParamResolver` empty-string check tightened** — changed from
  `null === $slug || '' === $slug` to `!is_string($slug) || '' === trim($slug)`,
  matching `ConsoleResolver`'s pattern and additionally rejecting
  whitespace-only `?_tenant=   ` query strings. Behavior is strictly stricter;
  any tenant slug that survived the prior check survives this one. WR-03.
- **`TenantRunCommand` `@security` trust-boundary docblock** added above the
  `new Process($command)` call to document the array-argv defense (the
  actual shell-injection vector was closed in v0.3.0 by switching from
  `Process::fromShellCommandline()` to array-argv `new Process()`). WR-04.
- **`ZeroConfigKernelBootTest` housekeeping** — dropped the stale
  `@group canary-red` annotation and historical RED-bar framing (the canary
  has been green since plans 18-09/18-10 landed); removed
  `setCatchExceptions(false)` from the `bin/console list` regression test so
  the captured output surfaces in the failure message; added `getmypid()` to
  the cache-dir hash to prevent parallel-PHPUnit cache-dir collisions;
  deduped the parent-directory removal loop in `tearDownAfterClass`.
  IN-01..IN-04.
- **`TenantWorkerMiddleware` explicit `use TenantStamp` import** — the
  class now imports `Tenancy\Bundle\Messenger\TenantStamp` explicitly
  instead of relying on same-namespace resolution; aligns with the rest of
  the bundle's import style and future-proofs the class against a stamp
  relocation. IN-05.
- **`examples/saas/bin/smoke.sh` per-tenant mailer assertion** — the demo
  smoke script now POSTs `/_demo/send-test-mail` for acme + globex, queries
  Mailpit's `/api/v1/messages` API, and asserts distinct `From:` addresses
  per tenant via `jq -e`. A regression in `TenantMessageDecorator` that
  broke per-tenant `From:` injection would now fail demo-smoke CI loudly
  instead of being caught only by human UAT. Closure of the audit's
  "smoke.sh has no per-tenant mailer assertion" tech-debt item.

## [0.3.2] — 2026-05-22

Phase 21 live-stack pass 3 hardening. The demo app would not boot end-to-end
on a fresh clone before this release; seven docker-layout + Doctrine ORM 3
+ FrankenPHP integration bugs were discovered only when `docker compose up`
was finally run as part of phase verification. All seven are fixed here.

### Changed

- **`Tenant` entity split into `AbstractTenant` + concrete `Tenant`** —
  Doctrine ORM 3 refuses two `#[ORM\Entity]` root classes pointing at the
  same `tenancy_tenants` table, which prevented the demo's `DemoTenant`
  from extending the bundle's `Tenant`. The split moves the slug/name/active
  column definitions onto an `#[ORM\MappedSuperclass]` abstract base
  (`Tenancy\Bundle\AbstractTenant`); the concrete bundle `Tenant` becomes
  a thin `#[ORM\Entity]` carrying nothing of its own. **Custom tenant
  entities MUST extend `AbstractTenant`, not `Tenant`** — see `UPGRADE.md`
  § 0.3.1 → 0.3.2 for the trivial migration. BOOT-01.

### Fixed

- **Demo Composer path-repo broken inside Docker** — `examples/saas/composer.json`
  declared a path repository at `../../` which resolved to the bundle root
  on the host but to `/` inside the container, silently falling through to
  Packagist's published `v0.3.1` and running the demo against the published
  tag instead of the dev tree. Fixed by mirroring the host layout in the
  container: `/srv/bundle/` (bundle root) + `/srv/bundle/examples/saas/`
  (demo root). BOOT-02.
- **Stale `wrapper_class:` reference in demo `doctrine.yaml`** — pointed at
  `Tenancy\Bundle\Doctrine\TenantConnection`, a class removed in v0.2.0
  when the bundle migrated to DBAL 4 `TenantDriverMiddleware` connection
  switching. Removed. BOOT-03.
- **`final class Post` rejected by Doctrine ORM 3 lazy-ghost proxy
  generation** in the demo. Removed `final`. BOOT-04.
- **Demo `config/services.yaml` missing** — controllers had no
  autoconfiguration, every route returned 500 `"has no container set"`.
  Added the standard Symfony services skeleton with controller resource
  tagging. BOOT-05.
- **`bin/console` returned Kernel instead of Application** — Symfony
  Runtime under FrankenPHP's `SERVER_NAME` env then dispatched the CLI as
  an HTTP request and threw `Invalid Host ":80,"`. Fixed by returning
  `Application` from `bin/console` and wrapping the entrypoint script in
  `env -u SERVER_NAME` as belt-and-braces. BOOT-06.
- **Caddyfile served HTTPS only** — `tls internal` on the wildcard block
  meant `smoke.sh` (plain HTTP) and the Dockerfile healthcheck both got
  redirects they could not follow. Split into explicit `http://` and
  `https://` site blocks. BOOT-07.

### Changed (demo packaging)

- **Mailpit UI port parameterized** — `${PORT_MAILPIT_UI:-8025}` in
  `compose.yaml`, `.env`, and `.env.example` so the demo coexists with
  other dev stacks on the same host. `smoke.sh` now accepts a `BASE_PORT`
  env override for the same reason.

## [0.3.1] — 2026-05-22

### Fixed

- **CI `prefer-lowest` matrix:** `RecordingLogger` (test-suite helper) declared
  `log($level, string|\Stringable $message, array $context = []): void`,
  which is incompatible with PSR-3 v1.x's
  `LoggerInterface::log($level, $message, array $context = []): void`. PHP's
  LSP rules reject the stricter child signature, killing the whole test
  suite with a fatal on autoload when CI installed psr/log ^1 on PHP 8.2
  + Symfony 7.4. Fixed by dropping the union type from the PHP signature
  and documenting the runtime contract in PHPDoc — the signature is now
  contravariant-wider than both psr/log v1 (no type) and v3
  (`string|\Stringable`), accepted by both. No production code affected;
  this only unblocks the prefer-lowest CI job on PHP 8.2 / Symfony 7.4.

## [0.3.0] — 2026-05-22

Adoption Surface — batch 1. Ships the SPA-friendly Origin-header resolver, the one-command `tenancy:install` setup flow, and a critical fix for a zero-config kernel-boot regression that affected every prior 0.x tag.

### Added

- **`OriginHeaderResolver`** — SPA-friendly tenant resolver that reads the browser-set
  `Origin` HTTP header, matches it against a configurable allow-list under
  `tenancy.origin.allow_list`, and resolves the tenant. Registered in the resolver chain
  at priority 25 (above `HeaderResolver` 20, below `HostResolver` 30). Opt-in via
  `tenancy.resolvers: ['…', 'origin']`. Supports explicit `{origin, slug}` map entries
  and wildcard shorthand `'https://*.app.example.com'` (slug = leftmost label).
  CORS preflight (`OPTIONS`) requests pass through cleanly; mismatches with
  `X-Tenant-ID` are recorded as `warning`-level PSR-3 log entries with structured
  context. See `docs/user-guide/origin-header-resolver.md` § Trust Model — Origin is
  a routing hint, not an authentication credential.
- **`OriginHeaderResolverConfigPass`** — compile-time guard that rejects empty
  allow-lists, unparseable origin URLs, mid-string wildcards, multi-label wildcards,
  path/query/fragment-bearing origins, and non-wildcard entries missing an explicit
  slug. Misconfiguration fails at container build, not at runtime.
- **`tenancy:install` console command** — one-command bundle setup for fresh
  Symfony apps. Runs `composer require danplaton4/tenancy-bundle && bin/console
  tenancy:install` and the bundle is registered + configured with zero manual
  `config/bundles.php` editing. Uses `nikic/php-parser` (declared `require-dev`
  + `suggest` only — never `require`) to AST-detect a Flex-canonical
  `bundles.php` shape; refuses to mutate non-standard shapes (DDD
  `registerBundles()` override, env-conditional registration, parser-rejected
  files) and prints a clean copy-paste snippet — refusal is a clean exit, not a
  tool failure. On the standard shape: takes a timestamped
  `config/bundles.php.bak.YYYYMMDD-HHMMSS` sidecar BEFORE write, atomic write
  via `Symfony\Component\Filesystem\Filesystem::dumpFile()`, post-mutation
  `php -l` syntax check, automatic restore via `Filesystem::copy()` (NOT
  `rename` — the `.bak` outlives every failure path) on lint failure. Then
  programmatically delegates to `tenancy:init` (forwarding `--force`) for a
  single continuous transcript. Supports `--dry-run` (preview-only; no write,
  no `tenancy:init` invocation) and `--force` (forwarded to `tenancy:init` to
  permit overwrite of an existing `tenancy.yaml`); the two flags are mutually
  exclusive (exit code 2 on conflict). Fixture corpus of ≥6 real-world
  `bundles.php` shapes (Symfony skeleton, API Platform, Sulu CMS,
  DDD-override, with-comments, env-conditional) plus a malformed sample
  gates the AST detector in CI. Implements DEC-INST-01 (programmatic
  delegation) and DEC-INST-02 (nikic-detect + refuse-on-nonstandard). Closes
  DX-06.
- **`BundlesPhpInstaller`** — `final` collaborator powering `tenancy:install`.
  Pure value-returning detector + writer with a typed `InstallResult`
  (`WROTE` / `ALREADY_REGISTERED` / `REFUSED_NON_STANDARD` /
  `LINT_FAILED_RESTORED` / `DEV_DEPENDENCY_MISSING` enum cases). Unit-testable
  against the fixture corpus without a kernel boot.

### Fixed

- **Zero-config kernel boot regression** — bundle now constructs cleanly with no
  `tenancy:` config block present (e.g. immediately after `composer require` on a
  fresh Symfony skeleton before `bin/console tenancy:install` has been run).
  - **Root cause:** 6 service classes were wired with
    `service('tenancy.provider')->nullOnInvalid()` in `config/services.php` but
    declared their `TenantProviderInterface` constructor parameter as non-nullable.
    On a zero-config install where no `tenancy:` extension block is loaded,
    `tenancy.provider` is absent and `nullOnInvalid()` resolves to `null`. PHP 8.x
    strict typing then throws `TypeError` during `cache:clear` (or any subsequent
    `bin/console` invocation), making `bin/console tenancy:install` unreachable.
  - **Fix — read-only resolver sites (fail-silent):** `HostResolver`,
    `HeaderResolver`, `QueryParamResolver`, and `ConsoleResolver` now declare
    `?TenantProviderInterface` and return `null` / early-return void at the top of
    their active method when the provider is absent. The resolver chain falls
    through to null-resolution, which the system already handles.
  - **Fix — write-path sites (fail-loud):** `TenantRunCommand` and
    `TenantWorkerMiddleware` now declare `?TenantProviderInterface` and throw
    `MissingTenantProviderException` (extends `\LogicException`) with an
    actionable message directing the user to `bin/console tenancy:install` when
    invoked without a configured provider. `\LogicException` (NOT
    `\RuntimeException`) is used deliberately: Symfony Messenger's default retry
    strategy treats `RuntimeException` as a retryable transient fault, which
    would silently re-queue a misconfigured worker until the retry cap. Silent
    no-op on the write path would risk data-correctness issues; fail-loud is
    the safer policy.
  - **Versions affected:** v0.1.0, v0.2.0, v0.2.1 — all users on those tags should
    upgrade. The defect predates Phase 18 and was discovered during human UAT on
    2026-05-21.
  - **Regression coverage:** `tests/Integration/ZeroConfigKernelBootTest.php` now
    exercises the previously-uncovered zero-config code path (container compile,
    resolver instantiation, `bin/console list` exit 0) as a permanent regression
    gate. A new contract test
    (`tests/Unit/Container/NullableProviderInjectionContractTest.php`) reflects
    on every `tenancy.provider->nullOnInvalid()` consumer in `services.php` and
    asserts the matching constructor param is `?TenantProviderInterface`,
    locking the invariant against drift. Closes DX-06. Audit source:
    `.planning/phases/18-tenancy-install/18-VERIFICATION.md`.

- **`tenancy:run` shell-injection vector** — the `command_string` argument was
  previously interpolated into a `Process::fromShellCommandline()` line, where
  shell metacharacters (`;`, `&&`, `|`, `$(...)`, backticks, redirects) in the
  argument would be interpreted by the shell. Callers passing untrusted input
  could execute arbitrary commands. Fixed by switching to `new Process(array)`
  with whitespace-tokenized argv; metacharacters now land as literal characters
  in individual tokens. Trade-off: `command_string` no longer supports
  shell-quoted args with embedded spaces — pass each token separated by
  whitespace, or use the Symfony Process API directly for complex argv.
  Regression coverage:
  `TenantRunCommandTest::testShellMetacharactersAreInertInCommandString`.

## [0.2.1] — 2026-04-21

### Fixed

- **DI bundle extension guard** (`TenancyBundle::loadExtension`): the `tenancy.database.enabled: true`
  guard introduced in 0.2.0 (code-review finding WR-05) used `class_exists(\Doctrine\DBAL\Driver\Middleware::class)`
  to detect DBAL presence. `Middleware` is an **interface**, and PHP's `class_exists()` returns `false` for
  interfaces — so the guard fired unconditionally whenever `database.enabled: true` was set, throwing a
  bogus `LogicException` even in environments where Doctrine DBAL was fully installed. Fixed by using
  `interface_exists()` to match the actual symbol type. This broke container boot for all
  database-per-tenant consumers in 0.2.0; upgrading to 0.2.1 is strongly recommended.

## [0.2.0] — 2026-04-20

Retrospective: v1.0.0 was tagged on 2026-04-12 but retracted the same day after four
defects surfaced in downstream demo projects. The line was restarted at v0.1.0. Phase 15
applied the four fixes as architectural corrections (not surface patches); v0.2.0 is
where the architecture finally settles.

### Changed

- **ResolverChain::resolve()** now returns `?TenantResolution` (nullable) instead of
  throwing `TenantNotFoundException` when no resolver matches. Public/landlord routes
  proceed with an empty `TenantContext`. `TenantNotFoundException` is narrowed to
  "provider-rejected identifier" (`DoctrineTenantProvider::findBySlug` is the only
  remaining thrower). Closes #6.
- **Database-per-tenant connection switching** migrated from DBAL `wrapperClass` +
  reflection to `Doctrine\DBAL\Driver\Middleware`. `TenantDriverMiddleware` +
  `TenantAwareDriver` intercept `connect()` per-tenant; `DatabaseSwitchBootstrapper::boot()`
  reduces to `$connection->close()`. Closes #7, #8.
- **`tenancy:init`** command now emits a sample `doctrine.yaml` (MySQL driver family)
  alongside the tenancy.yaml stub, with a driver-family-match callout.
- **Documentation:** `docs/architecture/dbal-wrapper.md` renamed/rewritten as
  `dbal-middleware.md`; user-guide `database-per-tenant`, `configuration`, `installation`,
  `getting-started`, `testing`, and examples updated to the middleware model. The
  `sqlite://` placeholder pattern for non-SQLite tenants is gone.

### Fixed

- **`TenantAwareCacheAdapter`** now implements the full `cache.app` substitution surface
  (`AdapterInterface`, `CacheInterface`, `NamespacedPoolInterface`, `PruneableInterface`,
  `ResettableInterface`). A sibling `TenantAwareTagAwareCacheAdapter` covers
  `cache.app.taggable`. Fresh Symfony 7.4 projects that run
  `composer require doctrine/orm doctrine/doctrine-bundle danplaton4/tenancy-bundle` can
  now `bin/console cache:clear` without TypeError. Closes #5.
- New compiler pass `CacheDecoratorContractPass` fails container compilation with a clear
  `LogicException` if the decorator is missing any `Symfony\*` interface the decorated
  service exposes. Prevents this class of regression from re-landing.

### Removed

- `Tenancy\Bundle\DBAL\TenantConnection` — deleted. (v0.1 had 2 Packagist downloads, both
  self; no external users.)
- `Tenancy\Bundle\DBAL\TenantConnectionInterface` — deleted.
- `tests/Unit/DBAL/TenantConnectionTest.php` — deleted.
- `tests/Integration/DatabaseSwitchIntegrationTest.php` — deleted (superseded by
  `tests/Integration/DBAL/DatabasePerTenantMiddlewareIntegrationTest.php`).
- `wrapper_class: TenantConnection::class` from test kernels' YAML.

### Migration

See `UPGRADE.md` § 0.1 → 0.2 for details. In short:

- No user action required for Fix #5.
- Fix #6 is a behavior change if you caught `TenantNotFoundException` in a
  `kernel.exception` listener for the "no resolver matched" case.
- Fix #7 + #8 require removing `wrapper_class:` from `doctrine.yaml` (if you had it) and
  ensuring your tenant connection's `driver:` matches your tenant database's driver
  family.

### Tooling

- `scripts/docs-lint.sh` — new CI-grade script that fails non-zero when post-v0.2 docs
  contain stale references (`wrapperClass`, `ReflectionProperty`, `sqlite://`,
  `TenantConnection`). Scoped to `docs/` + `src/Command/TenantInitCommand.php`.

## [0.1.0] - 2026-04-19

Initial public release. Multi-tenancy for Symfony with zero boilerplate and zero leaks.

> **Note on versioning**: Previously tagged as `v1.0.0` (2026-04-12) but never publicly released — the v1.0.0 tag was removed because four architectural issues (cache decorator contract, resolver optionality, DBAL 4 connection switching) surfaced in downstream demo projects before the tag was advertised. The codebase has been restarted from `0.x` until those issues are resolved.

### Added

- **Core Foundation**
  - `TenantContext` zero-dependency value holder for active tenant state
  - `TenantInterface` and `TenantBootstrapperInterface` contracts
  - `BootstrapperChain` with compiler pass autoconfiguration (`tenancy.bootstrapper` tag)
  - Lifecycle events: `TenantResolved`, `TenantBootstrapped`, `TenantContextCleared`
  - `TenantContextOrchestrator` kernel.request listener at priority 20
  - `Tenant` Doctrine entity with slug primary key

- **Tenant Resolution**
  - `HostResolver` — subdomain and custom domain resolution (priority 30)
  - `HeaderResolver` — `X-Tenant-ID` header resolution (priority 20)
  - `QueryParamResolver` — `?_tenant=` query parameter (priority 10)
  - `ConsoleResolver` — `--tenant=` CLI flag on ConsoleCommandEvent
  - `ResolverChain` with pluggable priority-based ordering via compiler pass
  - `DoctrineTenantProvider` with cache-then-check lookup pattern

- **Database-Per-Tenant Isolation**
  - `TenantConnection` DBAL 4 wrapperClass with runtime connection switching via reflection
  - `DatabaseSwitchBootstrapper` for tenant boot/clear delegation
  - `EntityManagerResetListener` to prevent identity map pollution across tenants
  - Dual Entity Manager configuration: `landlord` (central) + `tenant` (swappable)
  - Conditional DI wiring via `tenancy.database.enabled` config flag

- **Shared-Database Isolation**
  - `#[TenantAware]` PHP attribute for marking Doctrine entities
  - `TenantAwareFilter` Doctrine SQL filter with 4-branch logic (scoped/empty/strict/permissive)
  - `SharedDriver` bootstrapper to inject tenant context into the filter
  - Strict mode on by default — `TenantMissingException` when querying without active tenant
  - Validation blocking `shared_db` + `database.enabled` config conflict

- **Infrastructure Bootstrappers**
  - `DoctrineBootstrapper` — clears EM identity map on boot/clear (priority -10)
  - `TenantAwareCacheAdapter` — decorates `cache.app` with per-tenant namespace isolation via `withSubNamespace()`

- **Messenger Integration**
  - `TenantStamp` carrying tenant slug across process boundaries
  - `TenantSendingMiddleware` — attaches stamp on dispatch
  - `TenantWorkerMiddleware` — restores context on consume with try/finally teardown
  - `MessengerMiddlewarePass` compiler pass auto-enrolling both middlewares in all buses

- **CLI Commands**
  - `tenancy:migrate` — sequential per-tenant Doctrine migrations with `--tenant=` filter
  - `tenancy:run` — wraps any console command with tenant context via subprocess

- **Developer Experience**
  - `InteractsWithTenancy` PHPUnit trait with `initializeTenant()`, automatic tearDown cleanup
  - Assertion helpers: `assertTenantActive()`, `assertNoTenant()`, `getTenantService()`

- **OSS Tooling**
  - Symfony Flex recipe with auto-registration and `config/packages/tenancy.yaml` stub
  - GitHub Actions CI: PHP 8.2/8.3/8.4 x Symfony 7.4/8.0 matrix
  - PHPStan level 9 enforcement, php-cs-fixer with `@Symfony` ruleset
  - CI jobs for no-Doctrine, no-Messenger, and prefer-lowest dependency validation
  - Codecov coverage reporting

[Unreleased]: https://github.com/danplaton4/tenancy-bundle/compare/v0.3.3...HEAD
[0.3.3]: https://github.com/danplaton4/tenancy-bundle/compare/v0.3.2...v0.3.3
[0.3.2]: https://github.com/danplaton4/tenancy-bundle/compare/v0.3.1...v0.3.2
[0.3.1]: https://github.com/danplaton4/tenancy-bundle/compare/v0.3.0...v0.3.1
[0.3.0]: https://github.com/danplaton4/tenancy-bundle/compare/v0.2.1...v0.3.0
[0.2.1]: https://github.com/danplaton4/tenancy-bundle/compare/v0.2.0...v0.2.1
[0.2.0]: https://github.com/danplaton4/tenancy-bundle/releases/tag/v0.2.0
[0.1.0]: https://github.com/danplaton4/tenancy-bundle/releases/tag/v0.1.0
