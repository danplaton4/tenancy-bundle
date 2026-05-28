# Installation

Tenancy Bundle requires **PHP ^8.2** and **Symfony ^7.4 or ^8.0**. It is published on Packagist as [`danplaton4/tenancy-bundle`](https://packagist.org/packages/danplaton4/tenancy-bundle).

---

## 1. Install via Composer

```bash
composer require danplaton4/tenancy-bundle
```

This pulls in the bundle and its hard dependencies. As of v0.3.3, that includes [`nikic/php-parser`](https://github.com/nikic/PHP-Parser), which the install command uses to safely register the bundle in your Symfony app — you do not need to install it separately.

---

## 2. Run the install command

Run the bundled install command to register the bundle and scaffold a starter config:

```bash
bin/console tenancy:install
```

In one shot, this command auto-registers `Tenancy\Bundle\TenancyBundle::class` in your application's bundle list (using an AST-safe edit, never a blind regex), writes a fully commented `config/packages/tenancy.yaml` with sensible defaults, and prints next-step guidance for picking a driver and wiring your first tenant. See the [`tenancy:install` CLI reference](cli-commands.md#tenancy-install) for the full surface.

Two useful flags:

- `bin/console tenancy:install --dry-run` — prints the proposed mutations without writing anything, so you can review before applying.
- `bin/console tenancy:install --force` — overwrites an existing `config/packages/tenancy.yaml` (the default behavior refuses to overwrite). Useful when re-running the scaffold after a deliberate reset.

If your application's bundle list has a non-standard shape that the AST parser refuses to mutate, the command prints a copy-paste snippet showing the exact line to add manually. The scaffold step always runs even when the registration step is skipped.

---

## 3. Optional Dependencies

The bundle uses `class_exists()` and `interface_exists()` guards throughout. Features that require an optional package are silently skipped when that package is absent — you will never get a fatal error for a feature you do not use.

| Feature | Required Package | Notes |
|---------|-----------------|-------|
| Database-per-tenant driver | `doctrine/orm`, `doctrine/dbal`, `doctrine/doctrine-bundle` | `Doctrine\DBAL\Driver\Middleware`-based connection switching at runtime |
| Shared-DB driver | `doctrine/orm`, `doctrine/dbal`, `doctrine/doctrine-bundle` | Doctrine SQL filter with `#[TenantAware]` attribute |
| Tenant migrations | `doctrine/migrations` | `tenancy:migrate` command |
| Messenger context propagation | `symfony/messenger` | `TenantStamp`, sending/worker middlewares — auto-enrolled in all buses |
| Per-tenant mailer | `symfony/mailer` | `MailerBootstrapper` with X-Transport strategy |

!!! note "Core runs without Doctrine"
    If you only need header/subdomain resolution and cache isolation, the bundle runs without any Doctrine package installed. The resolver chain, bootstrapper lifecycle, and cache namespacing are all dependency-free.

---

## 4. Requirements Summary

| Requirement | Version |
|-------------|---------|
| PHP | `^8.2` |
| Symfony | `^7.4` or `^8.0` |
| doctrine/orm *(optional)* | `^2.17` or `^3.0` |
| doctrine/dbal *(optional)* | `^3.6` or `^4.0` |
| doctrine/doctrine-bundle *(optional)* | `^2.11` |
| doctrine/migrations *(optional)* | `^3.7` |
| symfony/messenger *(optional)* | `^7.4` or `^8.0` |
| symfony/mailer *(optional)* | `^7.4` or `^8.0` |

---

## 5. Verification

After installation, confirm the bundle is registered:

```bash
bin/console debug:container tenancy.context
```

Expected output (abbreviated):

```
Information for Service "tenancy.context"
=========================================

 Service ID  tenancy.context
 Class       Tenancy\Bundle\Context\TenantContext
 Tags        -
 Public      no
 Shared      yes
```

If you see `tenancy.context` in the output, the bundle is correctly wired.

Check that the resolver chain is populated:

```bash
bin/console debug:container tenancy.resolver_chain
```

You can also list all tenancy services:

```bash
bin/console debug:container tenancy
```

---

## Next Steps

You are ready to set up your first tenant. Continue to the [Getting Started](getting-started.md) walkthrough for a 5-minute end-to-end setup.
