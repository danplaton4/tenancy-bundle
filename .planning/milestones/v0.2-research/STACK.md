# Stack Research

**Domain:** Symfony reusable bundle — multi-tenancy as a first-class kernel citizen
**Researched:** 2026-03-17
**Confidence:** HIGH (all versions verified against Packagist and official docs)

---

## Recommended Stack

### Core Technologies

| Technology | Version Constraint | Purpose | Why Recommended |
|---|---|---|---|
| PHP | `^8.2` | Runtime | DBAL 4.x requires PHP ^8.2. PHP 8.2 is the practical floor: attributes (used for `#[TenantAware]`) matured in 8.1 but fibers, readonly properties, and enum support are solid at 8.2. Symfony 7.4 LTS requires 8.2. |
| Symfony | `^6.4 \|\| ^7.4` | Framework kernel | 6.4 is the current LTS (supported until Nov 2027); 7.4 is the new LTS (supported until Nov 2029). Supporting both gives the widest production install base. Symfony 8.0 requires PHP 8.4 and is a feature release — do NOT target it as a minimum yet. |
| doctrine/dbal | `^4.4` | DB abstraction layer | DBAL 4.4.2 is stable (Feb 2026). Requires PHP ^8.2. The wrapperClass mechanism (extend `Doctrine\DBAL\Connection`) is the only supported runtime connection override in DBAL 4 — `connect()`/`close()` are removed. Pin to ^4 because DoctrineBundle 3.x dropped DBAL 3 support. |
| doctrine/orm | `^3.3` | ORM / SQL filters | ORM 3.6.2 (Jan 2026) supports `^3.8.2 \|\| ^4` DBAL. SQL filters (`$em->getFilters()->enable('tenant_filter')`) are still the canonical shared-DB isolation mechanism. |

### Supporting Libraries

| Library | Version | Purpose | When to Use |
|---|---|---|---|
| `doctrine/doctrine-bundle` | `^2.13` | DoctrineBundle integration | **Important:** DoctrineBundle 3.x requires PHP ^8.4, which is too aggressive for this bundle's PHP ^8.2 floor. Use 2.13.x until its PHP floor aligns with ours, or make DoctrineBundle a soft/optional dependency with a broad constraint. Verify compatibility per CI matrix. |
| `doctrine/doctrine-migrations-bundle` | `^4.0` | Per-tenant migrations | Required for `tenancy:migrate` command. v4.0.0 (Dec 2025) also requires PHP ^8.4 — treat as optional in `require-dev` / `suggest`, not a hard `require`. |
| `league/flysystem-bundle` | `^3.3` | Filesystem abstraction | v3.6.2 requires PHP >=8.2 and Symfony ^6 \|\| ^7 \|\| ^8. Use `FilesystemOperator` + `#[Target]` for per-tenant filesystem routing in the Flysystem bootstrapper. |
| `league/flysystem` | `^3.28` | Core filesystem operations | Pulled in by flysystem-bundle. The filesystem bootstrapper decorates the operator to inject tenant path prefixes. |
| `symfony/messenger` | `^6.4 \|\| ^7.4` | Message bus + middleware | Already available via the Symfony constraint. `StampInterface` and `MiddlewareInterface` have not changed structurally across 6.4–7.4. The `TenantStamp` and two middleware (send-side, receive-side) only depend on these stable interfaces. |
| `symfony/event-dispatcher` | `^6.4 \|\| ^7.4` | Tenant lifecycle events | Dispatching `TenantResolved`, `TenantBootstrapped`, `TenantContextCleared` events. Also pulled in by the Symfony constraint. |
| `symfony/http-kernel` | `^6.4 \|\| ^7.4` | Kernel event listener for tenant resolution | `kernel.request` is where `HostResolver` and friends run. `RequestStack` is the injection point for request-scoped tenant context. |
| `symfony/cache` | `^6.4 \|\| ^7.4` | Cache pool prefix bootstrapper | The cache bootstrapper prefixes the `cache.app` pool key with `{tenant_id}:`. Uses `AdapterInterface` or the tagged-cache contract. |
| `symfony/dependency-injection` | `^6.4 \|\| ^7.4` | Compiler passes for bootstrapper registration | Compiler passes are how custom bootstrappers register themselves without modifying bundle internals. |
| `symfony/web-profiler-bundle` | `^6.4 \|\| ^7.4` (dev) | Profiler / WDT integration | The Tenancy WDT panel needs `DataCollectorInterface`. Dev-only dependency. |

### Development & Testing Tools

| Tool | Version | Purpose | Notes |
|---|---|---|---|
| `phpunit/phpunit` | `^11.0` | Unit and integration tests | PHPUnit 11 requires PHP >=8.2, aligns perfectly with the bundle floor. PHPUnit 12 requires PHP >=8.3, so 11 keeps PHP 8.2 coverage alive. PHPUnit 13 requires PHP >=8.4. Use `^11.0` in `require-dev`. |
| `symfony/phpunit-bridge` | `^6.4 \|\| ^7.4` | Symfony deprecation detection in tests | Bridges PHPUnit with Symfony's deprecation contracts. Set `SYMFONY_DEPRECATIONS_HELPER=max[direct]=0` in CI. Must use bridge v7.2+ when on PHPUnit 11 (provides ClockMock/DnsMock compatibility). |
| `SymfonyTest/symfony-bundle-test` | `^1.0` | `TestKernel` for multi-Symfony-version CI | Provides a lightweight kernel that loads your bundle under test, compatible with Symfony 5.4–7.x. Standard tool for bundle compatibility testing. |
| `phpstan/phpstan` | `^2.1` | Static analysis | PHPStan 2.1.42 (Mar 2026) is the current stable. Run at level 9 (`max`). This bundle also ships a PHPStan extension — PHPStan itself is thus both a dev tool and the runtime target for the extension. |
| `phpstan/phpstan-symfony` | `^2.0` | Symfony-specific PHPStan rules | 2.0.15 (Feb 2026) requires `phpstan/phpstan: ^2.1.13`. Provides container-aware type inference. Required for meaningful analysis of the DI layer. |
| `phpstan/phpstan-doctrine` | `^2.0` | Doctrine-specific PHPStan rules | 2.0.20 (Mar 2026) requires `phpstan/phpstan: ^2.1.34`. Required for `#[TenantAware]` entity analysis and SQL filter type checking. |
| `phpstan/extension-installer` | `^1.4` | Auto-registers PHPStan extensions | 1.4.3 (Sep 2024). Composer plugin that wires extension `.neon` files automatically on `composer install`. |
| `friendsofphp/php-cs-fixer` | `^3.0` | Code style — Symfony standards | v3.94.2 (Feb 2026). Use the `@Symfony` rule set. Config file: `.php-cs-fixer.dist.php` at repo root. Commit `.php-cs-fixer.cache` to `.gitignore`. |
| `phpstan/phpstan-strict-rules` | `^2.0` | Additional strict rules | Optional but recommended for a public bundle claiming PHPStan max. Adds strict comparison checks and disallows unsafe patterns. |

---

## Installation

```bash
# Runtime requirements (in bundle's require)
composer require \
  "php:^8.2" \
  "doctrine/dbal:^4.4" \
  "doctrine/orm:^3.3" \
  "league/flysystem:^3.28" \
  "symfony/cache:^6.4|^7.4" \
  "symfony/dependency-injection:^6.4|^7.4" \
  "symfony/event-dispatcher:^6.4|^7.4" \
  "symfony/http-kernel:^6.4|^7.4" \
  "symfony/messenger:^6.4|^7.4"

# Optional runtime (suggest in composer.json)
# league/flysystem-bundle, doctrine/doctrine-bundle, doctrine/doctrine-migrations-bundle

# Dev dependencies
composer require --dev \
  "phpunit/phpunit:^11.0" \
  "symfony/phpunit-bridge:^6.4|^7.4" \
  "symfony/framework-bundle:^6.4|^7.4" \
  "symfony/web-profiler-bundle:^6.4|^7.4" \
  "doctrine/doctrine-bundle:^2.13" \
  "league/flysystem-bundle:^3.3" \
  "phpstan/phpstan:^2.1" \
  "phpstan/phpstan-symfony:^2.0" \
  "phpstan/phpstan-doctrine:^2.0" \
  "phpstan/phpstan-strict-rules:^2.0" \
  "phpstan/extension-installer:^1.4" \
  "friendsofphp/php-cs-fixer:^3.0"
```

---

## Alternatives Considered

| Recommended | Alternative | When to Use Alternative |
|---|---|---|
| `phpunit/phpunit:^11` | `phpunit/phpunit:^12` | Only when the bundle drops PHP 8.2 support and requires PHP ^8.3 |
| `doctrine/dbal:^4.4` | `doctrine/dbal:^3.8` | Only if targeting Symfony projects still on DoctrineBundle 2.x with DBAL 3 — adds a compatibility burden and DBAL 3 EOL is approaching |
| GitHub Actions with shivammathur/setup-php | Other CI (CircleCI, GitLab) | No good reason to deviate; `setup-php` has native PHP matrix, Xdebug, and coverage support |
| `@Symfony` ruleset (php-cs-fixer) | `@PhpCsFixer` ruleset | `@PhpCsFixer` is more opinionated than Symfony's own OSS standard; fine for greenfield but diverges from `symfony/symfony` itself |
| Symfony 6.4 + 7.4 dual support | Symfony 7.4+ only | Only if development timeline allows waiting until 6.4 LTS users have migrated (Nov 2027) |

---

## What NOT to Use

| Avoid | Why | Use Instead |
|---|---|---|
| `doctrine/doctrine-bundle:^3.x` as a hard `require` | DoctrineBundle 3.0 bumped PHP minimum to ^8.4 in Dec 2025 — this would silently exclude all PHP 8.2/8.3 users | Declare as `require-dev` + `suggest`; allow users to install independently |
| `doctrine/doctrine-migrations-bundle:^4.0` as a hard `require` | Same as above — v4.0.0 requires PHP ^8.4 | Move to `suggest` with a note; provide the `tenancy:migrate` command as opt-in |
| DBAL `Connection::connect()` / `Connection::close()` | Both methods were removed in DBAL 4.x. Any bundle relying on them for runtime connection switching will fail entirely on DBAL 4 | Use the `wrapperClass` pattern: extend `Doctrine\DBAL\Connection` and override `getParams()` / driver-level reconnect |
| `EasyCorp/easy-admin-bundle` as a dev dependency in bundle tests | Pulls in too many transitive dependencies; bloats CI time unnecessarily | Only test against framework-bundle + doctrine-bundle minimal app |
| `phpunit/phpunit:^13` | Requires PHP >=8.4, excludes PHP 8.2 and 8.3 from the test matrix | `^11.0` supports PHP 8.2+ |
| Annotations (`@ORM\Entity`) on entities | Deprecated in Doctrine ORM 3.x; removed in future versions | PHP 8.x attributes (`#[ORM\Entity]`, `#[TenantAware]`) |
| `symfony/swiftmailer-bundle` | Abandoned (2021); incompatible with Symfony 6+ | `symfony/mailer` — not in scope for v1 anyway |
| Storing tenant context in a static property or singleton | Creates cross-request leaks in async/Messenger/CLI environments | Inject a request-scoped `TenantContext` service; clear it via `TenantContextCleared` event |

---

## Stack Patterns by Variant

**If database-per-tenant driver:**
- Use DBAL `wrapperClass` to create a `TenantConnection` subclass that overrides the underlying driver connection params on boot
- The `TenantBootstrapper` calls `DriverManager::getConnection()` with tenant params and passes the connection to a `TenantConnectionRegistry` service
- The ORM's `EntityManager` must be reset/cleared after the connection swap to prevent identity map contamination

**If shared-database driver:**
- Use `Doctrine\ORM\Query\Filter\SQLFilter` — specifically `$em->getFilters()->enable('tenant_filter')` with the filter receiving `tenant_id` as a parameter
- The `TenantBootstrapper` enables the filter and sets `$filter->setParameter('tenant_id', $tenant->getId(), ParameterType::STRING)`
- Strict mode: the filter's `addFilterConstraint()` throws `TenantMissingException` if `tenant_id` parameter is not set

**If Messenger worker (async processing):**
- Sending middleware adds `TenantStamp` to every outgoing envelope — runs on `SentStamp` absent (i.e., only on initial dispatch, not transport receive)
- Worker middleware reads `TenantStamp`, bootstraps the tenant context before the message handler runs, then clears it after
- Use `$stack->next()->handle()` wrapping to guarantee cleanup even on handler exceptions

**If PHPStan extension shipping:**
- Register via `phpstan.neon` extension file and expose it via `extra.phpstan` in `composer.json` for auto-installation via `phpstan/extension-installer`
- Custom rules implement `PHPStan\Rules\Rule` with `getNodeType()` returning the AST node (e.g., `Node\Expr\MethodCall`) and `processNode()` running the checks
- Rules to write: (1) `#[TenantAware]` on entities without `tenant_id` field → error; (2) direct `EntityManager::getFilters()->disable()` without guard → warning; (3) tenant context access outside request/worker scope → notice

---

## Version Compatibility Matrix

| Package | Constraint | PHP Floor | Symfony Floor | Notes |
|---|---|---|---|---|
| doctrine/dbal | ^4.4 | 8.2 | n/a | DBAL 4 is standalone |
| doctrine/orm | ^3.3 | 8.1 | n/a | Accepts dbal ^3.8.2 \|\| ^4 |
| doctrine/doctrine-bundle | ^2.13 (dev) | 8.1 (2.x) | 6.4 | 3.x requires PHP ^8.4 — too aggressive |
| league/flysystem-bundle | ^3.3 | 8.2 | 6.0 | Safe |
| symfony/* | ^6.4\|\|^7.4 | 8.1 (6.4) / 8.2 (7.4) | — | Use lowest common = 8.2 |
| phpunit/phpunit | ^11.0 | 8.2 | — | Bridge back to PHP 8.2 |
| phpstan/phpstan | ^2.1 | 7.4 | — | Run on PHP 8.4 in CI |
| friendsofphp/php-cs-fixer | ^3.0 | 7.4 | — | Run on PHP 8.3+ in CI |

---

## CI Configuration (GitHub Actions)

Recommended matrix:

```yaml
strategy:
  matrix:
    php: ["8.2", "8.3", "8.4"]
    symfony: ["6.4.*", "7.4.*"]
    include:
      - php: "8.4"
        symfony: "7.4.*"
        composer-flags: "--prefer-lowest"
    exclude:
      - php: "8.2"
        symfony: "7.4.*"  # optional: reduce matrix size if needed
```

Separate job for static analysis (no matrix needed):

```yaml
phpstan:
  runs-on: ubuntu-latest
  steps:
    - uses: shivammathur/setup-php@v2
      with:
        php-version: "8.4"
    - run: composer install
    - run: vendor/bin/phpstan analyse --level=9 src/
```

Code style job:

```yaml
cs:
  runs-on: ubuntu-latest
  steps:
    - uses: shivammathur/setup-php@v2
      with:
        php-version: "8.3"
    - run: composer install
    - run: vendor/bin/php-cs-fixer fix --dry-run --diff
```

---

## Symfony Flex Recipe Conventions

For submission to `symfony/recipes-contrib`:

1. `composer.json` must declare `"type": "symfony-bundle"` and an alias under `extra.symfony.alias`
2. Recipe lives at `vendor-name/bundle-name/X.Y/` in the contrib repo
3. `manifest.json` should register the bundle in `bundles.php`, copy a minimal `config/packages/tenant.yaml`, and optionally add an `.env` stub for `TENANT_DRIVER=shared`
4. The contrib repo requires `"allow-contrib": true` in the user's `composer.json` to auto-apply

Minimal recipe `manifest.json`:

```json
{
  "bundles": {
    "YourVendor\\TenancyBundle\\TenancyBundle": ["all"]
  },
  "copy-from-recipe": {
    "config/": "%CONFIG_DIR%/"
  }
}
```

---

## Key DBAL 4 Architectural Constraint (Critical for Implementation)

**Problem:** DBAL 4 removed `Connection::connect()` and `Connection::close()`. Runtime connection switching via close-and-reconnect is no longer possible.

**Solution:** Use the `wrapperClass` configuration key to register a `TenantConnection` class that extends `Doctrine\DBAL\Connection`. This class exposes a `switchTenant(TenantConnectionParams $params): void` method that modifies internal driver-level params and forces a fresh native connection on next query. The `EntityManager` must be reset (`$em->clear()`) after the switch to flush the identity map.

**Reference pattern from community bundles:** `mapeveri/multi-tenancy-bundle` and `fds/multi-tenancy-bundle` both use the `wrapper_class` approach in their Doctrine YAML config — confirmed as the standard pattern for DBAL 4 runtime connection switching.

---

## Sources

- [Symfony Releases — symfony.com/releases](https://symfony.com/releases) — confirmed 6.4 LTS (PHP 8.1+), 7.4 LTS (PHP 8.2+), 8.0 (PHP 8.4+) — HIGH confidence
- [doctrine/dbal on Packagist](https://packagist.org/packages/doctrine/dbal) — version 4.4.2, PHP ^8.2 — HIGH confidence
- [doctrine/orm on Packagist](https://packagist.org/packages/doctrine/orm) — version 3.6.2, supports `dbal:^3.8.2||^4` — HIGH confidence
- [doctrine/doctrine-bundle on Packagist](https://packagist.org/packages/doctrine/doctrine-bundle) — 3.2.2 requires PHP ^8.4; 2.18.2 is last 2.x stable — HIGH confidence
- [league/flysystem-bundle on Packagist](https://packagist.org/packages/league/flysystem-bundle) — 3.6.2, PHP >=8.2 — HIGH confidence
- [phpunit/phpunit supported versions](https://phpunit.de/supported-versions.html) — PHPUnit 11: PHP >=8.2; PHPUnit 12: PHP >=8.3 — HIGH confidence
- [phpstan/phpstan on Packagist](https://packagist.org/packages/phpstan/phpstan) — 2.1.42 (Mar 2026) — HIGH confidence
- [phpstan/phpstan-symfony on Packagist](https://packagist.org/packages/phpstan/phpstan-symfony) — 2.0.15, requires phpstan ^2.1.13 — HIGH confidence
- [phpstan/phpstan-doctrine on Packagist](https://packagist.org/packages/phpstan/phpstan-doctrine) — 2.0.20, requires phpstan ^2.1.34 — HIGH confidence
- [friendsofphp/php-cs-fixer on Packagist](https://packagist.org/packages/friendsofphp/php-cs-fixer) — v3.94.2 — HIGH confidence
- [phpstan/extension-installer on Packagist](https://packagist.org/packages/phpstan/extension-installer) — 1.4.3 — HIGH confidence
- [Symfony Best Practices for Reusable Bundles](https://symfony.com/doc/current/bundles/best_practices.html) — service naming, no autowiring in bundles, PSR-4 layout — HIGH confidence
- [DBAL 4.x Configuration docs](https://www.doctrine-project.org/projects/doctrine-dbal/en/4.4/reference/configuration.html) — wrapperClass mechanism — HIGH confidence
- [SymfonyTest/symfony-bundle-test on GitHub](https://github.com/SymfonyTest/symfony-bundle-test) — TestKernel for multi-Symfony CI — MEDIUM confidence (actively maintained, 113 stars, last release Aug 2025)
- [mapeveri/multi-tenancy-bundle](https://github.com/mapeveri/multi-tenancy-bundle), [fds/multi-tenancy-bundle](https://packagist.org/packages/fds/multi-tenancy-bundle) — wrapperClass pattern for runtime tenant connection switching — MEDIUM confidence (community implementations, not official doctrine guidance)

---

*Stack research for: Symfony multi-tenancy bundle (production OSS)*
*Researched: 2026-03-17*
