# Filesystem Bootstrapper (BOOT-03)

Per-tenant filesystem scoping via [Flysystem](https://flysystem.thephpleague.com/). When a tenant
is resolved, every Flysystem service tagged `tenancy.scoped` automatically points at the active
tenant's storage — either as a sub-prefix on a shared adapter (prefix mode) or as a per-tenant
adapter instance (per-tenant-adapter mode). Untagged Flysystem services bypass scoping, so
landlord-side assets and shared resources remain accessible across the resolver chain.

This page covers what the bootstrapper does, how to configure both isolation strategies, the
configuration reference, exception handling, the path-traversal trust boundary, and a FAQ
section addressing common pitfalls. A polished version of this guide will land in the v0.4 docs
refresh (Phase 29 / DOC-20).

---

## Overview

`FilesystemBootstrapper` implements `TenantBootstrapperInterface` and is registered
conditionally in the bundle's DI container, guarded by
`interface_exists(\League\Flysystem\FilesystemOperator::class)`. If `league/flysystem-bundle`
is not installed, the bootstrapper and its compiler pass compile out entirely — zero runtime
cost for projects that don't need per-tenant filesystem operations.

The bootstrapper's `boot()` is a **no-op**. Per-tenant scoping is applied transparently at
call time by two Symfony service decorators:

- **`FilesystemPrefixingDecorator`** (prefix mode) — wraps the underlying `FilesystemOperator`
  and prepends `tenant_{slug}/` to every path argument before delegating to the inner
  operator. No per-tenant adapter instances created; one shared adapter for all tenants.
- **`TenantAwareFilesystemDecorator`** (per-tenant-adapter mode) — reads the active tenant's
  `filesystemConfig.adapter_dsn` at call time, builds (and caches in a bounded LRU) a
  per-tenant `FilesystemOperator` instance, and delegates to it.

The `clear()` method flushes the LRU adapter cache so per-tenant connections close cleanly
in long-running Messenger workers. Mirrors the `MailerBootstrapper` lifecycle shape exactly.
Boot order: DatabaseSwitch (0) → Doctrine (−10) → Mailer (−20) → Filesystem (−30). `clear()`
runs in reverse.

`FilesystemContractPass` wires the decoration at compile time: it walks every service tagged
`tenancy.scoped`, finds the matching Flysystem storage definitions (registered by
`league/flysystem-bundle` under the user-supplied storage name), and rewrites each definition
with `setDecoratedService(...)` so the correct decorator wraps it transparently. Users tag
their own storage definitions once; the pass does the rest.

Locked decision: **DEC-FILE-MULTI** — only services tagged `tenancy.scoped` are scoped.
Non-tagged Flysystem services bypass scoping entirely (the escape hatch for landlord-side
assets, public CDN directories, etc.).

---

## Installation

```bash
composer require league/flysystem-bundle
```

The bundle treats `league/flysystem-bundle` as an **optional** dependency guarded by
`interface_exists(\League\Flysystem\FilesystemOperator::class)`. Installing it is only
necessary when `tenancy.filesystem.enabled: true` — the bundle no-ops if it is absent.

For in-memory storage in tests:

```bash
composer require --dev league/flysystem-memory
```

---

## Quick Start (prefix mode)

Prefix mode is the default (DEC-FILE-MODE). It requires no per-tenant configuration — a single
shared adapter is used and every Flysystem operation is transparently scoped to
`tenant_{slug}/` for the active tenant.

**Step 1 — Define a storage in `config/packages/flysystem.yaml`:**

```yaml
flysystem:
    storages:
        users.storage:
            adapter: local
            options:
                directory: '%kernel.project_dir%/var/storage/users'
```

**Step 2 — Tag it in `config/services.yaml`:**

```yaml
services:
    users.storage:
        tags:
            - { name: tenancy.scoped, strategy: prefix, prefix_template: 'tenant_{slug}/' }
```

**Step 3 — Enable the filesystem bootstrapper in `config/packages/tenancy.yaml`:**

```yaml
tenancy:
    filesystem:
        enabled: true
```

**Step 4 — Inject the decorated storage in your controller or service:**

```php
use League\Flysystem\FilesystemOperator;

class UploadController extends AbstractController
{
    public function __construct(
        private readonly FilesystemOperator $usersStorage,
    ) {}

    public function upload(Request $request): Response
    {
        $file = $request->files->get('upload');
        // No slug prefix needed — FilesystemPrefixingDecorator handles it transparently.
        $this->usersStorage->writeStream(
            basename($file->getClientOriginalName()),
            fopen($file->getRealPath(), 'r'),
        );
        // ...
    }
}
```

The `$usersStorage` argument is autowired by `league/flysystem-bundle`'s
`registerAliasForArgument` machinery (camelCased variable name → storage service ID). At
compile time `FilesystemContractPass` replaces the `users.storage` definition with a
`FilesystemPrefixingDecorator` wrapping the inner storage — the controller receives the
decorator, not the raw adapter.

A working end-to-end example lives in `examples/saas/src/Controller/TenantUploadController.php`
and the matching template in `examples/saas/templates/upload/index.html.twig`.

---

## Per-tenant-adapter mode

Per-tenant-adapter mode routes each tenant's Flysystem operations to a **separate adapter
instance** — a distinct S3 bucket, a per-tenant local directory on a different mount point,
or an in-memory adapter (tests). Use this mode for regulated deployments where tenant data
must be physically isolated rather than logically prefixed within a shared adapter.
(DEC-FILE-MODE + DEC-FILE-CONFIG)

### Configuration

**Tag the storage with `strategy: per_tenant_adapter`:**

```yaml
# config/services.yaml
services:
    tenant_buckets.storage:
        tags:
            - { name: tenancy.scoped, strategy: per_tenant_adapter }
```

**Provide an `adapter_dsn` on the tenant entity** via `TenantFilesystemConfigTrait` or a
manual implementation:

```php
use Tenancy\Bundle\Filesystem\TenantFilesystemConfigTrait;

class AppTenant extends AbstractTenant
{
    use TenantFilesystemConfigTrait;
}
```

Then seed each tenant's config:

```php
$tenant->setFilesystemConfig(['adapter_dsn' => 's3:///my-bucket?region=eu-central-1']);
```

**Supported DSN schemes** (Phase 24 scope):

| Scheme | Adapter |
|--------|---------|
| `local://` | `League\Flysystem\Local\LocalFilesystemAdapter` |
| `memory://` | `League\Flysystem\InMemory\InMemoryFilesystemAdapter` |
| `s3://` | `League\Flysystem\AwsS3V3\AwsS3V3Adapter` |

Additional schemes can be registered via `AdapterDsnParser::addScheme()` in a custom
compiler pass. Unknown schemes throw `UnsupportedAdapterDsnSchemeException` at runtime.

**Enable `allow_per_tenant_adapter`** (default `true`; disable on shared-hosting deployments
where per-tenant S3 credentials are a security risk):

```yaml
tenancy:
    filesystem:
        enabled: true
        allow_per_tenant_adapter: true
```

### Tenant config shape

`TenantFilesystemConfigTrait::getFilesystemConfig()` returns:

```php
?array{
    prefix?: string,          // override for prefix mode (defaults to 'tenant_{slug}/')
    adapter_dsn?: string,     // per_tenant_adapter DSN (e.g. 's3:///bucket?region=eu-central-1')
    services?: string[],      // reserved — no-op in v0.4; setting this key has no effect in the current release
}
```

When `null` is returned, prefix mode with the default template applies — the zero-config
path for new installs.

---

## Configuration reference

All keys live under `tenancy.filesystem.*` in `config/packages/tenancy.yaml`.

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `enabled` | `bool` | `false` | Enable the filesystem bootstrapper. Set to `true` to activate. |
| `allow_per_tenant_adapter` | `bool` | `true` | Allow `per_tenant_adapter` strategy. Disable on shared-hosting deployments to prevent per-tenant adapter instantiation. |
| `prefix_template` | `string` | `'tenant_{slug}/'` | Template for prefix mode. `{slug}` is replaced with the active tenant's slug at call time. |
| `cache_size` | `int` | `32` | Maximum number of per-tenant adapter instances kept in the LRU cache. Increase for workloads with many concurrent tenants; decrease to reduce memory pressure in constrained workers. |

Example showing all keys:

```yaml
tenancy:
    filesystem:
        enabled: true
        allow_per_tenant_adapter: true
        prefix_template: 'tenant_{slug}/'
        cache_size: 32
```

---

## Exception handling

### `MissingFilesystemConfigException`

Thrown when `per_tenant_adapter` mode is active and the resolved tenant's
`filesystemConfig.adapter_dsn` is `null` or missing.

```php
Tenancy\Bundle\Exception\MissingFilesystemConfigException extends \LogicException
```

**Extends `\LogicException`, NOT `\RuntimeException`.** This is intentional (DEC-FILE-EXCEPTION,
mirroring the Phase 23 WR-01 Messenger no-retry pattern): Symfony Messenger's default retry
strategy retries `RuntimeException` subclasses. A misconfigured tenant should **not** be
retried — it is a programmer or operator error (missing DSN in the database). The message goes
to the Dead Letter Queue (DLQ) immediately. Monitor your DLQ for this exception class.

### `UnsupportedAdapterDsnSchemeException`

Thrown when `per_tenant_adapter` mode encounters an `adapter_dsn` whose scheme is not
registered in `AdapterDsnParser`. For example, `ftp:///uploads` would throw this exception
because Phase 24 ships only `local://`, `memory://`, and `s3://`.

```php
Tenancy\Bundle\Exception\UnsupportedAdapterDsnSchemeException extends \LogicException
```

Also extends `\LogicException` — same no-retry semantics.

### `FilesystemContractPass` compile-time guards

Three errors are surfaced at container compile time (not at runtime):

1. **Bundle absent + enabled:** `tenancy.filesystem.enabled: true` but
   `league/flysystem-bundle` is not installed → `LogicException` pointing at the install
   command.
2. **Per-tenant-adapter forbidden:** a service is tagged `strategy: per_tenant_adapter` but
   `tenancy.filesystem.allow_per_tenant_adapter` is `false` → `LogicException`.
3. **Invalid strategy:** a service is tagged with an unknown `strategy` value (not `prefix`
   or `per_tenant_adapter`) → `LogicException`.

---

## Trust boundary: path traversal

!!! warning "Application responsibility"
    The bundle treats **all path arguments passed to `$filesystem->write($path)`,
    `$filesystem->read($path)`, etc. as TRUSTED**. If user-supplied values (e.g. upload
    filenames, query parameters) reach a Flysystem call without sanitisation, a path-traversal
    attack is possible.

The bundle does NOT sanitise path arguments. This is an application-level concern.

**Recommended sanitisation for upload filenames:**

```php
// Minimal: strips directory traversal components.
$filename = basename($file->getClientOriginalName());
$filesystem->writeStream($filename, $stream);
```

**For read paths from user input:**

```php
// Verify the resolved path stays within the tenant's own storage root.
// realpath() returns false for non-existent paths — check before using.
$resolved = realpath($root . '/' . $userPath);
if (false === $resolved || !str_starts_with($resolved, realpath($root))) {
    throw new \InvalidArgumentException('Path traversal detected.');
}
```

Flysystem 3's `LocalFilesystemAdapter` ships `LocationGuard` which prevents symlink traversal
(the `DISALLOW_LINKS` default mode), but does **not** block `..` in path arguments at the
API level. The application MUST sanitise before calling the Flysystem API.

See the [Flysystem security disclosure procedure](https://github.com/thephpleague/flysystem-bundle/blob/3.x/docs/A-security-disclosure-procedure.md)
for upstream guidance.

---

## FAQ

### Pitfall 1: My `FilesystemOperator $defaultStorage` argument doesn't resolve

**Symptom:** `ServiceNotFoundException` or autowiring failure on `FilesystemOperator $defaultStorage`.

**Cause:** `league/flysystem-bundle` registers storages under their user-supplied name (e.g.
`default.storage`), not a derived key like `flysystem.filesystem.default.storage`. The
bundle's `registerAliasForArgument` call creates a typed argument alias so `FilesystemOperator
$defaultStorage` resolves to `default.storage`. After `FilesystemContractPass` decorates it,
the alias still points at the outermost decorator.

**Fix:** Use the camelCased variable name matching the storage name. For `users.storage` use
`FilesystemOperator $usersStorage`.

---

### Pitfall 2: `listContents()` return type errors

**Symptom:** PHPStan warns about wrong return type; `foreach` on the return value throws.

**Cause:** Flysystem 1.x returned `array`; Flysystem 3 returns
`\League\Flysystem\DirectoryListing implements IteratorAggregate`. Code written for
Flysystem 1.x that does `$files = $fs->listContents(''); $files[0]->path()` will fail.

**Fix:** Iterate with `foreach` or call `->toArray()`:

```php
foreach ($this->usersStorage->listContents('') as $item) {
    echo $item->path();
}
```

---

### Pitfall 3: `InMemoryFilesystemAdapter` class not found in tests

**Symptom:** `Error: Class "League\Flysystem\InMemory\InMemoryFilesystemAdapter" not found`.

**Cause:** `league/flysystem-memory` is a separate Composer package — it is NOT transitively
required by `league/flysystem` even though its namespace looks like it should be.

**Fix:** `composer require --dev league/flysystem-memory`.

---

### Pitfall 4: Cross-tenant data leak in long-running workers

**Symptom:** Tenant A's files appear under Tenant B's listing after a context switch.

**Cause:** A decorator that caches the prefix (or any tenant-specific value) in an instance
property will retain that value across context switches in a Messenger worker.

**Fix:** The bundle's `FilesystemPrefixingDecorator` reads `TenantContext` **live on every
call** — no mutable instance state. If you write a custom decorator, follow the same pattern:
never memoize tenant-derived values. Use `FilesystemBootstrapper::clear()` (or the
`TenantContextClearedListener`) to flush the per-tenant adapter LRU.

---

### Pitfall 5: Path traversal via user-supplied filenames

**Symptom:** A tenant uploads a file named `../../etc/passwd`.

**Fix:** Apply `basename()` before writing; add a `realpath()` guard for explicit-path reads.
See [Trust boundary: path traversal](#trust-boundary-path-traversal) above.

---

### Pitfall 6: Decorated storage bypasses autowiring

**Symptom:** A service that type-hints `FilesystemOperator $usersStorage` receives the raw
`League\Flysystem\Filesystem` instead of the `FilesystemPrefixingDecorator`.

**Cause:** `ScopedStorageTaggingPass` must run **before** `FilesystemContractPass`. In test
kernels, register `ScopedStorageTaggingPass` at `PassConfig::TYPE_BEFORE_OPTIMIZATION`
priority `10` (FilesystemContractPass runs at priority `0`) so tags are attached before the
pass walks `findTaggedServiceIds('tenancy.scoped')`.

**Fix (test kernels only):**
```php
$container->addCompilerPass(
    new ScopedStorageTaggingPass(),
    \Symfony\Component\DependencyInjection\Compiler\PassConfig::TYPE_BEFORE_OPTIMIZATION,
    10,
);
```

In production configurations, the user's `services.yaml` tag is read before compiler passes
run — ordering is not an issue.

---

### Pitfall 7: `Filesystem` (concrete) vs `FilesystemOperator` (interface) confusion

**Symptom:** `TypeError: Argument 1 expected Filesystem, FilesystemOperator given`.

**Cause:** Decorators implemented against the concrete `Filesystem` class reject the
`LazyFilesystem` and other `FilesystemOperator` implementations the bundle may use.

**Fix:** Always type-hint the **interface** `\League\Flysystem\FilesystemOperator`, both for
the constructor's `$inner` parameter and for the service alias. The bundle's concrete
`Filesystem` satisfies the interface; so does any adapter-specific wrapper.

---

## See also

- [Configuration Reference](configuration.md) — `tenancy.filesystem.*` keys.
- [UPGRADE.md → 0.3 to 0.4](../../UPGRADE.md#03-to-04) — the zero-BC-break adoption path
  for existing v0.3 projects.
- [Mailer Bootstrapper](mailer-bootstrapper.md) — the structural twin; the filesystem
  bootstrapper follows the same lifecycle shape.
- [Profiler Tab](profiler-tab.md) — Phase 29 (DOC-20) will add a Filesystem subsection to
  the WDT panel showing the active tenant's storage strategy, prefix, and LRU cache state.
- [Shared Entities](shared-entities.md) — the `#[Shared]` sync model: landlord-side master records fanned out as tenant-side read-only copy to each tenant database.
- [PHPStan Extension](phpstan-extension.md) — static-analysis rules that catch tenancy + filesystem misconfiguration at edit time.
