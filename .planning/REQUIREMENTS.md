# Requirements: Symfony Tenancy Bundle — v0.4 Storage & Shared Entities

**Defined:** 2026-05-29
**Milestone:** v0.4 Storage & Shared Entities
**Goal:** Make a real SaaS work end-to-end. v0.3 closed the install funnel; v0.4 closes the storage and data-sharing gaps that block real SaaS use cases.

For prior-milestone (v0.3) requirements, see `.planning/milestones/v0.3-REQUIREMENTS.md`.

## v0.4 Requirements

### Bootstrappers

- [x] **BOOT-03**: Per-tenant Filesystem (Flysystem) bootstrapper — `Symfony\Component\Filesystem\Filesystem` / `oneup/flysystem-bundle` integration that scopes the active tenant's uploads to a per-tenant sub-prefix (or per-tenant adapter for S3-style backends), with `class_exists` / `interface_exists` guards keeping `league/flysystem-bundle` as an optional dependency.
  - Acceptance: `FilesystemBootstrapper implements TenantBootstrapperInterface`; optional dep guarded by `interface_exists(\League\Flysystem\FilesystemOperator::class)` AND `class_exists(\Oneup\FlysystemBundle\OneupFlysystemBundle::class)` (or the equivalent for `league/flysystem-bundle`)
  - Acceptance: configuration accepts a `tenancy.filesystem.adapter_strategy: prefix | per_tenant_adapter` switch with `prefix` as default; `prefix` strategy concatenates `tenant_<slug>/` onto the configured base path; `per_tenant_adapter` strategy reads tenant-supplied DSN-like config (mirrors BOOT-04 mailer pattern)
  - Acceptance: `TenantInterface` gains an OPTIONAL `getFilesystemConfig(): ?array` method via the same trait pattern that BOOT-04 uses for `getMailerDsn()` — backward-compatible default returns `null` (use prefix strategy with `tenant_<slug>/`)
  - Acceptance: integration test proves tenant A's writes land under tenant A's prefix and are invisible to tenant B's read; works with the in-memory Flysystem adapter so tests stay isolated
  - Acceptance: `MissingFilesystemConfigException extends \LogicException` thrown when `per_tenant_adapter` mode encounters a tenant without config (matches Phase 23 WR-01 pattern for no-retry semantics under Messenger)
  - Acceptance: `FilesystemContractPass` compile-time guard rejects "filesystem bootstrapper enabled + no Flysystem bundle installed"

### Shared Entities

- [x] **SHARE-01**: Landlord-side master record + tenant-side denormalized copy via Doctrine events — when a `#[Shared]`-attributed entity is written on the landlord EM, a corresponding read-only copy is synced into each active tenant's EM via a Doctrine `postFlush` event subscriber. Default mode is **synchronous** (immediate fan-out, blocking).
  - Acceptance: `#[Shared]` PHP attribute marks an entity for cross-tenant sync; PHPStan rejects `#[Shared]` + `#[TenantAware]` on the same class (mutually exclusive — an entity is EITHER landlord-master OR tenant-scoped, never both)
  - Acceptance: `SharedEntitySyncSubscriber` listens on `Doctrine\ORM\Events::postFlush` on the landlord EM; for each `#[Shared]` entity change, enumerates active tenants via `TenantProviderInterface::findAll()` and applies the change to each tenant EM
  - Acceptance: tenant-side copies are write-protected — attempting to `persist()` a `#[Shared]` entity in tenant context throws `SharedEntityWriteInTenantContextException extends \LogicException`
  - Acceptance: cascade depth limited to ONE level (i.e. don't recursively sync entities referenced by the `#[Shared]` entity unless they're also `#[Shared]`); documented landmine
  - Acceptance: dry-run mode via `tenancy:shared:resync --dry-run <SlugOrAll>` console command for diagnostic + repair of drift

- [x] **SHARE-02**: Bulk-initial-sync command — `tenancy:shared:resync [--tenant=<slug>|--all]` walks all `#[Shared]` entities on the landlord and writes them into the target tenant(s)' EMs. Idempotent (uses `merge()` semantics, not `persist()`).
  - Acceptance: command lists all `#[Shared]` entity classes discovered via Doctrine metadata; reports "N entities to sync per tenant × M tenants = X writes" before executing; respects `--dry-run`
  - Acceptance: works under both `database_per_tenant` and `shared_db` driver modes (the latter is a no-op for prefix-only; document explicitly)
  - Acceptance: continue-on-failure pattern matching `tenancy:migrate` — one tenant's failure doesn't abort the whole loop; summary table at exit with per-tenant pass/fail counts

- [ ] **SHARE-03**: Async shared entity fan-out via Symfony Messenger — when `tenancy.shared.async: true`, `SharedEntitySyncSubscriber` dispatches a `SharedEntityChangedMessage` instead of writing synchronously. The Messenger worker handles per-tenant fan-out, allowing the landlord HTTP response to return immediately.
  - Acceptance: async mode is opt-in via `tenancy.shared.async: true`; default remains sync (SHARE-01)
  - Acceptance: `SharedEntityChangedMessage` carries the entity class + identifier + change type (insert/update/delete) — NOT the full entity payload (Messenger envelope size discipline)
  - Acceptance: worker retrieves the current entity state from the landlord EM at handle time; this means downstream tenants see the LATEST state, not the state at dispatch time (documented landmine)
  - Acceptance: `MailerTransportContractPass`-style compile-time guard rejects "shared.async: true + symfony/messenger not installed"
  - Acceptance: integration test proves async mode survives Messenger transport round-trip (matches Phase 20 AsyncCanaryTest pattern)

### Developer Experience

- [ ] **DX-03**: PHPStan extension for `#[TenantAware]` + `#[Shared]` correctness — catches misuse patterns at static-analysis time that would otherwise become runtime data-leak bugs.
  - Acceptance: rule fires when an entity has `#[TenantAware]` AND `#[Shared]` (mutually exclusive)
  - Acceptance: rule fires when a Doctrine query in `tenant_em` context queries a `#[Shared]` entity without an explicit `setEntityManager('landlord')` override (potential cross-tenant leak via tenant EM)
  - Acceptance: rule fires when a `#[TenantAware]` entity's `tenant_id` column is missing OR not nullable=false (config drift detection)
  - Acceptance: ships as `phpstan/extension-installer` auto-loaded via `composer.json#extra.phpstan.includes`; opt-in via documented `phpstan.neon` snippet
  - Acceptance: rule provides clear error message naming the file + line + violation kind

### Documentation

- [ ] **DOC-20**: Documentation reflects everything v0.4 ships. New pages for Filesystem bootstrapper, Shared Entities sync model, PHPStan extension setup; UPGRADE.md 0.3 → 0.4 explains any BC breaks; `docs-lint.sh` extended with shared-entity-related anti-pattern checks.
  - Acceptance: new `user-guide/filesystem-bootstrapper.md` page — covers prefix vs per-tenant-adapter modes, configuration, MissingFilesystemConfigException
  - Acceptance: new `user-guide/shared-entities.md` page — covers `#[Shared]` attribute, sync model (sync vs async), `tenancy:shared:resync` command, cascade-depth landmine, write-protection invariant
  - Acceptance: new `user-guide/phpstan-extension.md` page — covers installation, `phpstan.neon` snippet, each rule's purpose + example violation/fix
  - Acceptance: `UPGRADE.md` 0.3 → 0.4 section (only if BC breaks land — TenantInterface `getFilesystemConfig()` is OPTIONAL via trait so no BC break expected, but document any unforeseen ones discovered during execution)
  - Acceptance: `scripts/docs-lint.sh` extended with check that fails on references to "shared entity" without disambiguating "landlord-side master" vs "tenant-side read-only copy" (avoids ambiguity that surfaced during planning)

## Future Requirements

Deferred to v0.5–v0.6 per the [Later Milestones](.planning/PROJECT.md#later-milestones-planned-scope-subject-to-v04-telemetry) plan in `PROJECT.md`.

- **v0.5 Operations & Scale:** OPS-01 tenant-level maintenance mode, OPS-02 health checks, ISOL-07 parallel migrations
- **v0.6 Advanced Isolation (demand-gated):** ISOL-06 PostgreSQL Row-Level Security driver — v1.0 candidate if adoption signals validate

## Out of Scope

Out of scope for v0.4 with reasoning. Some items are deferred to later milestones (see Future Requirements); others are user-requestable via GitHub issues.

| Item | Reason |
|------|--------|
| **v1.0 tag** | Requires external adoption signals to validate the public surface. Deferred until at least v0.6, possibly later. Same constraint as v0.3. |
| **Symfony Flex recipe** | Same reason as v0.3: `tenancy:install` is the v0.4 onboarding path; revisit when install volume justifies the contrib-submission maintenance cost. |
| **Cross-tenant queries via #[Shared]** | The shared-entity model is INTENTIONALLY one-way: landlord → tenant, not tenant → tenant. Cross-tenant query patterns belong to a separate "Multi-tenant aggregation" feature, demand-gated. |
| **Read-replica routing for #[Shared]** | Optimization for high-scale deploys. Out of scope until at least v0.5 (Operations & Scale). |
| **CDN / object-store provisioning** | Filesystem bootstrapper integrates with existing Flysystem adapters; provisioning new buckets/CDN paths is application-level concern, not bundle scope. |

## Carry-forward from v0.3 Retrospective

These are not formal requirements but inform v0.4 phase planning:

1. **`examples/saas/composer.lock` vs `Dockerfile` PHP version drift** — refresh in early v0.4 (Phase 24 if Filesystem bootstrapper needs the demo to exercise it; otherwise a standalone hygiene phase).
2. **Closure-phase CONTEXT.md should `git log --since=<audit-date>` over canonical_refs files** — capture in `gsd-discuss-phase` workflow updates if surfaced during a v0.4 closure phase.
3. **72-hour TTL on `human_needed` VERIFICATION status** — if this lights up during v0.4 work, consider tooling. Otherwise defer.
4. **Plan↔summary parity check** (Phase 16 GOV-01 was skipped in v0.3 as non-functional) — reconsider IF v0.4 work surfaces a concrete recurrence of drift that the check would have caught.

## Traceability

Filled by roadmap step. Each requirement maps to exactly one phase.

| Requirement | Phase | Status |
|-------------|-------|--------|
| BOOT-03 | Phase 24 — Filesystem Bootstrapper | Complete |
| SHARE-01 | Phase 25 — Shared Entities (Sync mode) | Complete |
| SHARE-02 | Phase 26 — `tenancy:shared:resync` command | Complete |
| SHARE-03 | Phase 27 — Async Shared Entities | Pending |
| DX-03 | Phase 28 — PHPStan Extension | Pending |
| DOC-20 | Phase 29 — Docs Refresh | Pending |

**Coverage:**

- v0.4 requirements: 6 total
- Active: 6, mapped to phases 24–29 (100%)
- Unmapped: 0

## Architectural Decisions (Tentative — Subject to Phase-Level Discuss)

These default to one direction unless flipped during a phase's `/gsd:discuss-phase` step. Plan-phase agents may treat them as starting positions, not locked decisions.

| ID | Decision | Default | Rationale |
|----|----------|---------|-----------|
| **DEC-FILE-01** | Filesystem adapter-strategy default | `prefix` mode (vs `per_tenant_adapter`) | Lower-friction onboarding; per-tenant-adapter is opt-in for S3-style separation |
| **DEC-FILE-02** | Config method on TenantInterface | OPTIONAL via trait (no BC break) | Symmetric with BOOT-04 mailer pattern; existing tenant entities work out of the box |
| **DEC-SHARE-01** | Sync mode default | Synchronous (immediate fan-out) | Predictable + easier to reason about; async is opt-in via config |
| **DEC-SHARE-02** | Cascade depth | One level only | Avoids unbounded sync cost; explicit `#[Shared]` on every shared entity |
| **DEC-SHARE-03** | Mutual exclusion with TenantAware | Compile-time error | Prevents data-leak bug class entirely |
| **DEC-PHPSTAN-01** | Distribution | `phpstan/extension-installer` auto-load | Same pattern as `phpstan/phpstan-symfony` and `phpstan/phpstan-doctrine` — zero-config |

---

*Requirements defined: 2026-05-29*
*Milestone: v0.4 Storage & Shared Entities*
*Status: drafted; ready for phase-by-phase `/gsd:plan-phase` invocation*
