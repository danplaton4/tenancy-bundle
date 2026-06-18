---
phase: 29-docs-refresh
reviewed: 2026-06-18T00:00:00Z
depth: standard
files_reviewed: 10
files_reviewed_list:
  - UPGRADE.md
  - docs/architecture/sql-filter.md
  - docs/roadmap.md
  - docs/user-guide/cli-commands.md
  - docs/user-guide/filesystem-bootstrapper.md
  - docs/user-guide/phpstan-extension.md
  - docs/user-guide/shared-db.md
  - docs/user-guide/shared-entities.md
  - mkdocs.yml
  - scripts/docs-lint.sh
findings:
  critical: 0
  warning: 7
  info: 3
  total: 10
status: issues_found
---

# Phase 29: Code Review Report

**Reviewed:** 2026-06-18
**Depth:** standard
**Files Reviewed:** 10
**Status:** issues_found

## Summary

Documentation phase (8 Markdown pages + `mkdocs.yml` nav + the `scripts/docs-lint.sh` CI gate). I verified every code-derived claim against the live source in `src/`, checked all internal cross-links and anchor fragments, validated all mkdocs nav entries, and reviewed the shell script as real code.

**Factual accuracy is strong.** Every PHPStan rule ID (`tenancy.mutualExclusion`, `tenancy.sharedEntityLeak`, `tenancy.tenantIdDrift`), the `#[Shared]` / `#[TenantAware]` attributes, all command names (`tenancy:install/init/migrate/run/shared:resync`), the `tenancy:shared:resync` flags, all `tenancy.filesystem.*` and `tenancy.shared.async` config keys + defaults, the bootstrapper boot-order priorities (DatabaseSwitch 0 → Doctrine -10 → Mailer -20 → Filesystem -30), the exception base classes (`MissingFilesystemConfigException`, `UnsupportedAdapterDsnSchemeException`, `SharedEntityWriteInTenantContextException` all extend `\LogicException`), the `SharedEntityChangedMessage` shape, the `addslashes()` SQL-filter behavior, the `services?` no-op annotation, the neon-file mechanics, and the accepted `tenant_id` types (`string`, `ascii_string`, `guid`, `uuid`) all match the source exactly. All 38 mkdocs nav `.md` targets and all internal `.md` links resolve.

**Defects cluster in three areas:** (1) broken `#03-to-04` anchor links to UPGRADE.md in two new v0.4 pages, (2) a stale "four commands" intro count in `cli-commands.md` that now contradicts its own content (five commands), (3) `docs/roadmap.md` left stale — still frames already-shipped v0.4 features as "Next/not started" and mis-describes what the PHPStan extension does. The lint script has one real state-leakage bug in its D-15 awk whitelist, plus a coverage gap in the new D-04 check, and the filesystem page ships a `str_starts_with` path-traversal guard with a known prefix-bypass weakness.

## Warnings

### WR-01: Broken anchor `#03-to-04` to UPGRADE.md in two new v0.4 pages

**File:** `docs/user-guide/filesystem-bootstrapper.md:428`, `docs/user-guide/shared-entities.md:298`
**Issue:** Both pages link `[...](../../UPGRADE.md#03-to-04)`. The UPGRADE.md heading is `## 0.3 → 0.4` (an arrow, not the word "to"), which slugifies to `03-04` (MkDocs/Python-Markdown) or `03--04` (GitHub). The anchor `#03-to-04` matches neither — the link lands on the page but never scrolls to the section. The pattern was copied from `mailer-bootstrapper.md` (`#02-to-03`), which is correct only because *its* heading is `## 0.2 to 0.3` (literal "to"). The new heading breaks the assumption.
**Fix:** Make the heading consistent with the established convention (`## 0.2 to 0.3`, `## 0.3.2 to 0.3.3`) AND fix both anchors in one move:
```markdown
# UPGRADE.md line 3 — change the heading to use "to":
## 0.3 to 0.4
```
This makes the existing `#03-to-04` anchors resolve correctly under both GitHub and MkDocs and aligns with every other heading in the file. (Alternatively, leave the heading and change both links to `#03-04`, but that diverges from the file's convention.)

### WR-02: `cli-commands.md` intro says "four commands" but documents five

**File:** `docs/user-guide/cli-commands.md:3`
**Issue:** The opening sentence claims "The bundle provides **four commands**: ... `tenancy:install` ... plus three subcommands: `tenancy:init` ... `tenancy:migrate` ... `tenancy:run`." But the same page documents a fifth command, `tenancy:shared:resync` (full section at lines 235-255), and the source has five `#[AsCommand]` definitions (`tenancy:install`, `tenancy:init`, `tenancy:migrate`, `tenancy:run`, `tenancy:shared:resync`). The intro count was not updated when the resync section was added in this phase. Reader-facing inaccuracy in the page's first sentence.
**Fix:**
```markdown
The bundle provides five commands: a one-shot setup command — `tenancy:install` — that
auto-registers the bundle and scaffolds `config/packages/tenancy.yaml` in a single step, plus
four subcommands: `tenancy:init` for regenerating the config file standalone, `tenancy:migrate`
for running Doctrine Migrations across all tenants, `tenancy:run` for executing any Symfony
console command within a specific tenant's context, and `tenancy:shared:resync` for
re-synchronizing `#[Shared]` entities to tenant databases.
```

### WR-03: `docs-lint.sh` D-15 awk whitelist leaks `in_whitelist`/`section` state across files

**File:** `scripts/docs-lint.sh:73-81`
**Issue:** The awk program processes `$(find docs/ -name '*.md')` as multiple file arguments but never resets `in_whitelist` or `section` between files. If file *A*'s last `## ` section is whitelisted (e.g. `## Upgrade`, `## Troubleshooting`), `in_whitelist` stays `1` when awk begins file *B*; every line in *B*'s preamble (before *B*'s first `## ` heading) inherits *A*'s whitelist state and is silently skipped. A `bundles.php` install-path regression placed in a file's intro — exactly what D-15 guards against — escapes detection depending on alphabetical file ordering. Verified reproducible: a `bundles.php` reference in the preamble of a second file was NOT flagged when the first file ended in a whitelisted section.
**Fix:** Reset per-file state at `FNR==1`:
```awk
awk '
    FNR == 1 { in_whitelist = 0; section = "" }
    /^## / {
        section = $0
        sub(/^## /, "", section)
        in_whitelist = (section ~ /^(Migration|Upgrade|Manual setup|Troubleshooting|Do I have to do anything\?|tenancy:install)/)
        next
    }
    !in_whitelist { print FILENAME ":" FNR ":" $0 }
' $(find docs/ -name "*.md") | grep -E "bundles\.php" || true
```

### WR-04: D-04 shared-entity check has a detection false-negative for attribute-only references

**File:** `scripts/docs-lint.sh:96-104`
**Issue:** The new D-04 check only enforces the disambiguation requirement on files matched by `grep -qiE 'shared entit(y|ies)'` — the bare two-word phrase. A page that discusses the concept exclusively via the attribute notation (e.g. "a `#[Shared]` entity is …") without ever writing the words "shared entity/entities" is skipped entirely, so the `landlord-side master` / `tenant-side read-only copy` disambiguation is never required even though the page is squarely about the feature. The check cannot catch the ambiguity it exists to prevent in attribute-only prose. (Detection is also case-insensitive `-iE` while the two required phrases are matched case-*sensitively* with plain `grep -q`; a heading-cased variant of either phrase would fail the gate even though the prose is correct.)
**Fix:** Broaden the trigger to also match the attribute form, and make the phrase checks case-insensitive to match the trigger:
```bash
if grep -qiE 'shared entit(y|ies)|#\[Shared\]' "$f"; then
    if ! grep -qi 'landlord-side master' "$f" || ! grep -qi 'tenant-side read-only copy' "$f"; then
        SHARED_ENTITY_VIOLATIONS="${SHARED_ENTITY_VIOLATIONS}${f}\n"
        EXIT=1
    fi
fi
```
Note: broadening to `#[Shared]` will require auditing that every page using the attribute (e.g. `phpstan-extension.md`, `shared-db.md`) carries both phrases — they currently do, so the gate stays green.

### WR-05: Recommended path-traversal guard in filesystem page has a prefix-bypass weakness

**File:** `docs/user-guide/filesystem-bootstrapper.md:302-308`
**Issue:** The page ships copy-paste "recommended sanitisation for read paths from user input":
```php
$resolved = realpath($root . '/' . $userPath);
if (false === $resolved || !str_starts_with($resolved, realpath($root))) {
    throw new \InvalidArgumentException('Path traversal detected.');
}
```
`str_starts_with($resolved, realpath($root))` is vulnerable to the classic sibling-prefix bypass: with `$root = /srv/storage`, a path resolving to `/srv/storage-evil/secret` passes the check because `/srv/storage-evil/...` starts with `/srv/storage`. Because this is presented as security guidance in a security-focused section, the flaw will be propagated into adopter code.
**Fix:** Compare against the root *with a trailing separator* (and reuse the resolved root):
```php
$realRoot = realpath($root);
$resolved = realpath($root . '/' . $userPath);
if (false === $realRoot || false === $resolved
    || !str_starts_with($resolved . \DIRECTORY_SEPARATOR, $realRoot . \DIRECTORY_SEPARATOR)) {
    throw new \InvalidArgumentException('Path traversal detected.');
}
```

### WR-06: `roadmap.md` frames shipped v0.4 features as "Next / not started"

**File:** `docs/roadmap.md:13-23`
**Issue:** The roadmap still reads "## In progress — closing v0.3 / Phase 22" and "## Next — v0.4 Storage & shared entities" listing the filesystem bootstrapper, shared-entity replication, and PHPStan extension as upcoming work, with "Latest tag: **v0.3.2**" (line 9). But those exact features are documented as shipped in `UPGRADE.md §0.3 → 0.4` and are the subject of this very docs-refresh phase (Phase 29). The roadmap was edited in this phase (commit `f38b30c`) only for the D-04 disambiguation phrasing — the stale v0.3/v0.4 status framing was left in place. A reader is told v0.4 hasn't started while reading its full feature documentation.
**Fix:** Move the three v0.4 items into the `## Shipped` section (with the v0.4 milestone and current tag), and replace the "In progress — closing v0.3 / Phase 22" block with the actual current state. At minimum, update line 9's "Latest tag: **v0.3.2**" to the real latest tag.

### WR-07: `roadmap.md` mis-describes what the PHPStan extension checks

**File:** `docs/roadmap.md:23`
**Issue:** The roadmap describes the PHPStan extension as a "static check that tenant-scoped repositories aren't accidentally injected into shared services." The actual extension (verified in `src/PHPStan/Rule/`) ships three rules with entirely different scopes: `tenancy.mutualExclusion` (`#[Shared]` + `#[TenantAware]` on one class), `tenancy.sharedEntityLeak` (concrete `EntityManager` querying a `#[Shared]` entity), and `tenancy.tenantIdDrift` (missing/nullable/non-string `tenant_id` column). None of them inspects "repositories injected into shared services." This is a factual inaccuracy about the feature, now contradicted by the dedicated `phpstan-extension.md` page added this phase.
**Fix:** Align the roadmap line with the actual rules, e.g.:
```markdown
- **PHPStan extension** — three static-analysis rules: mutual-exclusion of `#[Shared]`/`#[TenantAware]`,
  shared-entity leaks through the tenant EntityManager, and `tenant_id` column drift on `#[TenantAware]`
  entities. See [PHPStan Extension](user-guide/phpstan-extension.md).
```

## Info

### IN-01: `tenancy:run` doc omits the "no shell interpretation" caveat the command itself documents

**File:** `docs/user-guide/cli-commands.md:191-220`
**Issue:** The page presents the command string in quotes (`"app:import-products --format=csv"`) and describes spawning a subprocess, but never states that the string is whitespace-tokenized with **no shell semantics** — quotes, pipes, redirects, and command substitution are passed through as literal characters. The source (`TenantRunCommand::configure()`) explicitly documents this in the argument description, and it is a deliberate v0.3.0 shell-injection fix (`new Process(array $argv)` instead of `fromShellCommandline`). Users expecting shell behavior (e.g. an argument containing spaces wrapped in inner quotes) will be surprised.
**Fix:** Add a short note under "How It Works": "The command string is split on whitespace; there is no shell interpretation — inner quotes, pipes, and redirects are treated as literal token characters. To pass an argument containing spaces you must invoke the inner command differently."

### IN-02: `tenancy:run` "fails immediately with a clear error" overstates the slug-validation UX

**File:** `docs/user-guide/cli-commands.md:219-220`
**Issue:** The doc says an unknown/inactive tenant "fails immediately with a clear error." The source validates via `$this->tenantProvider->findBySlug($tenantSlug)` and lets `TenantNotFoundException`/`TenantInactiveException` **bubble uncaught** (comment: "let … bubble"), so the user sees an unhandled-exception stack trace, not a styled error message. Validation does happen before the subprocess spawns (the functional claim is correct); only the "clear error" characterization is optimistic.
**Fix:** Soften to "the command aborts before spawning the subprocess (the resolver exception propagates)" or add a caught/styled error in the command if a clean message is desired.

### IN-03: `sql-filter.md` characterizes `addslashes()` as SQL-injection prevention

**File:** `docs/architecture/sql-filter.md:134`
**Issue:** The page states "The `addslashes()` call escapes single quotes in the slug to prevent SQL injection." This accurately describes the live code (`TenantAwareFilter` line 49 uses `addslashes($tenant->getSlug())`), so it is not factual drift. But `addslashes()` is a weak, escaping-only defense; the page does not mention that slugs are also constrained upstream (resolver/provider validation), which is the real guarantee. Documenting `addslashes` as *the* injection guard could mislead a reader writing a custom filter.
**Fix:** Add a sentence noting that tenant slugs are validated/constrained at resolution time and that `addslashes` is defense-in-depth, not the primary control.

---

_Reviewed: 2026-06-18_
_Reviewer: Claude (gsd-code-reviewer)_
_Depth: standard_
