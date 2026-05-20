---
phase: 20-mailer-bootstrapper
reviewed: 2026-05-20T07:36:16Z
depth: standard
files_reviewed: 20
files_reviewed_list:
  - src/Bootstrapper/MailerBootstrapper.php
  - src/Command/Install/Step/MailerSetupStep.php
  - src/Command/TenancyInstallCommand.php
  - src/DependencyInjection/Compiler/MailerTransportContractPass.php
  - src/Entity/Tenant.php
  - src/Exception/TenantSanitizedTransportException.php
  - src/Mailer/DsnSanitizer.php
  - src/Mailer/LruTransportCache.php
  - src/Mailer/SanitizingMailerDecorator.php
  - src/Mailer/TenantAwareTransportsDecorator.php
  - src/Mailer/TenantContextClearedListener.php
  - src/Mailer/TenantMailerConfigTrait.php
  - src/Mailer/TenantMessageDecorator.php
  - src/Profiler/TenantDataCollector.php
  - src/Resources/views/Collector/tenant.html.twig
  - src/TenancyBundle.php
  - src/TenantInterface.php
  - config/services.php
  - config/services_dev.php
  - UPGRADE.md
findings:
  blocker: 2
  warning: 9
  info: 4
  total: 15
status: issues_found
---

# Phase 20: Code Review Report

**Reviewed:** 2026-05-20T07:36:16Z
**Depth:** standard
**Files Reviewed:** 20
**Status:** issues_found

## Summary

Phase 20 introduces per-tenant Mailer routing — a security-sensitive surface area centred on DSN handling, transport caching, and cross-tenant leak prevention. Architecture is solid (X-Transport strategy, bounded LRU with `stop()` on eviction, defense-in-depth tripwires in the profiler, idempotent install step). However, the review surfaced two BLOCKER-level data-leak vectors in the sanitization layer plus several correctness/robustness warnings that should be addressed before this lands in a 0.3 release.

The most impactful findings are:

- `TenantSanitizedTransportException` does not propagate / sanitize `TransportException::getDebug()` — raw DSN credentials remain accessible via the parent class's debug accessor (BL-01).
- `TenantAwareTransportsDecorator::send()` accepts `X-Transport: tenant_` with an empty slug, forwarding `''` to `TenantProviderInterface::findBySlug()` — provider behaviour on empty-string slug is undefined and may bypass the cross-tenant guard (BL-02).
- `SanitizingMailerDecorator` only catches `TransportExceptionInterface`; sibling exceptions from `Transport::fromDsn` (InvalidArgumentException, UnsupportedSchemeException, bridge-factory throws) bypass redaction (WR-01).
- Several install-step rough edges (hardcoded table name, no duplicate-migration check, Windows path comparison, migration class name with underscore) — none security-critical but each will surprise the first user who hits them.

---

## Blocker Issues

### BL-01: `TenantSanitizedTransportException` does not sanitize `getDebug()` — DSN credentials leak via parent `TransportException` API

**File:** `src/Exception/TenantSanitizedTransportException.php:17-22`, `src/Mailer/SanitizingMailerDecorator.php:30-38`

**Issue:**
`SanitizingMailerDecorator::send()` redacts only `$e->getMessage()` when wrapping the caught `TransportExceptionInterface` into a new `TenantSanitizedTransportException`. The parent class `Symfony\Component\Mailer\Exception\TransportException` has a private `$debug` field exposed via `getDebug(): string` and populated by `setDebug()` from concrete bridge transports (e.g. SMTP communication transcripts often contain the full DSN that produced the failed connection). Since the new wrapper exception is freshly constructed, `getDebug()` returns `''` — but the **previous** exception (still reachable via `$e->getPrevious()`) retains its `getDebug()` content unsanitized. User code that follows the documented Symfony pattern (`catch (TransportException $e) { $logger->error($e->getMessage(), ['debug' => $e->getDebug()]); }`) and walks the cause chain will write raw DSN credentials into logs.

The class docblock claims "the type contract is preserved" — but the **data contract** (`getDebug()` may contain unsanitized DSN) is silently broken. Phase 20's stated goal is "no raw password reaches user-visible surfaces"; `getDebug()` is a user-visible surface.

**Fix:**
Override `setDebug()` (or carry over and sanitize the previous exception's debug content at construction) so the wrapped exception exposes a redacted debug string. Two options:

```php
// Option 1: copy + sanitize at construction
final class TenantSanitizedTransportException extends TransportException
{
    public function __construct(string $message = '', int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
        if ($previous instanceof TransportException) {
            $this->setDebug(DsnSanitizer::redact($previous->getDebug()) ?? '');
        }
    }
}
```

Plus, in `SanitizingMailerDecorator::send()`, drop the `$previous` argument (or wrap into a non-chained exception) so user code walking `getPrevious()` cannot reach the unsanitized original. Either way, document the security invariant in the class docblock.

---

### BL-02: `TenantAwareTransportsDecorator::send()` accepts empty slug — provider lookup with `''` bypasses cross-tenant guard

**File:** `src/Mailer/TenantAwareTransportsDecorator.php:70-86`

**Issue:**
When a message arrives with header `X-Transport: tenant_` (literal, no slug after the underscore), the code path is:

```php
if (!str_starts_with($headerValue, 'tenant_')) { ... }
$slug = substr($headerValue, 7); // '' when headerValue is exactly 'tenant_'
$activeTenant = $this->context->getTenant();
if (null !== $activeTenant && $activeTenant->getSlug() !== $slug) {
    throw ...; // cross-tenant guard
}
$transport = $this->cache->get($slug) ?? $this->buildAndCache($slug);
```

The cross-tenant guard fires only when `$activeTenant` is non-null AND slug differs — but with no active tenant (worker before `TenantWorkerMiddleware` restoration, or sync-context misuse) the empty slug flows into `$this->cache->get('')` and `$this->provider->findBySlug('')`. `DoctrineTenantProvider::findBySlug('')` will likely return null/throw `TenantNotFoundException` (the security-critical case noted in UPGRADE.md §0.1→0.2), but the bundle has no positive validation here — relying on provider semantics that may differ across user-supplied `TenantProviderInterface` implementations.

This is a hostile-input vector: any code path (legitimate or malicious) that attaches `X-Transport: tenant_` to a message reaches `findBySlug('')`. A pathological provider implementation could return *the first tenant in the table* for an empty-slug query, silently routing a worker-context-less message to whatever tenant happens to be returned.

**Fix:**
Guard against empty slug explicitly at the decorator level, before any provider call:

```php
$slug = substr($headerValue, 7);
if ('' === $slug) {
    throw new \RuntimeException('tenancy: refusing to route mail — X-Transport "tenant_" has an empty slug.');
}
```

Additionally consider validating the slug character set against the bundle's existing slug-regex convention (`[a-z0-9_-]+`) to reject malformed slugs without a provider round-trip.

---

## Warnings

### WR-01: `SanitizingMailerDecorator` misses non-`TransportExceptionInterface` mailer exceptions — DSN may leak via bridge-factory throws

**File:** `src/Mailer/SanitizingMailerDecorator.php:30-38`

**Issue:**
The catch block only handles `TransportExceptionInterface`. `Transport::fromDsn()` (called inside `TenantAwareTransportsDecorator::buildAndCache`) can throw `Symfony\Component\Mailer\Exception\InvalidArgumentException` and `UnsupportedSchemeException` — both of which extend `\InvalidArgumentException` / `LogicException` and implement only the marker `ExceptionInterface`, NOT `TransportExceptionInterface`. Bridge transport factories (e.g. Sendgrid, SES) may also throw arbitrary `RuntimeException` subtypes during construction.

Verified against `vendor/symfony/mailer/Transport.php:113,160,179` and `vendor/symfony/mailer/Exception/UnsupportedSchemeException.php:20`. Confirmed that `Dsn::fromString` exception messages do not embed the DSN string in current symfony/mailer versions, but bridge factories (now or in future versions) may, and the bundle has no guarantee.

**Fix:**
Widen the catch in `SanitizingMailerDecorator::send()` to also cover `Symfony\Component\Mailer\Exception\ExceptionInterface` (or `\Throwable` with a re-throw of unrelated kinds), so any mailer-component exception bubbling out of the inner send call passes through `DsnSanitizer::redact()`:

```php
} catch (TransportExceptionInterface $e) {
    $sanitized = DsnSanitizer::redact($e->getMessage()) ?? $e->getMessage();
    throw new TenantSanitizedTransportException($sanitized, $e->getCode(), $e);
} catch (\Symfony\Component\Mailer\Exception\ExceptionInterface $e) {
    $sanitized = DsnSanitizer::redact($e->getMessage()) ?? $e->getMessage();
    throw new \RuntimeException($sanitized, $e->getCode(), $e);
}
```

(Or restructure to a single catch on `\Throwable` and re-throw with redacted message.)

---

### WR-02: `MailerSetupStep::scaffoldMigration` writes migration with underscored class name — incompatible with default Doctrine Migrations finder regex

**File:** `src/Command/Install/Step/MailerSetupStep.php:238-242`, `306-340`

**Issue:**
Generated class name: `Version20260520123456_AddTenantMailerColumns`. Doctrine Migrations 3.x `GlobFinder` / `RecursiveRegexFinder` extract migrations using a regex that — by default — accepts `Version[0-9]{14}` only. Some Symfony Flex recipes configure `migrations_paths` with `version_format` to accept descriptive suffixes, but the bundle's scaffold cannot assume that. The result on a default doctrine-migrations setup: the migration file is written but **not picked up by `doctrine:migrations:migrate`** — silent install failure (the user sees "Wrote migration: ..." success but `migrate` reports zero migrations).

**Fix:**
Drop the descriptive suffix and rely on the `getDescription()` body to communicate intent:

```php
$className = 'Version'.$timestamp; // no descriptive suffix
```

If a descriptive suffix is desired for human readability, prepend a comment block instead — or check `doctrine.migrations.yaml` for an explicit `version_format` before emitting the suffixed name. Either way, also print an `$io->note('Run: bin/console doctrine:migrations:migrate to apply.')` so the user notices if migration discovery fails.

---

### WR-03: `MailerSetupStep::scaffoldMigration` re-runs are not idempotent — duplicate migrations on every `tenancy:install --with-mailer`

**File:** `src/Command/Install/Step/MailerSetupStep.php:220-258`

**Issue:**
The migration class name embeds `gmdate('YmdHis')` so each invocation produces a new file. There is no pre-existence check for a migration that already adds the same columns. A user who runs `tenancy:install --with-mailer` twice (e.g. CI re-runs, user re-runs after editing yaml) gets a second migration file that will fail on the second `migrate` (column already exists). The entity-mutation step IS idempotent (TraitUse detection); the YAML-append IS idempotent (regex scan); the migration step is NOT — an asymmetry that will surprise users.

**Fix:**
Before scaffolding, glob `$migrationsDir/Version*.php` and grep for "AddTenantMailerColumns" or the literal `mailer_dsn` column name. If found, emit `$io->note('Mailer-columns migration already exists at %s — leaving unchanged.')` and return `alreadyRegistered()`. Mirrors the trait-detection idempotency in `updateEntity()`.

---

### WR-04: `MailerSetupStep::scaffoldMigration` hardcodes `tenancy_tenants` table name — wrong for user-supplied entities

**File:** `src/Command/Install/Step/MailerSetupStep.php:222-227`

**Issue:**
The `ALTER TABLE tenancy_tenants ADD COLUMN ...` SQL is hardcoded. The bundle's own `Entity\Tenant` uses `#[ORM\Table(name: 'tenancy_tenants')]`, but Phase 20 explicitly supports user-supplied Tenant entities (the `tenant_entity_class` config option, plus the trait-based migration path A in UPGRADE.md). A user with a `Tenant` entity mapped to `app_tenants` (or any other name) gets a migration that targets the wrong table and fails on `migrate`.

The `updateEntity()` step correctly resolves the user's entity path via reflection — but `scaffoldMigration()` does not derive the table name from the same entity.

**Fix:**
Resolve the table name from the tenant entity's `#[ORM\Table]` attribute via reflection (when doctrine/orm is installed) instead of hardcoding:

```php
$tableName = 'tenancy_tenants'; // fallback
if (null !== $tenantEntityClass && class_exists($tenantEntityClass)) {
    $rc = new \ReflectionClass($tenantEntityClass);
    foreach ($rc->getAttributes(\Doctrine\ORM\Mapping\Table::class) as $attr) {
        $tableName = $attr->newInstance()->name ?? $tableName;
    }
}
```

Then interpolate into the `$up` / `$down` SQL.

---

### WR-05: `TenancyInstallCommand::resolveTenantEntityPath` path-prefix check fails on Windows

**File:** `src/Command/TenancyInstallCommand.php:241-250`

**Issue:**
```php
if (!str_starts_with($fileReal, rtrim($projectDirReal, '/').'/')) {
    return $fallback;
}
```

`realpath()` on Windows returns paths with backslashes (`C:\Users\...\src\Entity\Tenant.php`). The hardcoded `/` separator in the prefix check guarantees a mismatch on Windows — the function silently falls back to `$projectDir.'/src/Entity/Tenant.php'` (a path that itself uses `/`, which Windows tolerates but is non-canonical) regardless of where the configured class actually lives. The "inside projectDir" security guard becomes a no-op on Windows.

**Fix:**
Use the platform separator (`DIRECTORY_SEPARATOR`) and normalize both sides:

```php
$sep = \DIRECTORY_SEPARATOR;
$projectDirReal = rtrim($projectDirReal, $sep).$sep;
if (!str_starts_with($fileReal, $projectDirReal)) {
    return $fallback;
}
```

Or, since the bundle CI matrix is Linux/macOS-only, document the Windows-not-supported limitation explicitly.

---

### WR-06: `LruTransportCache::stopTransport()` does not handle exceptions from `stop()` — partial-clear leaves cache in inconsistent state

**File:** `src/Mailer/LruTransportCache.php:68-101`

**Issue:**
```php
public function clear(): void
{
    foreach ($this->cache as $transport) {
        $this->stopTransport($transport); // may throw
    }
    $this->cache = [];
}
```

If `$transport->stop()` throws (network timeout closing an SMTP socket, broken QUIT, etc.), the foreach aborts and `$this->cache = []` never executes — leaving the cache populated with already-stopped (or partially-stopped) transports. On the next request, `get($slug)` returns a stopped transport and `send()` will fail or reconnect with stale state. Same hazard in `set()` on the eviction branch.

**Fix:**
Wrap `stop()` in try/catch (per transport) so partial failures don't poison the cache, and **clear before stopping** (or use a finally) so the invariant "cache empty after clear()" holds even on throw:

```php
public function clear(): void
{
    $transports = $this->cache;
    $this->cache = [];
    foreach ($transports as $transport) {
        try {
            $this->stopTransport($transport);
        } catch (\Throwable) {
            // best-effort socket teardown; cache emptiness is the load-bearing invariant
        }
    }
}
```

---

### WR-07: `DsnSanitizer::REDACTION_REGEX` may mangle non-DSN error text containing `:` and `@`

**File:** `src/Mailer/DsnSanitizer.php:19`

**Issue:**
Regex: `/(:[\/]{0,2}[^:]+:)[^@]+(@)/`. The `[\/]{0,2}` allows zero `/` characters after the first colon, meaning a free-text error like `Could not deliver to user@example.com via smtp:587 timeout` would match: `:587 timeout via smtp:` → `[^:]+` captures `587 timeout via smtp` → `:` → `[^@]+` captures `587 timeout via smtp` to `user`(wait, leftmost match wins; trace varies). At best the output is mangled; at worst it could redact useful diagnostics that a user needs to debug a non-credential failure.

This is not a security regression (the sanitizer over-redacts, not under-redacts) but it degrades the debuggability of `TenantSanitizedTransportException` messages. The profiler tripwire in `TenantDataCollector::collectMailerState` (line 157) uses a tighter regex `/:(?!\/\/)(?!\*\*\*@)[^:@\/]+@/` that requires `//` absence — DsnSanitizer should mirror it for symmetry.

**Fix:**
Tighten the sanitizer regex to require `://` (the actual DSN shape) instead of `[\/]{0,2}`:

```php
public const REDACTION_REGEX = '/(:\/\/[^:\/@]+:)[^@\/]+(@)/';
```

This still covers `smtp://user:pass@host`, `smtps://`, `failover(smtp://u:p@h1 smtp://u:p@h2)`, `sendmail://default` (no password, no match — correct), while declining to match free-text colons. Add a unit test for the failover composite case (multi-DSN with multiple passwords) explicitly.

---

### WR-08: `TenantAwareTransportsDecorator::send()` mutates the input message — retry / resend after success loses tenant routing

**File:** `src/Mailer/TenantAwareTransportsDecorator.php:88`

**Issue:**
```php
$message->getHeaders()->remove('X-Transport');
return $transport->send($message, $envelope);
```

Removing `X-Transport` mutates the caller's `Message` instance. If the message is re-sent after a successful first send (e.g. user code that retries on a downstream failure, or Symfony Messenger handler-level retries that re-dispatch the same Email instance), the second send will not see `X-Transport` and will fall back to the landlord transport. In a multi-tenant context that is a **silent cross-tenant misroute** — tenant A's mail goes out via the landlord's SMTP credentials, possibly bearing tenant A's From / Reply-To.

**Fix:**
Either (a) clone the message before mutating headers, or (b) leave the header in place and rely on the resolved transport (which is tenant-specific) to ignore it. Option (b) is simpler:

```php
// Do NOT remove X-Transport — the tenant-specific transport doesn't re-route on it.
return $transport->send($message, $envelope);
```

If the rationale for removal is to prevent the inner `Transports` from re-routing in some code path, document it in a regression test. As-is, removal is a footgun.

---

### WR-09: `MailerTransportContractPass::detectAsyncRouting` reads unprocessed extension config — misses env-var-driven routing

**File:** `src/DependencyInjection/Compiler/MailerTransportContractPass.php:71-105`

**Issue:**
`$container->getExtensionConfig('framework')` returns the **raw** configuration arrays as loaded from YAML/PHP — before normalization, env-var resolution, or merging from `framework.yaml + framework_prod.yaml`. A common Symfony pattern is:

```yaml
# framework.yaml
framework:
    messenger:
        routing:
            '%env(SEND_EMAIL_BUS)%': async
```

…where the FQCN is supplied at runtime via env var. The compile-time pass cannot see `Symfony\Component\Mailer\Messenger\SendEmailMessage` in the unresolved config and `detectAsyncRouting()` returns `false`, silently disabling the compile-time check. The user gets no warning; emails dispatched async fall back to landlord transport in production.

This is the documented limitation of `getExtensionConfig` (vs. processed configuration), and the bundle correctly exposes `tenancy.mailer.async: 'true'` as the explicit override. But the `'auto'` default is the documented escape hatch — and it is silently unreliable.

**Fix:**
Two options:
1. Strengthen the `auto` detection: also check `framework.messenger.transports` for `doctrine://` / `redis://` URLs (indicating async transport is likely configured) and pair with a tag-based check on `messenger.message_handler` services for `SendEmailMessageHandler`.
2. Lower-effort: change the default value of `tenancy.mailer.async` from `'auto'` to `'true'` (safer default — forces the user to opt out of the contract check rather than rely on detection that may fail). Document the rationale in UPGRADE.md.

Currently the `auto` default + silent-false-negative pattern means BOOT-04-f (the compile-time contract guard) has gaps in real-world configs.

---

## Info

### IN-01: `MailerSetupStep::TENANCY_YAML_MAILER_BLOCK` appends without leading newline normalization

**File:** `src/Command/Install/Step/MailerSetupStep.php:54-61`, `300`

**Issue:**
The constant begins with a blank line and the append adds a single trailing `\n`. If the target `tenancy.yaml` does not end with a newline (uncommon but possible), the result is `...lastline\n# Per-tenant...` instead of `...lastline\n\n# Per-tenant...` — block is glued onto the previous line's terminator. Minor cosmetic issue.

**Fix:**
Detect and normalize the trailing newline of the existing file before appending:

```php
$contents = (string) file_get_contents($path);
$prefix = str_ends_with($contents, "\n") ? '' : "\n";
$this->filesystem->appendToFile($path, $prefix.self::TENANCY_YAML_MAILER_BLOCK."\n");
```

---

### IN-02: `MailerSetupStep::updateEntity` does not detect trait used via parent class

**File:** `src/Command/Install/Step/MailerSetupStep.php:160-170`

**Issue:**
The TraitUse detection only walks the current class's direct `$class->stmts`. If a user has `class CustomTenant extends BundleTenant` and `BundleTenant` already uses `TenantMailerConfigTrait`, the installer will insert a duplicate `use` statement in `CustomTenant`, causing a PHP fatal error ("trait already used") on the next request. Edge case but reachable.

**Fix:**
After detecting no direct TraitUse, attempt reflection on the class hierarchy to check `class_uses($fqcn, true)` (autoloads if necessary) for `TenantMailerConfigTrait` via parent chain. If found, treat as `alreadyRegistered`.

---

### IN-03: `Tenant` entity column duplication with trait — implicit fatal error path

**File:** `src/Entity/Tenant.php:35-42`, `src/Mailer/TenantMailerConfigTrait.php:21-28`

**Issue:**
The bundle's own `Tenant` entity inlines `mailerDsn`, `mailerFrom`, `mailerReplyTo`. The `TenantMailerConfigTrait` declares the same three properties. A user extending `Tenancy\Bundle\Entity\Tenant` who also does `use TenantMailerConfigTrait` will get a PHP fatal error at class-load time ("trait property conflicts with parent"). UPGRADE.md hints at this but doesn't call out the failure mode explicitly.

**Fix:**
Add an UPGRADE.md callout: "Do NOT both extend the bundle's `Tenant` and `use TenantMailerConfigTrait` — pick exactly one path." Optionally, consider refactoring `Entity\Tenant` to itself `use TenantMailerConfigTrait` so the bundle has a single source of truth (trade-off: ORM column placement order changes).

---

### IN-04: `TenantDataCollector::collectMailerState` tripwire regex misses `***@` at non-credential positions

**File:** `src/Profiler/TenantDataCollector.php:157`

**Issue:**
The tripwire regex `/:(?!\/\/)(?!\*\*\*@)[^:@\/]+@/` checks for residual credentials but assumes the only legitimate `:NNN@` shape post-redaction is `:***@`. A correctly-redacted DSN with port-in-host syntax like `smtp://user:***@host:587` is fine — the third `:` (port) is not followed by `@`. But a user with `mailerFrom = 'support:queue@example.com'` (a non-standard but legal email local-part with `:`) collected into the `from` field would be safe because the regex only runs on the redacted DSN, not on `from`. So this is not a live bug — but the tripwire's defensive intent could be strengthened by also asserting the redacted DSN ends with the host portion intact (no `[^:@\/]+@[^@]*$` containing `@` twice).

**Fix:**
Non-blocking. Consider adding a positive assertion (regex MUST match `://[^:@]+:\*\*\*@`) in addition to the negative tripwire — defense-in-depth + future-regression-proofing.

---

_Reviewed: 2026-05-20T07:36:16Z_
_Reviewer: Claude (gsd-code-reviewer)_
_Depth: standard_
