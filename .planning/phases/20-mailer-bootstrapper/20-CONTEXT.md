# Phase 20: Mailer Bootstrapper - Context

**Gathered:** 2026-05-19
**Status:** Ready for planning

<domain>
## Phase Boundary

Deliver per-tenant Mailer dispatch that is correct under BOTH synchronous Mailer
and Messenger-routed async dispatch. When tenant A's HTTP context dispatches an
email, the SMTP transport actually used (in the worker, even after the HTTP
context has cleared) is tenant A's — never the landlord's, never another
tenant's.

Concretely, this phase ships:

1. `MailerBootstrapper` — implements `TenantBootstrapperInterface`; optional dep
   guarded by `interface_exists(\Symfony\Component\Mailer\MailerInterface::class)`.
2. `TenantTransportFactoryDecorator` — lazy per-tenant SMTP transport resolution
   keyed off `X-Transport: tenant_<slug>`, backed by an LRU cache (default 32).
3. `TenantMessageDecorator` — `MessageEvent` listener that stamps
   `X-Transport: tenant_<slug>` AND sets the tenant's `From` / `Reply-To` headers
   BEFORE Messenger serialization (so the stamp survives the broker round-trip).
4. `MailerTransportContractPass` — compile-time guard: fails the build when the
   bootstrapper is enabled but no transport strategy is configured, and when
   Mailer is routed async without the `x_transport` strategy.
5. `TenantMailerConfigTrait` — default impl of the three new `TenantInterface`
   methods (`getMailerDsn`, `getMailerFrom`, `getMailerReplyTo`).
6. `Tenant` entity gains `mailerDsn` (nullable), `mailerFrom` (nullable),
   `mailerReplyTo` (nullable) columns + landlord migration recipe in `UPGRADE.md`.
7. `SanitizingMailerDecorator` — wraps `TransportException` to redact the DSN
   password component from the message and trace before propagation.
8. Async canary test — dispatches in tenant A's HTTP context, runs the worker in
   a clean context, asserts the captured SMTP DSN matches tenant A (not landlord).
9. **Profiler panel mailer section** (extends Phase 19's Tenancy WDT panel) —
   shows the active tenant's `mailerFrom`, redacted `mailerDsn`, transport cache
   size + hit count, and the resolved strategy (`x_transport` / async-detected
   true|false). In-scope as part of the BOOT-04 user-visible surface so devs
   can spot misconfigs without leaving the WDT.
10. **`tenancy:install --with-mailer`** (extends Phase 18's install command) —
    interactive flag that scaffolds the mailer migration into `migrations/`,
    inserts `use TenantMailerConfigTrait;` into the user's `Tenant` entity (or
    refuses with a manual snippet if the entity layout is non-standard,
    mirroring the `bundles.php` handling from DEC-INST-02), and prints next
    steps. Removes the manual-migration friction that would otherwise be the
    main install-funnel blocker for v0.3.

**Not in this phase:** Mailer template per-tenant overrides, queue prioritization
per tenant, bounce-handling hooks, IMAP/POP3 inbox-per-tenant, tenant-creation-
time DSN validation — those are genuinely new capabilities and belong in their
own future phases (v0.4+).

</domain>

<decisions>
## Implementation Decisions

### Carried Forward (LOCKED in MILESTONES.md — do not re-decide)
- **DEC-MAIL-01:** `X-Transport` header strategy + `MessageEvent` listener for
  `From`/`Reply-To` headers — the chosen extension mechanism.
- **DEC-MAIL-02:** `mailerDsn` nullable column on `Tenant` (full BOOT-04 scope
  shipped in v0.3, not deferred).
- **DEC-MAIL-03:** `getMailerDsn()` added to `TenantInterface` — BC break,
  mitigated by `TenantMailerConfigTrait` and documented in `UPGRADE.md`
  `0.2 → 0.3`. **Expanded this phase:** the BC-break surface grows from 1 to 3
  interface methods (see D-02 below) — the same trait covers all three.

### Transport Provider — Lazy-Only Resolution
- **D-01:** Single mechanism for all deployments: a `TransportFactoryDecorator`
  (or `Transports`-registry decorator — researcher to confirm exact integration
  point) intercepts `tenant_<slug>` lookups, calls
  `TenantProviderInterface::findBySlug($slug)`, builds the SMTP transport from
  the tenant's `mailerDsn`, and caches it in the LRU.
  - **Rationale:** Hybrid solutions are hard to adopt — users have to decide
    whether to implement the warmup interface, and the wrong choice has
    runtime cost implications. Lazy-only is one mechanism that works for 1
    tenant or 10k+ tenants, with no decision burden on users. First-send-per-
    tenant cost (DB lookup + transport construction) is amortized by the LRU
    cache (D-03) and is a non-issue once a worker has been processing for any
    meaningful duration.
  - **No new interface:** No `TenantTransportProviderInterface` ships in v0.3.
    The existing `TenantProviderInterface::findBySlug()` is the contract.
  - **Researcher must confirm:** Whether the integration point is decorating
    Symfony's `Transports` registry, extending `TransportFactoryInterface`, or
    registering a custom scheme like `tenancy+smtp://` — all three are
    plausible; only one is idiomatic for Symfony 7.x.

### Tenant Header Source — Dedicated Columns
- **D-02:** `From` and `Reply-To` come from dedicated entity columns and
  interface methods, not from the DSN query string.
  - **Tenant entity:** gains `mailerFrom` (string, NOT NULL when bootstrapper
    enabled — enforced via doctrine-level constraint OR runtime check on
    `MessageEvent`, planner to choose) and `mailerReplyTo` (nullable).
  - **TenantInterface:** gains `getMailerFrom(): ?string` and
    `getMailerReplyTo(): ?string` alongside `getMailerDsn(): ?string`.
  - **`TenantMailerConfigTrait`:** provides default impls for all three —
    a user installing the trait gets `mailerDsn`, `mailerFrom`, `mailerReplyTo`
    properties + getters in one shot.
  - **Rationale:** Cleaner separation — DSN carries transport credentials,
    columns carry sender identity. DSNs sometimes appear in logs/traces (even
    after sanitization) and embedding sender addresses there couples concerns.
    Columns are queryable and indexable. Matches what stancl/tenancy does in
    Laravel (separate `data` columns for sender config). The slight BC-break
    surface increase is contained — the trait absorbs it.

### Transport Cache — Configurable LRU
- **D-03:** Per-tenant transport cache is a configurable LRU, default size 32.
  - **Config key:** `tenancy.mailer.transport_cache_size` (default `32`).
  - **Eviction:** LRU on size; full clear on `TenantContextCleared` event.
  - **Connection lifecycle:** When a transport is evicted (LRU OR full clear),
    the underlying SMTP connection MUST be closed cleanly. Researcher to
    confirm Symfony's `SmtpTransport` exposes a `close()` / `stop()` method or
    whether destructor invocation is sufficient.
  - **Rationale:** 32 covers the realistic worker fanout in multi-tenant
    fleets. Unbounded is rejected by roadmap success criterion 6 (socket-leak
    prevention via long-running-worker simulation test of 100 distinct
    tenants). Hard-coded 16 is too small for high-tenant-throughput workers
    and pushes calibration onto users. Configurable default 32 hits the
    middle.

### Async-Routing Guard — Auto-Detect with Override
- **D-04:** `MailerTransportContractPass` defaults to auto-detection of async
  Mailer routing, with an explicit override config flag.
  - **Detection logic:** Inspect `framework.messenger.routing` for entries
    mapping `Symfony\Component\Mailer\Messenger\SendEmailMessage` to any
    transport. If present, async routing is on; `x_transport` strategy is
    required.
  - **Config key:** `tenancy.mailer.async: auto|true|false` (default `auto`).
    - `auto`: use detection logic above.
    - `true`: force async-mode contract regardless of detection.
    - `false`: skip async-mode contract enforcement (escape hatch for users
      with custom routing or non-standard message classes).
  - **Rationale:** Mirrors the bundle's `tenancy.driver` pattern — auto-detect
    by default, overridable for edge cases. The compiler pass is the source of
    truth for the 95% case (users with standard mailer + messenger config);
    the override exists for the 5% (custom `SendEmailMessage` subclasses,
    non-standard routing). Zero-config-by-default DX is preserved.

### Strategy: Always-On Registration (Bundle Pattern)
- **D-05:** `MailerBootstrapper`, `TenantMessageDecorator`, the
  `TransportFactoryDecorator`, and `SanitizingMailerDecorator` are all
  registered unconditionally in DI, guarded by `interface_exists` checks for
  `MailerInterface` and `MessageEvent`. No `tenancy.mailer.enabled` flag.
  - **Rationale:** Consistent with `MessengerMiddlewarePass`, `DoctrineBootstrapper`,
    and `TenantAwareCacheAdapter` — all auto-register when their target package
    is installed. Zero-config is a core DX promise.
  - **`MailerTransportContractPass`** still runs and enforces strategy
    correctness — installing `symfony/mailer` without configuring a transport
    strategy is a hard compile-time error with a clear message pointing to the
    docs.

### DSN Sanitization
- **D-06:** A `SanitizingMailerDecorator` wraps the `MailerInterface` service.
  On caught `TransportException`, it:
  1. Redacts the password component from the exception message using regex
     pattern `(://[^:]+:)[^@]+(@)` → `$1***$2`.
  2. Wraps in a `TenantSanitizedTransportException` (extends `TransportException`)
     so the original exception type contract is preserved for users catching
     `TransportException` upstream.
  3. Strips DSN from any user-supplied trace context if the planner identifies
     additional leak vectors (e.g. Mailer transport debug listeners).
  - **Rationale:** Roadmap criterion 5 is non-negotiable; this is the
    minimum-surface implementation. Decorator pattern keeps the wiring
    transparent.

### Profiler Panel Mailer Section (D-08)
- **D-08:** The Tenancy WDT panel (shipped in Phase 19) gains a "Mailer"
  subsection rendered in dev only (`kernel.debug=true`). It shows:
  - Active tenant's `mailerFrom` and `mailerReplyTo` (raw).
  - Active tenant's `mailerDsn`, redacted via the same sanitization helper
    used by `SanitizingMailerDecorator` (D-06) — single source of truth.
  - Transport cache: current size, configured max, hit count, eviction count.
  - Resolved strategy: `x_transport` (always) + async-detected (`auto`
    detection result OR explicit user setting).
  - State badge: `OK` (DSN configured), `MISSING` (bootstrapper enabled but
    tenant has no `mailerDsn`), `ERROR` (last send failed — surfaces the
    sanitized exception message).
  - **Rationale:** Phase 19 shipped the panel + data collector pattern; adding
    a mailer subsection is a small surface extension that catches misconfigs
    in dev before they reach prod. Competitive edge — `stancl/tenancy` has no
    equivalent (Laravel Telescope/Debugbar don't pivot on tenant + mailer
    cross-section).
  - **Implementation:** A new collector method on the existing Tenancy
    profiler collector — no new template scaffolding, just an additional
    section in the existing Twig panel template. Researcher to confirm the
    collector contract from Phase 19.

### `tenancy:install --with-mailer` Extension (D-09)
- **D-09:** `tenancy:install` (shipped in Phase 18) gains a `--with-mailer`
  flag. When passed (interactively or non-interactively):
  - Scaffolds a Doctrine migration into the user's configured migration
    directory adding `mailer_dsn`, `mailer_from`, `mailer_reply_to` columns
    to the `tenancy_tenants` table. If `doctrine/migrations` is not
    installed, prints the SQL snippet for manual application + exits 0.
  - Edits the user's `Tenant` entity (path resolved from
    `tenancy.tenant_entity_class` config) to add `use TenantMailerConfigTrait;`
    on the class body. Uses `nikic/php-parser` (already a Phase 18 dep per
    DEC-INST-02) to detect non-standard entity layouts; on detection,
    refuses to mutate, prints the manual `use` statement to add, exits 0.
  - Updates the user's `config/packages/tenancy.yaml` to add the
    `mailer` section with `transport_cache_size: 32` and `async: auto` —
    commented-out defaults so the values are visible but not yet active.
  - Prints next steps: "Run `bin/console doctrine:migrations:migrate` then
    set `mailerDsn` on each tenant via your seeding / admin flow."
  - **Rationale:** The migration + trait-import + config edit is the install-
    funnel pain point that would otherwise block 80% of users adopting BOOT-04.
    Phase 18's `tenancy:install` is the obvious home — the command already
    knows how to scaffold migrations and mutate config files via the parser
    pattern. Folding this in keeps the v0.3 "Adoption Surface" milestone
    promise honest.
  - **Default behavior:** `tenancy:install` (no flag) does NOT scaffold mailer
    by default — users opt-in via `--with-mailer` or the interactive prompt.
    Backward-compatible with the Phase 18 install flow.

### Bootstrapper Ordering
- **D-07:** `MailerBootstrapper` runs AFTER `DatabaseSwitchBootstrapper` and
  `DoctrineBootstrapper` (so the tenant's `mailerDsn` can be read from the
  tenant entity — which lives in the landlord DB and is already loaded by the
  resolver, but the ordering principle holds). `clear()` runs in reverse —
  Mailer transport cleanup happens BEFORE EM reset / connection close.
  - **Implementation:** Use `BootstrapperChain`'s priority mechanism. Planner
    to confirm whether explicit priority is needed or registration order is
    sufficient (it is for the existing chain; document either way).

### Claude's Discretion
- Exact integration point for transport decoration (`Transports` registry vs
  `TransportFactoryInterface` extension vs custom scheme) — researcher and
  planner to choose based on Symfony 7.x idioms.
- Exact regex / sanitization helper structure for D-06 — single regex above is
  sufficient for `smtp://`, `smtps://`, `sendmail://+credentialed`, but planner
  to add coverage for other DSN schemes Symfony Mailer supports.
- Test infrastructure for the async canary — likely a `TestTransport` + a
  custom Messenger transport that synchronously dispatches in a forked context,
  but researcher to confirm what Symfony 7.x's `mailer/tests` ecosystem
  provides.
- Migration file format — bundle ships a copy-pasteable migration snippet in
  `UPGRADE.md` (NOT a fully wired migration class shipped as a service);
  `tenancy:install` is NOT extended in this phase to register the migration.

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Phase requirements & decisions
- `.planning/REQUIREMENTS.md` §BOOT-04 — full acceptance criteria (9 items)
- `.planning/ROADMAP.md` §Phase 20: Mailer Bootstrapper — goal + 6 success criteria
- `.planning/MILESTONES.md` §DEC-MAIL-01, DEC-MAIL-02, DEC-MAIL-03 — locked
  cross-phase decisions
- `.planning/PROJECT.md` §Current Milestone — v0.3 Adoption Surface scope and
  the "ship in weeks not months" tight-scope mandate

### Existing codebase — integration points (most relevant first)
- `src/Bootstrapper/TenantBootstrapperInterface.php` — `boot(TenantInterface): void`
  + `clear(): void` contract MailerBootstrapper implements
- `src/Bootstrapper/BootstrapperChain.php` — registration + boot/clear ordering;
  see D-07 for ordering rationale
- `src/Bootstrapper/DatabaseSwitchBootstrapper.php` — closest analog for "tenant
  property → infra reconfig" pattern; also `optional dep + interface_exists`
  reference
- `src/Bootstrapper/DoctrineBootstrapper.php` — second analog; shows the
  zero-config registration + guarded dep pattern
- `src/TenantInterface.php` — interface that gains 3 new methods this phase
- `src/Entity/Tenant.php` — entity that gains 3 new columns
- `src/Messenger/TenantWorkerMiddleware.php` — restores tenant context in
  workers; MailerBootstrapper MUST boot during this restoration (it's part of
  `BootstrapperChain`) so worker-side dispatch sees the correct transport
- `src/Messenger/TenantSendingMiddleware.php` — attaches `TenantStamp` on
  dispatch; the X-Transport header stamping needs to happen BEFORE messenger
  serialization, so `TenantMessageDecorator` must listen on `MessageEvent`
  which fires PRIOR to the sending middleware
- `src/Event/TenantContextCleared.php` — event the transport cache listens to
  for full-clear
- `src/EventListener/TenantContextOrchestrator.php` — canonical teardown
  sequence; mailer cleanup mirrors this pattern
- `src/DependencyInjection/Compiler/MessengerMiddlewarePass.php` — closest
  analog for `MailerTransportContractPass` structure (compile-time validation +
  `interface_exists` guard + zero-config registration)
- `src/DependencyInjection/Compiler/CacheDecoratorContractPass.php` — second
  analog: compile-time contract enforcement with clear error messages
- `src/TenancyBundle.php` — `loadExtension()` where new compiler passes and DI
  wiring are registered; `configure()` where new config keys
  (`mailer.transport_cache_size`, `mailer.async`) are declared
- `src/Exception/` directory — where `TenantSanitizedTransportException` lives

### Symfony Mailer contracts — researcher must investigate
- `Symfony\Component\Mailer\MailerInterface` — the interface
  `SanitizingMailerDecorator` decorates
- `Symfony\Component\Mailer\Transport\TransportInterface` — what per-tenant
  transports implement (built lazily from each tenant's `mailerDsn`)
- `Symfony\Component\Mailer\Transport\Transports` — multi-transport registry;
  the X-Transport routing happens here. Researcher: confirm the public API for
  decorating / extending this class in Symfony 7.x.
- `Symfony\Component\Mailer\Transport\TransportFactoryInterface` — alternate
  integration point; researcher to compare with `Transports` decoration.
- `Symfony\Component\Mailer\Event\MessageEvent` — event the
  `TenantMessageDecorator` listens to; fires for every queued OR sent message.
  Confirm priority constraints relative to Symfony's own listeners.
- `Symfony\Component\Mailer\Messenger\SendEmailMessage` — the message class
  whose presence in `framework.messenger.routing` is the auto-detect signal
  (D-04).
- `Symfony\Component\Mailer\Exception\TransportException` — the exception class
  `SanitizingMailerDecorator` wraps.
- `Symfony\Component\Mailer\Header\TagHeader` and the `X-Transport` header —
  researcher must validate the header survives Messenger serialization across
  ALL supported transports (Doctrine, AMQP, Redis JSON serializer). This is
  explicitly called out in ROADMAP.md as substantial research.

### Prior phase context (read in this order)
- `.planning/phases/05-infrastructure-bootstrappers/05-CONTEXT.md` —
  established "always-on, zero config" bootstrapper DI pattern
- `.planning/phases/06-messenger-integration/06-CONTEXT.md` —
  `TenantStamp`/middleware pair the worker side of this phase relies on; the
  teardown sequence MailerBootstrapper integrates with
- `.planning/phases/02-tenant-resolution/02-CONTEXT.md` —
  `TenantProviderInterface::findBySlug()` semantics (used by the lazy
  transport decorator)
- `.planning/phases/15-architectural-fixes-v0-2/` — FIX-03 driver-middleware
  migration; pattern for "infra reconfig via middleware decoration" reused here

### Test infrastructure
- `tests/Integration/Messenger/` — analogs for the async canary test scaffolding
- `src/Testing/InteractsWithTenancy` (PHPUnit trait) — the per-test tenant
  setup helper; researcher to confirm whether Mailer's `TestTransport` is
  compatible with the trait's clean-context guarantees

### Documentation refs
- `UPGRADE.md` — top of file; this phase appends a `0.2 → 0.3` section
  documenting the 3-method `TenantInterface` BC break + migration recipe
  (install trait OR implement 3 methods + add columns)
- `docs/user-guide/` — researcher/planner to identify exact page that gains a
  new "Per-tenant Mailer" section

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- `TenantProviderInterface::findBySlug(string $slug): TenantInterface` —
  exact method the lazy transport decorator needs; existing cache layer in
  `DoctrineTenantProvider` keeps the per-send DB lookup cost amortized
- `BootstrapperChain::boot()` / `::clear()` — `MailerBootstrapper` plugs in here
  with no new chain machinery needed
- `TenantContextCleared` event — already dispatched in both HTTP and worker
  contexts (orchestrator + worker middleware); the transport cache listens
  here for full-clear
- `interface_exists()` guard pattern — used in `MessengerMiddlewarePass`,
  `CacheDecoratorContractPass`; reuse verbatim for the Mailer pass

### Established Patterns
- `final class` everywhere — `MailerBootstrapper`, decorators, listeners,
  compiler pass all use `final`
- `private readonly` constructor injection
- Always-on DI registration with `interface_exists` guard (see D-05)
- Compiler passes for contract enforcement (`MessengerMiddlewarePass`,
  `CacheDecoratorContractPass`) — `MailerTransportContractPass` follows
  identical structure
- `Tenant` entity is a CONCRETE class users can replace via
  `tenancy.tenant_entity_class` config — this is why the BC break must be
  trait-mitigated: users with custom entities won't auto-inherit new columns
- Bundle DI lives in `loadExtension()` of `src/TenancyBundle.php` —
  configurator pattern with `service()`, `param()`, `Reference` helpers

### Integration Points
- `src/Bootstrapper/MailerBootstrapper.php` — NEW
- `src/Mailer/` — NEW directory for `TenantMessageDecorator`,
  `TransportFactoryDecorator` (or `TransportsDecorator`), `LruTransportCache`,
  `SanitizingMailerDecorator`
- `src/Mailer/TenantMailerConfigTrait.php` — NEW (lives next to the bootstrapper
  in the Mailer namespace for discoverability)
- `src/DependencyInjection/Compiler/MailerTransportContractPass.php` — NEW
- `src/Exception/TenantSanitizedTransportException.php` — NEW
- `src/Profiler/TenancyDataCollector.php` — MODIFIED (D-08: add mailer section
  data; new method `collectMailerState()`)
- `src/Resources/views/Profiler/tenancy.html.twig` — MODIFIED (D-08: add
  collapsible "Mailer" section to existing panel)
- `src/Command/Install/InstallCommand.php` — MODIFIED (D-09: add
  `--with-mailer` option, invoke new sub-step)
- `src/Command/Install/Step/MailerSetupStep.php` — NEW (D-09: encapsulates
  migration scaffold + trait import + config edit)
- `src/TenantInterface.php` — MODIFIED (3 new methods)
- `src/Entity/Tenant.php` — MODIFIED (3 new columns + getters/setters; uses the
  trait OR inlines for compatibility — planner to decide whether shipping
  Tenant entity uses the trait or inlines for self-documentation)
- `src/TenancyBundle.php` — MODIFIED (`configure()` for new config keys;
  `loadExtension()` for service wiring + compiler pass registration)
- `tests/Unit/Mailer/` — NEW unit tests for each new class
- `tests/Integration/Mailer/` — NEW; the async canary test lives here

### What does NOT exist yet
- No `symfony/mailer` dependency in `composer.json` — researcher must confirm
  whether to add as `require-dev` (development testing) + suggest, or just
  `suggest` (currently the pattern for optional deps). The bundle never
  hard-requires optional deps.
- No `src/Mailer/` directory
- No mailer-related compiler pass
- The Tenant entity has no mailer columns and the interface has no mailer
  methods — this is the BC break this phase introduces

### Critical Edge: Worker-Side Lifecycle
- `TenantWorkerMiddleware` restores context via `BootstrapperChain::boot()`.
  Because `MailerBootstrapper` is in the chain, it runs on every message.
- The X-Transport stamp set in HTTP context (by `TenantMessageDecorator`'s
  `MessageEvent` listener) MUST be set BEFORE the envelope is serialized by
  Messenger — otherwise the worker has no signal to identify which tenant's
  transport to use. Researcher must validate the event firing order: standard
  Symfony 7.x flow is `MessageEvent` → `MessageEvent::QUEUED` (set BEFORE
  serialize) → serialize → broker → deserialize → worker → send.

</code_context>

<specifics>
## Specific Ideas

- **Competitive positioning the user called out** ("everything that brings
  value and we got on competitors, sometimes better"):
  - `stancl/tenancy` (the Laravel leader) ships per-tenant mailer config but is
    **sync-only correct** — Laravel queue jobs lose tenant context unless the
    user explicitly tenant-scopes each job. THIS bundle ships **async-correct
    by default** via the X-Transport stamp + worker middleware composition.
    That is the headline differentiator.
  - `stancl/tenancy` has no compile-time guard — misconfigured mailers fail at
    runtime. This bundle's `MailerTransportContractPass` makes misconfig a
    compile-time error. That is impossible in Laravel (no compiled container).
  - DSN sanitization is shipped by default, not opt-in (Laravel's
    `Illuminate\Mail` does not sanitize TransportException messages).
  - Profiler integration (optional, future Phase 19 extension): the Tenancy
    panel could show the active tenant's redacted DSN + sender — surfacing
    misconfigs in dev without leaving the WDT. NOT shipped in this phase;
    captured below as deferred.

- **No new config namespace** beyond what's strictly required: only
  `tenancy.mailer.transport_cache_size` and `tenancy.mailer.async` are added.
  No `tenancy.mailer.enabled` flag (D-05).

</specifics>

<deferred>
## Deferred Ideas

- **Per-tenant mailer template overrides** (per-tenant Twig namespace for
  email templates) — genuinely new capability, separate phase candidate for
  v0.4 or later.
- **Bounce-handling hooks / per-tenant DSN credential rotation** — operational
  features, demand-gated, no current request.
- **Validating DSN at tenant-creation time** (Doctrine lifecycle event on
  Tenant persist that tests SMTP connectivity) — future DX phase candidate.
- **IMAP/POP3 inbox per tenant** — out of scope, separate capability.

</deferred>

---

*Phase: 20-Mailer Bootstrapper*
*Context gathered: 2026-05-19*
