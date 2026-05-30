# Phase 24 Discussion Log

**Mode:** discuss — 3 substantive gray areas surfaced + resolved with user
**Started:** 2026-05-30
**Concluded:** 2026-05-30
**Decision count:** 8 (3 user-locked, 5 pattern-inherited)

## Decisions Made With User

### DEC-FILE-BUNDLE — Flysystem bundle choice

**Options presented:**
1. `league/flysystem-bundle` (Recommended) — canonical Flysystem 3 bundle, maintained by Flysystem owner
2. `oneup/flysystem-bundle` — older community bundle, more widely deployed historically
3. Both — auto-detect at runtime

**User selected:** Option 1 (`league/flysystem-bundle`).

**Implication:** Single-bundle support keeps the integration surface small. Add `oneup` later if demand justifies. Production code uses `interface_exists(\League\Flysystem\FilesystemOperator::class)` guards.

### DEC-FILE-MODE — Adapter mode scope in v0.4

**Options presented:**
1. Both modes in v0.4 (Recommended) — prefix (default) AND per-tenant-adapter (opt-in)
2. Prefix-only in v0.4, defer per-tenant-adapter
3. Per-tenant-adapter only

**User selected:** Option 1 (Both modes in v0.4).

**Implication:** Ships BOOT-03 as "done" rather than "half-done". Symmetric with how Phase 20 shipped sync + async in one milestone. Per-tenant-adapter mode gates the `MissingFilesystemConfigException` invariant.

### DEC-FILE-MULTI — Multi-filesystem scoping strategy

**Options presented:**
1. Scope by tag (`tenancy.scoped`) (Recommended) — explicit opt-in per service
2. Scope all Flysystem services
3. Scope only the default Flysystem

**User selected:** Option 1 (Scope by tag).

**Implication:** Tag attributes carry `strategy: prefix|per_tenant_adapter`. Matches existing bundle pattern (`tenancy.resolver`, `tenancy.bootstrapper`). Real escape hatch for landlord-only filesystems (logos, shared CDN assets).

## Decisions Inherited From Pattern Precedents (No User Interaction)

The following 5 decisions were locked by precedents from earlier bootstrapper phases (5 Cache, 20 Mailer) without requiring user input. Reasonable-call discipline per Phase 23 pattern. User can redirect during plan-phase if needed.

### DEC-FILE-CONFIG — `getFilesystemConfig()` via OPTIONAL trait (no BC break)

Locked. Symmetric with Phase 20's `TenantMailerConfigTrait` pattern. UPGRADE 0.3 → 0.4 has no BC break for downstream users.

### DEC-FILE-EXCEPTION — `MissingFilesystemConfigException extends \LogicException`

Locked. Mirrors Phase 23 WR-01 pattern for `MissingTenantProviderException`. Messenger no-retry semantic preserved.

### DEC-FILE-COMPILE-PASS — `FilesystemContractPass` compile-time guard

Locked. Mirrors `MailerTransportContractPass`. Three guards: missing bundle, disabled per-tenant-adapter, invalid strategy attribute.

### DEC-FILE-PRIORITY — Bootstrapper priority -30

Locked. Sits after Mailer (-20) on boot, before Mailer on clear. Filesystem cleanup runs while EM is still alive.

### DEC-FILE-TEST-ADAPTER — `league/flysystem-memory` for tests

Locked. Transitive dep of `league/flysystem`; no network IO in tests; mirrors Phase 20 SpyTransport pattern.

## Scope Creep Captured

None — user accepted the recommended scope for all 3 gray areas without proposing additions.

## Deferred Ideas

- `oneup/flysystem-bundle` integration (defer until demand surfaces)
- Profiler "Filesystem" subsection (defer to v0.4 polish phase if Phase 24 runs long)
- `tenancy:filesystem:migrate` bulk-migration command (document as manual recipe instead)
- CDN / public-URL signing per tenant (application-level concern)

## Canonical Refs Added During Discussion

All canonical refs in `24-CONTEXT.md` were sourced from REQUIREMENTS.md + codebase scout of existing bootstrapper code. No new external docs introduced during discussion.

---

_Recorded: 2026-05-30_
_Recorder: Claude (gsd-discuss-phase)_
