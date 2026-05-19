# Phase 6: Messenger Integration - Research

**Researched:** 2026-03-19
**Domain:** Symfony Messenger middleware, stamps, DI wiring
**Confidence:** HIGH

---

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions

**A — No-tenant dispatch behavior**
- Skip silently.
- Sending middleware checks `TenantContext::hasTenant()`. If `true`, attach `TenantStamp($tenant->getSlug())`. If `false`, `return $handler($envelope)` unchanged.
- Worker corollary: If an envelope arrives with no `TenantStamp`, the worker middleware passes it through without any tenant bootstrapping.

**B — Worker missing-tenant handling**
- Throw and let Messenger's retry policy handle it.
- Worker middleware calls `TenantProviderInterface::findBySlug($slug)`. If `TenantNotFoundException` or `TenantInactiveException` is thrown, let it propagate.
- No silent discard. Message surfaces in dead-letter queue after max retries.

**C — Stamp payload**
- Slug only.
- `TenantStamp` carries a single `string $tenantSlug` field. Worker calls `TenantProviderInterface::findBySlug($tenantSlug)` to reload the full tenant object.

**D — Bus scope**
- Auto-enroll on all buses, zero config.
- Both middlewares registered via DI so they apply to all configured buses automatically.
- No `tenancy.messenger.buses` option, no `tenancy.messenger.enabled` flag.

**Teardown guarantee**
- `try/finally` is mandatory in the worker middleware.
- Teardown sequence: `BootstrapperChain::clear()` → `TenantContext::clear()` → dispatch `TenantContextCleared`.

**TenantStamp serialization**
- Claude's discretion on exact serialization approach. Must survive round-trip through `PhpSerializer` and `JsonSerializer`. Plain `readonly` promoted property is sufficient.

**Event dispatching on worker side**
- Claude's discretion on whether `TenantResolved` is dispatched on the worker side.

### Claude's Discretion
- TenantStamp serialization: exact approach (promoted readonly property with getter is sufficient for both serializers)
- Whether `TenantResolved` is dispatched on the worker side (see Open Questions)

### Deferred Ideas (OUT OF SCOPE)
- `tenancy.messenger.enabled` config flag
- Per-bus middleware opt-in (`tenancy.messenger.buses`)
- `TenantResolved` dispatch on worker side as a user-facing config option
- Stamp encryption
- Worker concurrency / tenant isolation locking
</user_constraints>

---

<phase_requirements>
## Phase Requirements

| ID | Description | Research Support |
|----|-------------|-----------------|
| MSG-01 | `TenantStamp` is a custom Symfony Messenger stamp that carries the active tenant identifier across process boundaries | `StampInterface` is a marker interface; plain class with `public readonly string $tenantSlug` satisfies both PhpSerializer (native PHP serialize) and JsonSerializer (Symfony serializer + ObjectNormalizer with getter method) |
| MSG-02 | Sending middleware automatically attaches `TenantStamp` to every dispatched envelope when a tenant context is active | `MiddlewareInterface::handle(Envelope, StackInterface): Envelope` — sending middleware calls `TenantContext::hasTenant()`, conditionally attaches stamp via `$envelope->with(new TenantStamp(...))` |
| MSG-03 | Worker-side middleware re-boots the tenant context from `TenantStamp` before the handler runs and clears it in a `try/finally` block | Worker reads stamp via `$envelope->last(TenantStamp::class)`, calls `TenantProviderInterface::findBySlug()`, boots chain, wraps `$stack->next()->handle()` in `try/finally`, clears in finally block |
</phase_requirements>

---

## Summary

Symfony Messenger middleware is implemented by providing a class that implements `MiddlewareInterface` (single method: `handle(Envelope $envelope, StackInterface $stack): Envelope`). Stamps are plain PHP objects implementing `StampInterface` (a marker interface with no required methods). The `Envelope` is immutable — stamps are added with `$envelope->with(new MyStamp(...))` which returns a new envelope; stamps are read with `$envelope->last(MyStamp::class)`.

The critical DI wiring challenge is Decision D: "auto-enroll on all buses, zero config." The `messenger.middleware` service tag alone does NOT automatically apply a service to all buses — Symfony Messenger's `MessengerPass` processes middleware per-bus via container parameters named `$busId.middleware`. The correct zero-config approach for a bundle is to use `prependExtensionConfig('framework', [...])` in `TenancyBundle::prependExtension()` to inject the middleware IDs into each already-configured bus's middleware list. This mirrors how `TenancyBundle::prependExtension()` already uses this pattern for Doctrine entity mappings.

`symfony/messenger` is not in `composer.json` at all (not even in `suggest`). It must be added as a `suggest` entry since it is optional for users who don't use Messenger, with a `class_exists` guard in `prependExtension()` to avoid crashing when messenger is not installed.

**Primary recommendation:** Implement `TenantStamp` + `TenantSendingMiddleware` + `TenantWorkerMiddleware` in `src/Messenger/`, wire in `config/services.php`, and enroll into all buses via `prependExtension()` with a `class_exists(MessageBusInterface::class)` guard.

---

## Standard Stack

### Core
| Library | Version | Purpose | Why Standard |
|---------|---------|---------|--------------|
| symfony/messenger | ^6.4\|\|^7.0 | Stamp and middleware contracts | Matches project's existing Symfony constraint; the bundle supports 6.4 and 7.x |

### Supporting
| Library | Version | Purpose | When to Use |
|---------|---------|---------|-------------|
| symfony/framework-bundle | ^6.4\|\|^7.0 | FrameworkExtension processes messenger configuration | Already a dev dep; needed for `prependExtension()` to access `framework.messenger.buses` config |

### Alternatives Considered
| Instead of | Could Use | Tradeoff |
|------------|-----------|----------|
| `prependExtensionConfig` in `prependExtension()` | A standalone compiler pass iterating `messenger.bus`-tagged services | `prependExtension` is simpler and consistent with how this bundle already injects Doctrine mappings; compiler pass would work too but adds more code |
| `public readonly` promoted property + getter | `__serialize`/`__unserialize` | The promoted property + getter approach works with both PhpSerializer and JsonSerializer; custom serialization only needed for complex payloads |

**Installation:**
```bash
composer require symfony/messenger "^6.4||^7.0"
```

Add to `composer.json` `suggest` block:
```json
"suggest": {
    "symfony/messenger": "Required for tenant context preservation across async message processing (^6.4|^7.0)"
}
```

**Version verification:** symfony/messenger latest stable is v8.0.7 (PHP ^8.4 only). For this project's PHP ^8.2 + Symfony ^6.4||^7.0 constraint, use `^6.4||^7.0`.

---

## Architecture Patterns

### Recommended Project Structure
```
src/
└── Messenger/
    ├── TenantStamp.php              # StampInterface implementation
    ├── TenantSendingMiddleware.php  # Attach stamp on dispatch
    └── TenantWorkerMiddleware.php   # Restore context on consume
tests/
├── Unit/Messenger/
│   ├── TenantStampTest.php
│   ├── TenantSendingMiddlewareTest.php
│   └── TenantWorkerMiddlewareTest.php
└── Integration/
    └── MessengerMiddlewareIntegrationTest.php
```

### Pattern 1: StampInterface — marker interface, no required methods

`StampInterface` is a pure marker interface. There are no methods to implement. The stamp is a plain value object.

```php
// Source: https://symfony.com/doc/current/components/messenger.html
use Symfony\Component\Messenger\Stamp\StampInterface;

final class TenantStamp implements StampInterface
{
    public function __construct(public readonly string $tenantSlug) {}

    public function getTenantSlug(): string
    {
        return $this->tenantSlug;
    }
}
```

**Serialization note:**
- `PhpSerializer` (default for most transports): serializes the entire `Envelope` via PHP's native `serialize()`. A `readonly` promoted property round-trips correctly.
- `JsonSerializer` (Symfony Serializer + ObjectNormalizer): uses `getTenantSlug()` getter during normalization. The getter is required for JSON serialization. `readonly` promoted properties are supported by the ObjectNormalizer for normalization but denormalization requires the constructor parameter name to match the JSON key exactly (it will: `tenantSlug`). The getter ensures compatibility.
- `NonSendableStampInterface` must NOT be implemented — stamps implementing that interface are stripped by `PhpSerializer` before encoding.

### Pattern 2: MiddlewareInterface contract

```php
// Source: https://symfony.com/doc/current/messenger.html
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;

final class TenantSendingMiddleware implements MiddlewareInterface
{
    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        // attach stamp if tenant is active, then delegate
        return $stack->next()->handle($envelope, $stack);
    }
}
```

**Key contract rule:** The middleware MUST call `$stack->next()->handle($envelope, $stack)` and return its result. Failing to call next breaks the chain.

### Pattern 3: Worker middleware with try/finally

The worker middleware runs on the consumer side only. It reads the stamp from the incoming envelope before the handler runs. The `try/finally` guarantees teardown even when the handler throws.

```php
final class TenantWorkerMiddleware implements MiddlewareInterface
{
    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $stamp = $envelope->last(TenantStamp::class);

        if ($stamp === null) {
            // No stamp — non-tenant message, pass through unchanged
            return $stack->next()->handle($envelope, $stack);
        }

        $tenant = $this->tenantProvider->findBySlug($stamp->getTenantSlug());
        // TenantNotFoundException / TenantInactiveException bubble up to Messenger retry policy

        $this->tenantContext->setTenant($tenant);
        $this->bootstrapperChain->boot($tenant);

        try {
            return $stack->next()->handle($envelope, $stack);
        } finally {
            $this->bootstrapperChain->clear();
            $this->tenantContext->clear();
            $this->eventDispatcher->dispatch(new TenantContextCleared());
        }
    }
}
```

### Pattern 4: DI registration — services.php + prependExtension

**In `config/services.php`** (always-on, consistent with existing services):
```php
use Tenancy\Bundle\Messenger\TenantSendingMiddleware;
use Tenancy\Bundle\Messenger\TenantWorkerMiddleware;

$services->set('tenancy.messenger.sending_middleware', TenantSendingMiddleware::class)
    ->args([service('tenancy.context')]);

$services->set('tenancy.messenger.worker_middleware', TenantWorkerMiddleware::class)
    ->args([
        service('tenancy.context'),
        service('tenancy.bootstrapper_chain'),
        service('tenancy.provider'),
        service('event_dispatcher'),
    ]);
```

**In `TenancyBundle::prependExtension()`** (auto-enroll in all buses, zero user config):
```php
public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
{
    // ... existing Doctrine prepend logic ...

    // Guard: only wire Messenger integration when symfony/messenger is installed
    if (!class_exists(\Symfony\Component\Messenger\MessageBusInterface::class)) {
        return;
    }

    // Inject both middlewares into every configured bus
    $messengerConfigs = $builder->getExtensionConfig('framework');
    $buses = [];
    foreach ($messengerConfigs as $config) {
        foreach ($config['messenger']['buses'] ?? [] as $busName => $busConfig) {
            $buses[$busName] = true;
        }
    }

    // Also cover the default bus if no explicit buses section
    // FrameworkBundle creates messenger.bus.default when no buses configured
    if (empty($buses)) {
        $buses['messenger.bus.default'] = true;
    }

    $middlewareToInject = [
        ['id' => 'tenancy.messenger.sending_middleware'],
        ['id' => 'tenancy.messenger.worker_middleware'],
    ];

    foreach (array_keys($buses) as $busName) {
        $builder->prependExtensionConfig('framework', [
            'messenger' => [
                'buses' => [
                    $busName => [
                        'middleware' => $middlewareToInject,
                    ],
                ],
            ],
        ]);
    }
}
```

**Important:** `prependExtensionConfig` prepends — meaning the user's explicit bus config is merged after. This is the correct behavior: bundle adds middleware first, user config can extend.

**Alternative simpler approach:** Only wire the single default bus (`messenger.bus.default`) unconditionally, since that covers ~95% of Symfony apps. Multi-bus discovery via `getExtensionConfig('framework')` is a V1.1 enhancement.

### Anti-Patterns to Avoid

- **Calling `$stack->next()->handle()` inside the `finally` block:** The handler runs in `try`, teardown runs in `finally`. Never put the `stack->next()` call inside `finally`.
- **Setting tenant context AFTER calling stack->next():** Context must be established before the handler runs (worker side). On sending side, stamp must be attached before `stack->next()`.
- **Catching and swallowing `TenantNotFoundException` in worker middleware:** Let it propagate — Messenger's retry/DLQ policy handles recovery. Swallowing loses the message.
- **Calling `$envelope->with()` without reassigning:** `Envelope` is immutable. `$envelope->with(new TenantStamp(...))` returns a new instance. The pattern is `$envelope = $envelope->with(...)`.
- **Using `messenger.middleware` service tag expecting auto-registration:** This tag does NOT auto-enroll middleware in all buses. Only `prependExtensionConfig` achieves zero-config bus enrollment.

---

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| Stamp envelope attachment | Custom envelope wrapper | `Envelope::with(new TenantStamp(...))` | Envelope is the contract; custom wrappers break Messenger internals |
| Stamp reading | Manual header inspection | `Envelope::last(TenantStamp::class)` | Type-safe, handles multiple stamps of same class correctly |
| Exception-safe cleanup | Manual try/catch in every handler | `try/finally` in worker middleware | Single point of teardown; handlers never need to know about tenant lifecycle |
| Multi-bus enrollment | Per-bus YAML config in bundle docs | `prependExtension()` with `getExtensionConfig` | Zero-config DX promise; user should not configure bus names |

**Key insight:** Messenger's stamp system is specifically designed for this use case (cross-process context propagation). The patterns are battle-tested by Symfony's own stamps (`DelayStamp`, `BusNameStamp`, `HandledStamp`).

---

## Common Pitfalls

### Pitfall 1: symfony/messenger not installed — service container explosion
**What goes wrong:** Bundle registers messenger services (type-hinting `MiddlewareInterface`) but `symfony/messenger` is not in the application's `composer.json`. Container compilation fails with "class not found."
**Why it happens:** The bundle's `config/services.php` is always imported, registering classes that don't exist.
**How to avoid:** Guard messenger service registration behind `class_exists(\Symfony\Component\Messenger\MiddlewareInterface::class)` in `loadExtension()`. Same guard in `prependExtension()`.
**Warning signs:** `Class "Symfony\Component\Messenger\Middleware\MiddlewareInterface" not found` during container compilation.

### Pitfall 2: Sending middleware attaching stamp on worker consume side
**What goes wrong:** `TenantSendingMiddleware` runs on BOTH the dispatch path and the consume path. On the consume side it would re-attach a stamp to already-stamped messages.
**Why it happens:** Middleware in the stack runs for every envelope, including consumed ones.
**How to avoid:** The sending middleware should be idempotent. Since `TenantContext::hasTenant()` returns `false` on a fresh worker process (before `TenantWorkerMiddleware` boots the context), attaching is safe. However, if both middlewares are in the same stack, order matters: worker middleware must run before sending middleware to avoid a stamp-on-stamp scenario. Place worker middleware first (lower index in prepend, which means it executes first on consume side after being unwrapped).
**Warning signs:** Double stamps on consumed envelopes visible in integration tests.

### Pitfall 3: JsonSerializer fails to deserialize TenantStamp — constructor argument mismatch
**What goes wrong:** `Serializer.php` uses `ObjectNormalizer` to denormalize stamps. If the JSON key doesn't match the constructor parameter name, denormalization fails with "Cannot create instance."
**Why it happens:** `ObjectNormalizer` maps JSON keys to constructor parameter names by default. If a camelCase key becomes snake_case or vice versa, the match fails.
**How to avoid:** Keep `tenantSlug` as the property name (camelCase, matches the JSON key output by the normalizer). Do not rename. Test with `JsonSerializer` in integration tests.
**Warning signs:** `RuntimeException: Cannot create an instance of "TenantStamp" from serialized data because its constructor requires parameter "tenantSlug" to be present.`

### Pitfall 4: Worker teardown skipped when handler throws
**What goes wrong:** Without `try/finally`, a handler exception leaves the `TenantContext` populated. The next message processed by the same worker inherits the previous tenant's context.
**Why it happens:** Normal exception propagation skips any code after the throwing line.
**How to avoid:** The `try/finally` pattern is non-negotiable per the phase spec. Test by asserting `TenantContext::hasTenant()` is false after a handler that throws.
**Warning signs:** Integration test showing `hasTenant() === true` after exception scenario.

### Pitfall 5: prependExtension does not discover dynamically created buses
**What goes wrong:** App creates buses programmatically (not in `config/packages/messenger.yaml`). `getExtensionConfig('framework')` returns only statically declared config, missing runtime buses.
**Why it happens:** `getExtensionConfig` reads pre-compilation config, not runtime.
**How to avoid:** For V1, document that auto-enrollment covers statically configured buses and `messenger.bus.default`. Dynamically created buses require manual config (deferred to V1.1 per CONTEXT.md).
**Warning signs:** Middleware not running in custom buses silently.

---

## Code Examples

Verified patterns from official sources:

### Adding a stamp to an envelope
```php
// Source: https://symfony.com/doc/current/components/messenger.html
$envelope = $envelope->with(new TenantStamp($tenant->getSlug()));
// Envelope is immutable; assign the return value
```

### Reading a stamp from an envelope
```php
// Source: https://symfony.com/doc/current/components/messenger.html
$stamp = $envelope->last(TenantStamp::class);
// Returns null if no such stamp; returns the last stamp of that class
```

### Passing through when no condition met
```php
// Source: https://symfony.com/doc/current/messenger.html (middleware section)
public function handle(Envelope $envelope, StackInterface $stack): Envelope
{
    return $stack->next()->handle($envelope, $stack);
}
```

### Teardown sequence (mirror of TenantContextOrchestrator::onKernelTerminate)
```php
// Source: src/EventListener/TenantContextOrchestrator.php (existing project code)
$this->bootstrapperChain->clear();
$this->tenantContext->clear();
$this->eventDispatcher->dispatch(new TenantContextCleared());
```

### PhpSerializer: how stamps survive round-trip
PhpSerializer serializes the entire Envelope via `serialize($envelope)`. All stamps that do NOT implement `NonSendableStampInterface` are included. A `readonly` promoted property is natively serializable by PHP.

### JsonSerializer: stamp round-trip requirement
Stamps must be Symfony-normalizable. `ObjectNormalizer` uses getter methods for normalization (serialize direction) and constructor parameter names for denormalization (deserialize direction). A stamp with `public readonly string $tenantSlug` and `getTenantSlug(): string` satisfies both.

---

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|--------------|--------|
| Manual tenant propagation in message payload | `StampInterface` for cross-cutting context | Symfony 4.2 | Stamps are the standard way to attach metadata without touching the message |
| Per-bus YAML middleware config in bundle docs | `prependExtensionConfig` in bundle | Symfony 4.x+ | Zero-config DX; bundle auto-enrolls into user's buses |
| Custom serialize/unserialize for stamps | Promoted readonly constructor property + getter | PHP 8.1+ | No boilerplate; works with both PhpSerializer and JsonSerializer |

**Deprecated/outdated:**
- Hand-rolling message metadata by adding fields to message classes: stamps are the correct abstraction since Symfony 4.2.

---

## Open Questions

1. **Should `TenantResolved` be dispatched on the worker side?**
   - What we know: `TenantContextOrchestrator::onKernelRequest()` dispatches `TenantResolved` after `setTenant()` + `boot()`. The `resolvedBy` field carries the resolver class name.
   - What's unclear: Are there application listeners that depend on `TenantResolved` being fired in worker processes? The worker middleware "restores" not "resolves" — semantically different.
   - Recommendation: Do NOT dispatch `TenantResolved` in V1. Dispatch only `TenantContextCleared` (required by teardown sequence per CONTEXT.md). If a `TenantRestoredForWorker` event or `TenantResolved` with `resolvedBy = TenantWorkerMiddleware::class` is needed, that's V1.1. This avoids listeners designed for HTTP resolution firing in worker context with unexpected arguments (`$request === null`).

2. **Sending middleware — does it run on consume side too?**
   - What we know: Middleware is in the bus stack and runs for every `dispatch()` call including internal worker dispatching.
   - What's unclear: Whether `TenantSendingMiddleware` should check for an existing `TenantStamp` and skip re-attaching to avoid double-stamp scenarios.
   - Recommendation: Add an idempotency guard: `if ($envelope->last(TenantStamp::class) !== null) { return $stack->next()->handle($envelope, $stack); }`. This prevents double-stamping in edge cases where the sending middleware runs on the worker-side dispatch path after `TenantWorkerMiddleware` has already loaded the context.

3. **Does `symfony/messenger` need to be in `require` or only `suggest`?**
   - What we know: It is not in `composer.json` at all. Decision D says "auto-enroll, zero config." For auto-enroll to work, the code must run at container build time. If messenger is absent, the guard prevents registration.
   - Recommendation: Add to `suggest` only (consistent with doctrine dependencies). The `class_exists` guard in `loadExtension()` and `prependExtension()` prevents crashes when messenger is absent.

---

## Validation Architecture

### Test Framework
| Property | Value |
|----------|-------|
| Framework | PHPUnit 11.x |
| Config file | `phpunit.xml` (project root) |
| Quick run command | `vendor/bin/phpunit --testsuite unit tests/Unit/Messenger/` |
| Full suite command | `vendor/bin/phpunit` |

### Phase Requirements → Test Map
| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|-------------------|-------------|
| MSG-01 | TenantStamp carries tenantSlug, implements StampInterface | unit | `vendor/bin/phpunit tests/Unit/Messenger/TenantStampTest.php -x` | Wave 0 |
| MSG-01 | TenantStamp survives PhpSerializer round-trip | unit | `vendor/bin/phpunit tests/Unit/Messenger/TenantStampTest.php -x` | Wave 0 |
| MSG-02 | Sending middleware attaches stamp when tenant active | unit | `vendor/bin/phpunit tests/Unit/Messenger/TenantSendingMiddlewareTest.php -x` | Wave 0 |
| MSG-02 | Sending middleware passes through when no tenant | unit | `vendor/bin/phpunit tests/Unit/Messenger/TenantSendingMiddlewareTest.php -x` | Wave 0 |
| MSG-03 | Worker middleware boots tenant context from stamp | unit | `vendor/bin/phpunit tests/Unit/Messenger/TenantWorkerMiddlewareTest.php -x` | Wave 0 |
| MSG-03 | Worker clears context in finally block even on exception | unit | `vendor/bin/phpunit tests/Unit/Messenger/TenantWorkerMiddlewareTest.php -x` | Wave 0 |
| MSG-03 | Worker passes through envelope with no stamp | unit | `vendor/bin/phpunit tests/Unit/Messenger/TenantWorkerMiddlewareTest.php -x` | Wave 0 |
| MSG-01+02+03 | Two sequential messages load correct tenant each time | integration | `vendor/bin/phpunit tests/Integration/MessengerMiddlewareIntegrationTest.php -x` | Wave 0 |
| MSG-01+02+03 | Middlewares wired into bus via DI compilation | integration | `vendor/bin/phpunit tests/Integration/MessengerMiddlewareIntegrationTest.php -x` | Wave 0 |

### Sampling Rate
- **Per task commit:** `vendor/bin/phpunit --testsuite unit tests/Unit/Messenger/`
- **Per wave merge:** `vendor/bin/phpunit`
- **Phase gate:** Full suite green before `/gsd:verify-work`

### Wave 0 Gaps
- [ ] `tests/Unit/Messenger/TenantStampTest.php` — covers MSG-01
- [ ] `tests/Unit/Messenger/TenantSendingMiddlewareTest.php` — covers MSG-02
- [ ] `tests/Unit/Messenger/TenantWorkerMiddlewareTest.php` — covers MSG-03
- [ ] `tests/Integration/MessengerMiddlewareIntegrationTest.php` — covers MSG-01+02+03 end-to-end
- [ ] `tests/Integration/Support/MessengerTestKernel.php` — test kernel with FrameworkBundle + TenancyBundle + Messenger configured

---

## Sources

### Primary (HIGH confidence)
- https://symfony.com/doc/current/components/messenger.html — StampInterface contract, Envelope API (`with()`, `last()`), stamp serialization requirements
- https://symfony.com/doc/current/messenger.html — MiddlewareInterface, middleware DI registration patterns
- https://github.com/symfony/messenger/blob/7.1/Transport/Serialization/PhpSerializer.php — confirmed PhpSerializer uses native `serialize($envelope)`, strips `NonSendableStampInterface`, readonly properties survive
- https://github.com/symfony/messenger/blob/7.3/Transport/Serialization/Serializer.php — confirmed JsonSerializer normalizes stamps per-class using Symfony Serializer; getter methods required
- `src/EventListener/TenantContextOrchestrator.php` — canonical teardown sequence to mirror in worker middleware
- `src/TenancyBundle.php` — prependExtension pattern already used for Doctrine; same approach for Messenger buses
- `config/services.php` — established DI service ID naming convention (`tenancy.*`)
- https://packagist.org/packages/symfony/messenger — confirmed latest stable v8.0.7 (PHP ^8.4); for this project use ^6.4||^7.0

### Secondary (MEDIUM confidence)
- https://symfony.com/doc/current/bundles/prepend_extension.html — prependExtensionConfig approach to inject into all buses
- https://github.com/symfony/messenger/blob/7.2/DependencyInjection/MessengerPass.php — confirmed messenger.middleware tag alone does NOT auto-enroll in all buses; middleware is per-bus via container parameters
- https://symfonycasts.com/screencast/messenger/middleware-stamps — middleware + stamp patterns (cross-referenced with official docs)

### Tertiary (LOW confidence)
- WebSearch findings on JsonSerializer + readonly constructor properties — there are known edge cases in Symfony 8.x but NOT in 6.4/7.x; the getter method pattern mitigates this

---

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH — symfony/messenger is a stable, long-lived component; version constraint matches existing project constraint
- Architecture: HIGH — MiddlewareInterface contract verified via official docs and source; prependExtension pattern verified from project's existing code
- DI bus enrollment: MEDIUM — mechanism (prependExtensionConfig) is confirmed as working approach; exact iteration of dynamic buses needs verification during implementation
- Pitfalls: HIGH — serialization pitfalls verified against source code; teardown requirement is non-negotiable per CONTEXT.md
- Stamp serialization: MEDIUM-HIGH — PhpSerializer confirmed HIGH; JsonSerializer readonly+getter pattern confirmed MEDIUM (working in 6.4/7.x, regression exists only in 8.x)

**Research date:** 2026-03-19
**Valid until:** 2026-06-19 (stable API — Symfony 7.x LTS; 90 days)
