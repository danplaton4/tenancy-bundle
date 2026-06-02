---
phase: 24-filesystem-bootstrapper
reviewed: 2026-06-03T00:00:00Z
depth: standard
files_reviewed: 40
files_reviewed_list:
  - src/Filesystem/FilesystemPrefixingDecorator.php
  - src/Filesystem/TenantAwareFilesystemDecorator.php
  - src/Filesystem/AdapterDsnParser.php
  - src/Filesystem/LruFilesystemCache.php
  - src/Filesystem/TenantContextClearedListener.php
  - src/Filesystem/TenantFilesystemConfigTrait.php
  - src/Bootstrapper/FilesystemBootstrapper.php
  - src/DependencyInjection/Compiler/FilesystemContractPass.php
  - src/Entity/AbstractTenant.php
  - src/Exception/MissingFilesystemConfigException.php
  - src/Exception/UnsupportedAdapterDsnSchemeException.php
  - src/TenancyBundle.php
  - config/services.php
  - composer.json
  - examples/saas/src/Controller/TenantUploadController.php
  - examples/saas/templates/upload/index.html.twig
  - examples/saas/config/packages/flysystem.yaml
  - examples/saas/config/packages/tenancy.yaml
  - examples/saas/config/services.yaml
  - examples/saas/composer.json
  - docs/user-guide/filesystem-bootstrapper.md
  - mkdocs.yml
  - UPGRADE.md
  - tests/Integration/Filesystem/FilesystemBootstrapperIntegrationTest.php
  - tests/Integration/Filesystem/FilesystemTestKernel.php
  - tests/Integration/Filesystem/LongRunningWorkerFilesystemSimulationTest.php
  - tests/Integration/Filesystem/MakeFilesystemServicesPublicPass.php
  - tests/Integration/Filesystem/ReplaceFilesystemProviderPass.php
  - tests/Integration/Filesystem/ScopedStorageTaggingPass.php
  - tests/Integration/Filesystem/StubFilesystemTenantProvider.php
  - tests/Integration/Filesystem/StubTenantWithFilesystem.php
  - tests/Integration/Support/StubTenantFilesystemExtension.php
  - tests/Unit/Bootstrapper/FilesystemBootstrapperTest.php
  - tests/Unit/DependencyInjection/Compiler/FilesystemContractPassTest.php
  - tests/Unit/Exception/MissingFilesystemConfigExceptionTest.php
  - tests/Unit/Filesystem/AdapterDsnParserTest.php
  - tests/Unit/Filesystem/FilesystemPrefixingDecoratorTest.php
  - tests/Unit/Filesystem/LruFilesystemCacheTest.php
  - tests/Unit/Filesystem/TenantAwareFilesystemDecoratorTest.php
  - tests/Unit/Filesystem/TenantContextClearedListenerTest.php
  - tests/Unit/Filesystem/TenantFilesystemConfigTraitTest.php
findings:
  critical: 1
  warning: 6
  info: 7
  total: 14
status: issues_found
---

# Phase 24: Code Review Report

**Reviewed:** 2026-06-03
**Depth:** standard
**Files Reviewed:** 40
**Status:** issues_found

## Summary

Phase 24 adds the Filesystem Bootstrapper (BOOT-03): two `FilesystemOperator` decorator
strategies (prefix-mode default + per-tenant-adapter opt-in), an LRU cache of per-tenant
`Filesystem` instances, a DSN parser, a compiler pass with compile-time guards, and
bootstrapper lifecycle wiring.

**The core security invariant holds.** Both `FilesystemPrefixingDecorator` and
`TenantAwareFilesystemDecorator` read `TenantContext` live on every call via a single
private resolver method, carry zero non-readonly instance state, and are reflection-pinned
against mutable state in their unit tests. The cross-tenant context-switch tests
(`testLiveReadInvariantCrossTenantContextSwitch`, `testContextSwitchRoutesToCorrectAdapter`)
and the 100-tenant worker simulation provide strong evidence the no-leak invariant is
maintained. The LRU eviction/close semantics, DSN credential-leak discipline, and compiler
pass guards are well-implemented and well-tested.

The findings below center on one **BLOCKER** integration-test defect that silently negates
the realism of the entire integration suite, plus correctness gaps in the prefix decorator's
trailing-separator stripping, multi-tag collision handling in the compiler pass, a stub test
left incomplete, and several documentation/example consistency issues.

## Critical Issues

### CR-01: Filesystem integration test kernel boots with an invalid `driver` value, silently disabling driver wiring

**File:** `tests/Integration/Filesystem/FilesystemTestKernel.php:148`

**Issue:** The kernel loads the tenancy extension with `'driver' => 'shared_database'`. This
is **not a valid driver value** — the configuration schema and every runtime branch recognize
only `'database_per_tenant'` (the default) and `'shared_db'` (see
`src/TenancyBundle.php:41`, `:115`, `:251`, `:301` and `src/Profiler/TenantDataCollector.php:43`
`KNOWN_DRIVERS = ['database_per_tenant', 'shared_db']`). The Mailer integration kernel — which
this file claims to mirror — correctly uses `'database_per_tenant'`
(`tests/Integration/Mailer/MailerTestKernel.php:118`).

Because `'shared_database'` matches neither branch:
- The `shared_db` block (`TenancyBundle.php:251`) never runs, so `SharedDriver` is never
  registered.
- The `database.enabled` block is also not exercised (the kernel never sets
  `database.enabled: true`), so the database-per-tenant driver is not wired either.
- `tenancy.driver` parameter is set to the bogus string `'shared_database'`, which would
  break any consumer that asserts on driver identity (e.g. the profiler collector treats it
  as an unknown driver).

The kernel boots only because no validator rejects an unknown scalar `driver` and because the
`shared_db + database.enabled` cross-validation (`TenancyBundle.php:114-120`) does not fire for
this value. The result is that the **entire integration suite runs against a tenancy container
in an undefined driver state** — none of the documented driver wiring is active. The tests
still pass because they exercise only the filesystem decorators (which are driver-agnostic) and
swap in `StubFilesystemTenantProvider` via `ReplaceFilesystemProviderPass`, so the latent
mis-wiring is masked. This is an adversarial-review red flag: a typo in a load-bearing config
key produced a green suite that does not test what its docblock claims ("Doctrine ORM is wired
against an in-memory SQLite so the TenancyBundle's landlord EntityManager ... work end-to-end").

**Fix:** Use a valid driver value. Given the kernel wires Doctrine ORM + SQLite and the
provider is stubbed, `database_per_tenant` (the default, matching the Mailer kernel) is the
correct choice:

```php
$container->loadFromExtension('tenancy', [
    'driver' => 'database_per_tenant',
    'strict_mode' => false,
    'filesystem' => [
        'enabled' => true,
        'allow_per_tenant_adapter' => true,
        'cache_size' => 2,
    ],
]);
```

Consider also adding an enum/`->validate()->ifNotInArray(['database_per_tenant', 'shared_db'])`
guard on the `driver` node in `TenancyBundle::configure()` so a future typo fails loudly at
container compile time instead of silently producing an undefined driver state. (That config
hardening is out of Phase 24 scope but the test bug it would have caught is in scope.)

## Warnings

### WR-01: `FilesystemPrefixingDecorator::listContents()` may return paths with a leading separator after prefix strip

**File:** `src/Filesystem/FilesystemPrefixingDecorator.php:79-109`, `244-255`

**Issue:** The prefixer is built from `prefixTemplate` with `{slug}` substituted, e.g.
`tenant_acme/`. `PathPrefixer` is constructed with that string. Flysystem's `PathPrefixer`
normalizes the prefix by trimming the trailing `/` and re-appending one for `prefixPath()`,
but `stripPrefix()`/`stripDirectoryPrefix()` strip only `strlen($this->prefix)` characters and
then `ltrim('/')`. This works for the default template. However, if a user supplies a template
**without** a trailing separator (e.g. `prefix_template: 'tenant_{slug}'`, which the compiler
pass and config schema both accept as an arbitrary scalar with no validation —
`TenancyBundle.php:108`, `FilesystemContractPass.php:84`), the prefix becomes `tenant_acme`
and `prefixPath('reports.txt')` yields `tenant_acme/reports.txt` while `stripPrefix` removes
`tenant_acme` leaving `/reports.txt`. Application code that wrote `reports.txt` then sees
`/reports.txt` back from `listContents()` — an asymmetry that breaks round-tripping and any
caller that re-feeds listed paths into `read()`/`delete()`.

The shipped tests only ever use templates ending in `/`, so this is uncovered.

**Fix:** Normalize the template to guarantee a trailing separator before constructing the
prefixer, or document the trailing-`/` requirement and validate it. Minimal normalization:

```php
private function prefixer(): PathPrefixer
{
    $tenant = $this->context->getTenant();
    if (null === $tenant) {
        return new PathPrefixer('');
    }
    $prefix = str_replace('{slug}', $tenant->getSlug(), $this->prefixTemplate);
    if ('' !== $prefix && !str_ends_with($prefix, '/')) {
        $prefix .= '/';
    }
    return new PathPrefixer($prefix);
}
```

Add a test with a no-trailing-slash template asserting `listContents()` returns
`reports.txt`, not `/reports.txt`.

### WR-02: Prefix-mode tenant with empty slug silently disables scoping (cross-tenant collision risk)

**File:** `src/Filesystem/FilesystemPrefixingDecorator.php:246-254`

**Issue:** When a tenant **is** active but `getSlug()` returns an empty string, the substituted
prefix for the default template `tenant_{slug}/` becomes `tenant_/`. That is at least distinct,
but for templates like `{slug}/` an empty slug yields prefix `/` (effectively root). More
importantly, the no-tenant branch (`null === $tenant`) and an empty-slug tenant are treated
inconsistently: a null tenant returns an empty prefixer (intentional passthrough), but an
empty-slug tenant produces a degenerate prefix. `AbstractTenant` allows constructing a tenant
with `new DemoTenant('', '')` — `slug` is a plain `string` with no non-empty constraint
(`AbstractTenant.php:25-26`, `:67-71`). Two different tenants that both resolve to an
empty/whitespace slug would share a storage namespace — a cross-tenant data leak vector that
the live-read invariant does not protect against (the invariant guards against caching, not
against degenerate slugs).

This is lower severity than CR-01 because a production tenant table normally has a non-empty
slug primary key, but the bundle does not enforce it anywhere in the filesystem path.

**Fix:** Either document that slugs are assumed non-empty and unique (they are the entity ID),
or defensively guard: treat an empty resolved prefix the same as no-tenant, or throw. At
minimum add an assertion in the decorator or a non-empty validation on the slug column.

### WR-03: `FilesystemContractPass` silently drops all-but-last decoration when a service carries multiple `tenancy.scoped` tags

**File:** `src/DependencyInjection/Compiler/FilesystemContractPass.php:81-100`

**Issue:** The pass iterates `foreach ($tags as $attrs)` (multiple tag instances per service
are legal in Symfony) and, for each, registers a decorator under the **same** fixed ID
`$id.'.tenant_scoped'` (line 98). If a user tags one storage twice (e.g. once `prefix` and once
`per_tenant_adapter`, or two prefix templates), `setDefinition()` overwrites the prior
definition with no warning — only the last tag wins. Worse, two decorators both calling
`setDecoratedService($id)` on the same inner ID would be a misconfiguration the pass should
reject, not silently collapse. There is no test for the multi-tag case.

**Fix:** Detect multiple `tenancy.scoped` tags on a single service and throw a `LogicException`
(consistent with the three existing compile-time guards), e.g.:

```php
foreach ($container->findTaggedServiceIds(self::TAG) as $id => $tags) {
    if (count($tags) > 1) {
        throw new \LogicException(sprintf(
            'tenancy.scoped on "%s" declared %d times; exactly one strategy per service is supported.',
            $id, count($tags),
        ));
    }
    $attrs = $tags[0];
    // ... existing per-tag logic ...
}
```

### WR-04: `MissingFilesystemConfigExceptionTest` is a non-implemented stub but the exception class shipped — coverage gap on a security-relevant invariant

**File:** `tests/Unit/Exception/MissingFilesystemConfigExceptionTest.php:22-26`

**Issue:** The test body is `$this->markTestIncomplete('Stub — implemented in Plan 24-02 ...')`,
yet `src/Exception/MissingFilesystemConfigException.php` was fully implemented in commit
`2c21a3d` (Plan 24-02). The stub was never flipped to a real test. This leaves the
LogicException-ancestry invariant for this class asserted only indirectly (via the decorator
integration test `testMissingFilesystemConfigThrowsLogicException` and the decorator unit test
`testMissingFilesystemConfigExceptionIsLogicException`). The dedicated unit test that should
pin the `forTenant()` factory message shape and the `\LogicException` / not-`\RuntimeException`
ancestry is dead. `markTestIncomplete` produces a non-failing "incomplete" status, so CI stays
green while the intended coverage is absent.

By contrast, `UnsupportedAdapterDsnSchemeException` has no dedicated unit test file at all in the
reviewed set (it is covered only indirectly through `AdapterDsnParserTest`).

**Fix:** Implement the stub: assert `MissingFilesystemConfigException::forTenant('acme')`
returns a `\LogicException`, is not a `\RuntimeException`, and that the message contains the
slug and the `adapter_dsn` guidance. Remove `markTestIncomplete`. Add an equivalent dedicated
test for `UnsupportedAdapterDsnSchemeException::forScheme()`.

### WR-05: Demo app enables `database.enabled: true` with `DemoTenant extends AbstractTenant`, but no migration adds the new `filesystem_config` column

**File:** `examples/saas/config/packages/tenancy.yaml:3-4`; `src/Entity/AbstractTenant.php:57-59`;
`UPGRADE.md:62-70`

**Issue:** Phase 24 added a non-nullable-typed (`?array`, nullable in DB) `filesystemConfig`
column to `AbstractTenant` (commit `ff77357`). The demo's `DemoTenant` extends `AbstractTenant`
and the demo runs `database.enabled: true` against MariaDB. After this upgrade, the demo's
`tenancy_tenants` table is missing the `filesystem_config` column, so any
`doctrine:schema:validate` will report drift and `cache:warmup`/queries that materialize the
full entity will fail on the missing column. The UPGRADE guide documents the `ALTER TABLE`
for end users, but the demo app ships no migration and no fixture update, so the runnable demo
is in a broken-schema state out of the box. Given the project's own memory note
("Live-stack verification required — code-only verification != verification for runnable
artifacts"), this is exactly the class of demo regression that prior phases were burned by.

**Fix:** Add a Doctrine migration (or update the demo's schema/fixtures) adding
`filesystem_config JSON DEFAULT NULL` to `tenancy_tenants` in `examples/saas`, and verify the
demo boots with `doctrine:schema:validate` clean. If the demo uses `schema:update` on boot,
confirm the new column is created.

### WR-06: `parse_str` array-style query values are silently dropped, so `?write_flags[]=...` and similar produce default behavior with no error

**File:** `src/Filesystem/AdapterDsnParser.php:202-220`, `233-237`

**Issue:** `parseQuery()` runs `parse_str()` then discards any non-scalar value
(`if (is_scalar($v))`). A DSN like `local:///srv/uploads?write_flags[]=2` parses `write_flags`
to an array, which is dropped, so the `isset($params['write_flags'])` check at line 235 is
false and the adapter silently falls back to `LOCK_EX`. Likewise any `key[]=`/`secret[]=` in an
`s3://` DSN is dropped. This is not a crash (verified: `is_numeric([...])` is false and the key
is absent), and the trust boundary treats the DSN as admin-supplied, so it is not a security
hole. But an operator who fat-fingers an array-style query gets silently-ignored configuration
rather than an error — a debugging trap.

**Fix:** Minor. Either document that DSN query values must be scalar, or detect a dropped
non-scalar value and throw an `InvalidArgumentException` naming the offending key (without
echoing the value, to preserve the credential-leak discipline).

## Info

### IN-01: `MakeFilesystemServicesPublicPass` references two service IDs that are never registered

**File:** `tests/Integration/Filesystem/MakeFilesystemServicesPublicPass.php:48-49`

**Issue:** The ID list includes `tenancy.filesystem.prefixing_decorator` and
`tenancy.filesystem.tenant_aware_decorator`, but `config/services.php` registers only
`lru_cache`, `adapter_dsn_parser`, `bootstrapper`, and `context_cleared_listener`. The
decorators are registered per-storage by the compiler pass under `<id>.tenant_scoped`, never
under these names. The `hasDefinition`/`hasAlias` guards tolerate the absence, so these are
dead entries — harmless but misleading, suggesting services that do not exist.

**Fix:** Remove the two non-existent IDs, or add a comment that they are intentional
forward-compat placeholders.

### IN-02: `TenantAwareFilesystemDecorator` uses fully-qualified `\Tenancy\Bundle\TenantInterface` inline instead of a `use` import

**File:** `src/Filesystem/TenantAwareFilesystemDecorator.php:243`, `270`

**Issue:** `buildAndCache()` and `readConfig()` type-hint `\Tenancy\Bundle\TenantInterface`
with a leading-backslash FQCN, while the rest of the file imports its dependencies via `use`.
This is stylistically inconsistent with the `@Symfony` ruleset used elsewhere. (Note: the
project memory records that cs-fixer's `no_unused_imports` strips same-namespace `use`
statements — but `TenantInterface` is in a different namespace (`Tenancy\Bundle` vs
`Tenancy\Bundle\Filesystem`), so a normal `use Tenancy\Bundle\TenantInterface;` import is
appropriate and would not be stripped.)

**Fix:** Add `use Tenancy\Bundle\TenantInterface;` and reference `TenantInterface` unqualified.

### IN-03: `readConfig()` PHPStan array-shape doc is wider than what callers consume; `services` key is parsed nowhere

**File:** `src/Filesystem/TenantAwareFilesystemDecorator.php:268`, `276`;
`src/Filesystem/TenantFilesystemConfigTrait.php:25-31`

**Issue:** The config shape advertises a `services?: array<string>` key ("limit scoping to these
service IDs") in the trait docblock, the entity, and `readConfig()`, but nothing in Phase 24
reads it — `FilesystemContractPass` scopes by tag only and the decorators never consult
`services`. This is a documented-but-unimplemented feature surface. Not a bug, but it advertises
behavior that does not exist; a user setting `services` expecting per-service limiting gets a
silent no-op.

**Fix:** Either drop `services` from the documented shape until it is implemented, or add an
explicit "not yet honored in v0.4" note in the trait/docs.

### IN-04: `AbstractTenant` filesystem-config getter/setter return `self` while the trait returns `static`

**File:** `src/Entity/AbstractTenant.php:162`; `src/Filesystem/TenantFilesystemConfigTrait.php:57`

**Issue:** `AbstractTenant::setFilesystemConfig()` returns `self`, but the equivalent trait
(`TenantFilesystemConfigTrait::setFilesystemConfig()`) returns `static`. The two are documented
as equivalent drop-ins (`AbstractTenant.php:54`). For a subclass, `self` on a fluent setter
narrows the return type to `AbstractTenant` rather than the concrete subclass, which is a minor
fluent-interface ergonomics inconsistency between the two equivalent adoption paths. The whole
of `AbstractTenant` uses `self`, so this matches local convention, but the documented
equivalence with the trait is imperfect.

**Fix:** Optional. For strict equivalence, the trait could return `self`, or `AbstractTenant`
setters could return `static`. Cosmetic.

### IN-05: `StubTenantFilesystemExtension` declares `filesystemConfig` `#[ORM\Column]` that can collide with `AbstractTenant`'s own column if combined

**File:** `tests/Integration/Support/StubTenantFilesystemExtension.php:28-29`

**Issue:** Test-only. `StubTenantWithFilesystem` implements `TenantInterface` directly (not via
`AbstractTenant`), so there is no collision today. But the trait carries an unconditional
`#[ORM\Column(type: 'json', nullable: true)]` on `filesystemConfig`; if a future stub both
`extends AbstractTenant` and `use`s this trait, Doctrine would see a duplicate column mapping
and fail. The production `TenantFilesystemConfigTrait` has the identical latent issue with
`AbstractTenant` (both declare the same property + column), but `AbstractTenant` inlines it
precisely so users do not double-apply. Worth a guard comment.

**Fix:** Add a docblock note on both traits: "Do not combine with `AbstractTenant`, which
already inlines this column."

### IN-06: Integration-test method name typos reduce searchability

**File:** `tests/Integration/Filesystem/FilesystemBootstrapperIntegrationTest.php:348`
(`testAutowiringDelivesDecorator`); `tests/Integration/Filesystem/LongRunningWorkerFilesystemSimulationTest.php:222`
(`testCrossTenatLeakNegativeAssertion`)

**Issue:** `Delives` (should be "Delivers") and `CrossTenat` (should be "CrossTenant") are
typos in test method names. Cosmetic, but they hurt grep-ability of the no-leak coverage that
these tests provide.

**Fix:** Rename to `testAutowiringDeliversDecorator` and `testCrossTenantLeakNegativeAssertion`.

### IN-07: Demo `TenantUploadController::index()` lists with `listContents('')` but never guards against the no-tenant case

**File:** `examples/saas/src/Controller/TenantUploadController.php:36-45`

**Issue:** When no tenant is resolved, `FilesystemPrefixingDecorator` passes through with an
empty prefix, so `listContents('')` returns the **entire shared adapter root across all
tenants** (`tenant_acme/...`, `tenant_globex/...`). For the authn-free demo this is acceptable
(the controller is explicitly marked "Remove from any non-local deployment"), but it is a
pattern worth flagging: copy-pasted into a real app without a tenant-required guard, the index
route would enumerate every tenant's filenames when accessed without a resolved tenant. The
docs' Pitfall section does not cover this "no-tenant lists everything" consequence of the
documented "no tenant = no scoping" semantic.

**Fix:** Demo is fine as-is given its disclaimer. Consider a one-line `if
(!$this->tenantContext->getTenant()) { ... }` guard in the demo plus a docs note that prefix
mode's no-tenant passthrough exposes the shared root.

---

## Verification notes (no defect — confirms the invariant held)

- **Live-read / zero mutable state:** Both decorators read `TenantContext` via a single private
  method (`prefixer()` / `resolve()`) called fresh per operation; all constructor properties are
  `readonly`; both are `final`; reflection tests pin "all properties readonly or static." The
  worker-reuse leak vector (RESEARCH Pitfall 4) is closed.
- **LRU close-on-eviction:** `LruFilesystemCache::set()` evicts the oldest entry and calls
  `close()` behind a `method_exists` guard; `clear()` closes all; move-to-end on `get()`/`set()`
  is correct. Tests cover eviction order, plain-operator-without-close graceful eviction, and
  re-set-same-slug not evicting others.
- **DSN credential-leak discipline (T-24-04-01):** `unsupportedScheme()` is called with the
  scheme name only, never the DSN; the s3 builder's optional-dep guard passes only the scheme;
  three negative tests assert `key=`/`secret=`/userinfo never appear in exception messages.
  `local:///srv/uploads` and `s3:///bucket` path extraction verified correct.
- **Path-traversal trust boundary:** correctly documented as application responsibility; the
  demo applies `basename()` before `writeStream`. Consistent across decorator docblocks, parser
  docblock, and the user guide.
- **Exception ancestry (Messenger no-retry):** both new exceptions extend `\LogicException`;
  the parser's transition-window fallback also throws `\LogicException`. Verified.

---

_Reviewed: 2026-06-03_
_Reviewer: Claude (gsd-code-reviewer)_
_Depth: standard_
