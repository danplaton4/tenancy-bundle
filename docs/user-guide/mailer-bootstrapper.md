# Mailer Bootstrapper

Per-tenant SMTP transport with per-tenant `From` / `Reply-To` headers, correct under both synchronous Mailer dispatch and Messenger-routed async dispatch. When tenant `acme` triggers an email — whether it goes out immediately on `kernel.terminate` or sits on a Messenger queue for 10 minutes before a worker picks it up — the message is delivered through **`acme`'s** SMTP credentials, never the landlord's, never another tenant's.

This page covers the four things you need to know to operate the bootstrapper in production: how to configure per-tenant DSNs, how the X-Transport strategy keeps the wire-format async-safe, what happens when a worker dequeues a message for a tenant that no longer exists, and how to migrate a project that already has a custom `Tenant` entity. For the broader bundle config keys (`tenancy.mailer.async`, `tenancy.mailer.transport_cache_size`), see [Configuration Reference](configuration.md).

---

## How it works

`MailerBootstrapper` implements `TenantBootstrapperInterface` and is registered unconditionally in the bundle's DI container, guarded by `interface_exists(\Symfony\Component\Mailer\MailerInterface::class)`. If `symfony/mailer` is not installed, the bootstrapper compiles out entirely — zero runtime cost for projects that don't need email.

When a tenant is resolved, the bootstrapper provisions a per-tenant SMTP transport built from the tenant's `mailerDsn`. The transport is cached in an LRU keyed on tenant slug (default 32 entries, configurable via `tenancy.mailer.transport_cache_size`) so long-running Messenger workers don't pay the DSN-parse + socket-open cost on every send. On `TenantContextCleared` the cache entry for that tenant is evicted and the underlying SMTP connection is closed cleanly — no socket leaks even after 10k tenant context switches.

The clever part is the worker side: a `TenantMessageDecorator` listens on Symfony's `MessageEvent` and stamps each outgoing email with an `X-Transport: tenant_<slug>` header **before** Messenger serializes the envelope. The stamp survives the broker round-trip (Doctrine, AMQP, Redis — all tested), and when the worker dequeues the message the same `MailerBootstrapper` reads `X-Transport`, looks up the slug, and routes the send through that tenant's cached transport. Same code path, sync or async.

---

## Configuring per-tenant SMTP

Each tenant carries three nullable columns:

| Column | Purpose | Fallback when `null` |
|--------|---------|----------------------|
| `mailerDsn` | SMTP transport DSN (e.g. `smtp://user:pass@host:587`) | Landlord's default Mailer DSN |
| `mailerFrom` | `From:` header on outgoing emails | Application's default `From` |
| `mailerReplyTo` | `Reply-To:` header (optional) | No `Reply-To` header set |

Returning `null` from any of the three is the **landlord fallback**: emails for that tenant go through the application's default Mailer config. This is the right behavior for tenants that haven't yet been onboarded onto their own SMTP provider.

### Option A: use `TenantMailerConfigTrait` (recommended)

The bundle ships `Tenancy\Bundle\Mailer\TenantMailerConfigTrait` which provides the three columns, the three getters, and the three setters in a single `use` statement:

```php
<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Tenancy\Bundle\Entity\AbstractTenant;
use Tenancy\Bundle\Mailer\TenantMailerConfigTrait;

#[ORM\Entity]
#[ORM\Table(name: 'tenancy_tenants')]
class AppTenant extends AbstractTenant
{
    use TenantMailerConfigTrait;
}
```

The trait's `#[ORM\Column]` attributes are only honored when `doctrine/orm` is installed (matching the bundle's optional-Doctrine convention). With Doctrine absent, the trait still works as plain PHP property storage.

### Option B: implement the methods by hand

If your project uses non-standard column names, a different storage type, or stores mailer config in a separate table, implement the three `TenantInterface` methods yourself:

```php
public function getMailerDsn(): ?string { return $this->resolveMailerDsn(); }
public function getMailerFrom(): ?string { return $this->mailerFrom; }
public function getMailerReplyTo(): ?string { return $this->mailerReplyTo; }
```

You retain full control over storage. The bootstrapper only ever calls these three methods on the resolved tenant — no schema constraints are enforced beyond the interface contract.

---

## The X-Transport strategy

Symfony Mailer routes emails by **transport name**, not by `From` header. A multi-transport `mailer.yaml` like this:

```yaml
framework:
    mailer:
        transports:
            tenant_acme: '%env(ACME_MAILER_DSN)%'
            tenant_globex: '%env(GLOBEX_MAILER_DSN)%'
            main: '%env(MAILER_DSN)%'
```

…picks the active transport from an `X-Transport: tenant_acme` header on the outgoing message. The bundle's `TenantMessageDecorator` listens on `MessageEvent` (which fires for every queued OR sent message) and stamps that header automatically based on the active `TenantContext`. The application code calls `$mailer->send($email)` exactly as it would in a single-tenant app — the decorator handles tenancy transparently.

The reason this matters for **async dispatch**: `MessageEvent` fires **before** Messenger serializes the envelope and pushes it onto the broker. The `X-Transport` header travels with the serialized message all the way through the broker → worker → deserialize chain. When the worker calls `$mailer->send()` on the deserialized message, Mailer reads `X-Transport: tenant_acme` and routes through `acme`'s SMTP transport — even though the HTTP context where the dispatch originated has long since cleared.

This is sync-safe AND async-safe — same code path for both. No worker-side context restoration is needed for transport selection; the message is self-describing.

---

## Async failure-mode warning

When Mailer is routed asynchronously through Messenger, there's one operational caveat you must understand: **a message dequeued for a deleted tenant must throw, not silently drop.**

Concretely: imagine tenant `acme` triggers an email at 10:00 AM. The `SendEmailMessage` lands on the queue with `X-Transport: tenant_acme`. At 10:05 AM, an admin deletes the `acme` tenant from the landlord database. At 10:10 AM, a Messenger worker dequeues the message. The worker has two options:

1. **Silently drop the email** — succeed the worker, send nothing. This is the worst possible failure mode: the application thinks the email was sent, the user expects to receive it, and there's no audit trail.
2. **Throw an exception** — the message goes to the Dead Letter Queue (DLQ) where ops can inspect it.

The bundle enforces option 2 at **container compile time**. `src/DependencyInjection/Compiler/MailerTransportContractPass.php` checks two invariants:

- If `symfony/mailer` is installed, the `tenancy.mailer.async` parameter must be declared (allowed values: `'auto'`, `'true'`, `'false'`). Missing parameter → `LogicException` at compile.
- If Mailer is routed async via Messenger (auto-detected by inspecting `framework.messenger.routing` for `SendEmailMessage`, OR forced via `tenancy.mailer.async: true`), the X-Transport decorator service (`tenancy.mailer.message_decorator`) must be wired. Missing decorator → `LogicException` at compile with a message pointing back to this docs page.

The result: misconfiguring async Mailer transport selection is **impossible** in production. The container won't build. The worker can't ship.

**Operationally:** monitor your DLQ for `TenantSanitizedTransportException` (see `src/Mailer/Exception/TenantSanitizedTransportException.php`). These represent messages dispatched in one tenant context that failed to send in the worker context — usually because the tenant was deleted between dispatch and processing, or because the tenant's `mailerDsn` was rotated mid-flight. The DSN credential is **redacted** from the exception trace (per BOOT-04 acceptance) so safe to surface in Slack alerts.

---

## Migration recipe (existing projects with a custom Tenant entity)

If your project upgraded from v0.2 → v0.3 and already has a custom `Tenant` entity, the canonical migration recipe lives in [`UPGRADE.md`](https://github.com/danplaton4/tenancy-bundle/blob/master/UPGRADE.md#02-to-03) under the **0.2 to 0.3** section. Two paths are documented there:

- **Path A (recommended):** add `use TenantMailerConfigTrait;` to your entity class body. Run `bin/console doctrine:migrations:diff` and apply the generated migration — the three nullable columns will be added to your tenants table.
- **Path B:** implement the three `TenantInterface` methods manually (use this when your column names, types, or storage strategy diverge from the trait defaults).

**Don't read this page for the migration recipe — read `UPGRADE.md`.** This page deliberately does not duplicate the ALTER TABLE snippet to avoid drift between the two locations.

### Automated migration via `tenancy:install --with-mailer`

The Phase 18 install command, `bin/console tenancy:install`, accepts a `--with-mailer` flag that does the three migration steps for you on standard-layout projects:

1. Scaffolds a Doctrine migration into your configured migrations directory adding `mailer_dsn`, `mailer_from`, `mailer_reply_to` columns to the `tenancy_tenants` table.
2. Edits your custom `Tenant` entity (resolved from `tenancy.tenant_entity_class`) to insert `use TenantMailerConfigTrait;`. AST-driven via `nikic/php-parser`; on non-standard entity layouts the command refuses to mutate and prints the manual snippet instead.
3. Adds a commented `mailer:` block to `config/packages/tenancy.yaml` showing the defaults (`transport_cache_size: 32`, `async: auto`) so they're discoverable but inactive.

After running `tenancy:install --with-mailer`, apply the migration with `bin/console doctrine:migrations:migrate` and you're done. See [CLI Commands](cli-commands.md#tenancy-install) for the full command surface.

---

## See also

- [Configuration Reference](configuration.md) — `tenancy.mailer.async`, `tenancy.mailer.transport_cache_size`, the per-tenant mailer config block.
- [CLI Commands](cli-commands.md#tenancy-install) — `tenancy:install --with-mailer` scaffold flag.
- [UPGRADE.md → 0.2 to 0.3](https://github.com/danplaton4/tenancy-bundle/blob/master/UPGRADE.md#02-to-03) — the canonical BC-break migration recipe.
- [Messenger Integration](messenger.md) — how tenant context propagates through Messenger workers (the same `BootstrapperChain::boot()` invocation that powers async Mailer dispatch).
- [Profiler Tab](profiler-tab.md) — the dev-only Tenancy WDT panel shows the active tenant's `mailerFrom`, redacted `mailerDsn`, transport cache size, and last-send strategy. Catches misconfigs before they reach prod.
