# Phase 7: CLI Commands - Context

**Gathered:** 2026-03-19
**Status:** Ready for planning

<domain>
## Phase Boundary

Two console commands: `tenancy:migrate` runs Doctrine migrations sequentially for all tenants in database_per_tenant mode; `tenancy:run {tenantSlug} "command:name [args]"` executes any Symfony console command scoped to a specific tenant. No parallel migration, no restore, no scheduling — those are future phases.

</domain>

<decisions>
## Implementation Decisions

### Tenant discovery for tenancy:migrate
- Add `findAll(): array` to `TenantProviderInterface` — returns all tenants (active and inactive TBD by implementation)
- `DoctrineTenantProvider` implements it by querying the landlord EntityManager
- This keeps the command decoupled from Doctrine — it uses the provider abstraction, not the EM directly

### Error handling in tenancy:migrate
- Continue processing ALL tenants even when one fails
- Print a per-tenant status line as each completes: `✓ acme` / `✗ beta (message)`
- Print a summary table at the end: succeeded count, failed count, list of failures
- Exit code 1 if any tenant failed, 0 if all succeeded
- No `--stop-on-failure` flag in v1 — operators see the full picture by default

### tenancy:run execution mechanism
- Spawn `bin/console {command} {args} --tenant={slug}` as a subprocess via Symfony's `Process` component
- `ConsoleResolver` already handles `--tenant` on `ConsoleEvents::COMMAND` — this wires for free
- Full process isolation: no shared state, no memory leaks between tenant runs
- Forward stdout/stderr from child process to parent output
- Exit with child process exit code

### Driver scope guard for tenancy:migrate
- Check configured driver at runtime in `execute()`
- If driver is `shared_db`: output `tenancy:migrate is only available with the database_per_tenant driver` to stderr and exit 1
- Fail loudly — silent skip or warning would hide misconfiguration in CI pipelines

### doctrine/migrations dependency
- `tenancy:migrate` depends on `doctrine/migrations` — add to `suggest` block in `composer.json`
- Guard the command registration with `class_exists(\Doctrine\Migrations\DependencyFactory::class)` or equivalent
- If doctrine/migrations is absent: command is not registered (not just erroring at runtime)

### Output style
- `tenancy:migrate`: verbose table output by default — operators need visibility; respect `-q` for CI silent mode
- `tenancy:run`: transparent passthrough — child output goes directly to parent, no wrapping

### --tenant filter for tenancy:migrate (confirmed in-scope 2026-04-02)
- `tenancy:migrate` MUST accept `--tenant=<slug>` to run migrations for a single tenant only
- When `--tenant` is provided: resolve tenant by slug via `TenantProviderInterface::findBySlug()`, run migrations only for that tenant
- When `--tenant` is provided and tenant not found: exit 1 with error message

### Claude's Discretion
- Exact output formatting (column widths, color usage)
- Whether `findAll()` returns only active tenants or all tenants (active + inactive)
- How `tenancy:run` finds the `bin/console` binary (PHP_BINARY + script path pattern)

</decisions>

<specifics>
## Specific Ideas

- `tenancy:run` argument form is `tenancy:run {tenantSlug} "command:name arg1 arg2"` — slug first, then command string
- The subprocess approach means `tenancy:run` is safe to use in scripts and cron jobs without worrying about state leakage

</specifics>

<canonical_refs>
## Canonical References

No external specs — requirements are fully captured in decisions above and the REQUIREMENTS.md entries below.

### Requirements
- `.planning/REQUIREMENTS.md` §CLI Commands — CLI-01 (`tenancy:migrate`) and CLI-02 (`tenancy:run`) requirement entries

### Existing code to read before planning
- `src/Provider/TenantProviderInterface.php` — interface to extend with `findAll()`
- `src/Provider/DoctrineTenantProvider.php` — concrete implementation to update
- `src/Resolver/ConsoleResolver.php` — already handles `--tenant` option on `ConsoleEvents::COMMAND`; `tenancy:run` subprocess reuses this
- `config/services.php` — DI registration pattern for new command services
- `src/TenancyBundle.php` — `class_exists` guard pattern (see MessengerMiddlewarePass registration) for conditional command registration
- `composer.json` — suggest block pattern for optional dependencies

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- `ConsoleResolver`: handles `--tenant=slug` resolution on any command via `ConsoleEvents::COMMAND`. `tenancy:run` gets tenant context for free by spawning a subprocess with `--tenant=` appended.
- `BootstrapperChain` + `TenantContext`: full lifecycle already available — `tenancy:migrate` uses these directly to boot each tenant before running migrations.
- `DoctrineTenantProvider`: already queries landlord EM with caching — `findAll()` can use the same EM, but should bypass the cache (or use a short TTL) since migrate is an operator tool.
- `getConnectionConfig()` on `TenantInterface`: provides the DBAL connection parameters needed to run migrations against each tenant's database.

### Established Patterns
- `final class` + `private readonly` constructor injection — all bundle classes follow this.
- `class_exists` / `interface_exists` guard before registering optional-dependency services (see `config/services.php` Messenger block).
- `#[AsCommand]` attribute for Symfony console command registration.
- Atomic commits per task (GSD convention) — each file group committed separately.

### Integration Points
- `TenantProviderInterface::findAll()` — new method; both `DoctrineTenantProvider` and any custom provider must implement it.
- `config/services.php` — new service definitions for `TenantMigrateCommand` and `TenantRunCommand`, guarded by `class_exists` for migrations.
- `TenancyBundle::loadExtension()` or `services.php` — register commands so they appear in `bin/console list tenancy`.

</code_context>

<deferred>
## Deferred Ideas

- `--stop-on-failure` flag for `tenancy:migrate` — explicitly out of scope for v1; continue-all is the default behavior
- Parallel `tenancy:migrate` via `symfony/process` (ISOL-07 in backlog) — v1.1 feature
- ~~`--tenant=acme` single-tenant targeted run for `tenancy:migrate`~~ — promoted to in-scope 2026-04-02 (see Decisions above)
- `tenancy:restore` command — separate phase entirely

</deferred>

---

*Phase: 07-cli-commands*
*Context gathered: 2026-03-19*