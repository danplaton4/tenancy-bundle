# Phase 20: Mailer Bootstrapper — Research

**Researched:** 2026-05-19
**Domain:** Symfony Mailer multi-transport + Symfony Messenger async integration; PHP SMTP transport connection lifecycle; LRU cache; DSN sanitization; DI compiler pass patterns
**Confidence:** HIGH (all critical claims verified against Symfony source code at 7.3 branch)

---

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions
- **DEC-MAIL-01:** X-Transport header strategy + MessageEvent listener for From/Reply-To headers — the chosen extension mechanism.
- **DEC-MAIL-02:** mailerDsn nullable column on Tenant (full BOOT-04 scope shipped in v0.3).
- **DEC-MAIL-03:** getMailerDsn() added to TenantInterface — BC break, mitigated by TenantMailerConfigTrait and documented in UPGRADE.md 0.2→0.3. BC-break surface grows from 1 to 3 interface methods (getMailerDsn, getMailerFrom, getMailerReplyTo). Same trait covers all three.
- **D-01:** Single mechanism: TransportFactoryDecorator intercepts tenant_<slug> lookups, calls TenantProviderInterface::findBySlug($slug), builds transport from tenant's mailerDsn, caches in LRU. No TenantTransportProviderInterface. No new interface. Existing TenantProviderInterface::findBySlug() is the contract.
- **D-02:** From and Reply-To from dedicated entity columns (mailerFrom, mailerReplyTo), not from DSN query string. TenantInterface gains getMailerFrom(): ?string and getMailerReplyTo(): ?string.
- **D-03:** Per-tenant transport cache is a configurable LRU, default size 32. Config key: tenancy.mailer.transport_cache_size. Full clear on TenantContextCleared event.
- **D-04:** MailerTransportContractPass defaults to auto-detection of async Mailer routing. Config key: tenancy.mailer.async: auto|true|false (default auto). Detection: inspect framework.messenger.routing for SendEmailMessage entries.
- **D-05:** MailerBootstrapper, TenantMessageDecorator, TransportFactoryDecorator, SanitizingMailerDecorator all registered unconditionally in DI guarded by interface_exists checks. No tenancy.mailer.enabled flag.
- **D-06:** SanitizingMailerDecorator wraps MailerInterface. On TransportException: redacts password with regex (://[^:]+:)[^@]+(@) → $1***$2. Wraps in TenantSanitizedTransportException (extends TransportException).
- **D-07:** MailerBootstrapper runs AFTER DatabaseSwitchBootstrapper and DoctrineBootstrapper. clear() runs in reverse — Mailer cleanup before EM reset.
- **D-08:** TenantDataCollector (Phase 19) gains collectMailerState() method and a collapsible Mailer section in tenancy.html.twig (dev only). Shows: mailerFrom, mailerReplyTo, redacted mailerDsn, transport cache size/max/hits/evictions, resolved strategy, async-detected flag. State badge: OK/MISSING/ERROR.
- **D-09:** tenancy:install gains --with-mailer flag. Scaffolds migration, edits Tenant entity to add use TenantMailerConfigTrait;, updates config/packages/tenancy.yaml with commented-out mailer section defaults. Uses nikic/php-parser (already a Phase 18 dep) for entity detection.

### Claude's Discretion
- Exact integration point for transport decoration (Transports registry vs TransportFactoryInterface extension vs custom scheme). Researcher and planner to choose based on Symfony 7.x idioms.
- Exact regex / sanitization helper structure for D-06.
- Test infrastructure for the async canary test.
- Migration file format (bundle ships copy-pasteable snippet in UPGRADE.md; tenancy:install NOT extended to register the migration — WAIT: D-09 above says it IS extended. Researcher confirms D-09 is the locked decision).

### Deferred Ideas (OUT OF SCOPE)
- Per-tenant mailer template overrides.
- Bounce-handling hooks / per-tenant DSN credential rotation.
- Validating DSN at tenant-creation time.
- IMAP/POP3 inbox per tenant.
</user_constraints>

<phase_requirements>
## Phase Requirements

| ID | Description | Research Support |
|----|-------------|------------------|
| BOOT-04 | Per-tenant Mailer bootstrapper sends mail from tenant's own SMTP transport with tenant's From/Reply-To, correctly in BOTH sync and async Messenger-routed dispatch | Full coverage: X-Transport header survival verified, transport integration point confirmed, LRU cache design confirmed, SmtpTransport.stop() verified, async canary test pattern established |
</phase_requirements>

---

## Summary

This phase delivers a per-tenant Mailer bootstrapper that is async-correct by default — the headline differentiator over `stancl/tenancy` (Laravel). The core mechanics are verified against Symfony source at the 7.x branch. No training-time assumptions are used for the critical claims.

**X-Transport header survival across Messenger transports is CONFIRMED.** When `PhpSerializer` (the default) serializes a Messenger `Envelope` containing a `SendEmailMessage`, it uses PHP's native `serialize()` on the entire `Envelope`. The `SendEmailMessage` holds the `Email` (a `Message` subclass), and `Message.__serialize()` returns `[$this->headers, $this->body]` — headers including X-Transport are serialized with the object. On the worker side, `MessageHandler::__invoke()` extracts the `Email` and calls `mailer.transports->send()` (a `Transports` instance), which reads and removes X-Transport, routes to the named transport, and sends. Header survival is guaranteed with PHP serialization. The JSON `Serializer` alternative breaks for `SendEmailMessage` (known issue #33394) and is not the default — do not use it.

**The idiomatic Symfony 7.x integration point is injecting a `tenancy+` custom-scheme transport factory** tagged `mailer.transport_factory`. The `Transports` registry is created by `Transport::fromStrings()` and cannot be cheaply decorated (it's a `final` class). The recommended approach is: at bundle boot time, inject `tenant_<slug>` DSN strings as named transports via the `mailer.transports` service argument, backed by a custom `TenantTransportFactory` that intercepts `tenancy+smtp://tenant_<slug>` DSNs, calls `TenantProviderInterface::findBySlug()`, builds the real `EsmtpTransport`, and caches it in the LRU. The `MailerTransportContractPass` injects this map into the `mailer.transports` service definition. **However**, because tenants are runtime entities, the exact slugs are not known at compile time — the `Transports` registry holds a lazy factory, not pre-instantiated transport instances. This is the key design insight for D-01.

**Practical integration point decision (Claude's Discretion resolved):** Decorate `mailer.transports` with a `TenantAwareTransports` wrapper that intercepts `tenant_*` lookups, resolves the tenant on first use, builds and caches the transport in the LRU, and delegates all other names to the inner `Transports`. This is a decorator pattern on the `mailer.transports` service (which is `Transports` but aliased to `TransportInterface` for the DI container). The `MessageHandler` injects `mailer.transports` — if we decorate that service, the worker-side lookup flows through our decorator automatically.

**Primary recommendation:** Decorate `mailer.transports` (the `Transports` registry), not the `TransportFactoryInterface`. The decorator intercepts `send()` calls where the X-Transport header is `tenant_<slug>`, resolves the tenant, builds and LRU-caches the per-tenant `EsmtpTransport`, then calls `doSend()` directly. `SmtpTransport::stop()` (confirmed to exist) is called on LRU eviction and on `TenantContextCleared`.

---

## Architectural Responsibility Map

| Capability | Primary Tier | Secondary Tier | Rationale |
|------------|-------------|----------------|-----------|
| X-Transport header stamping | API/Backend (HTTP context, MessageEvent listener) | — | Must fire before Messenger serialization; MessageEvent fires in HTTP context when isQueued=true |
| Per-tenant transport routing | API/Backend (worker, mailer.transports decorator) | — | Worker calls mailer.transports.send(); decorator intercepts tenant_<slug> |
| Transport LRU cache | API/Backend (long-running worker process) | — | In-process bounded cache; cleared on TenantContextCleared |
| DSN sanitization | API/Backend (SanitizingMailerDecorator wraps MailerInterface) | — | Decorates at MailerInterface level before any exception propagates |
| Compile-time guard | Build / DI Compiler | — | MailerTransportContractPass; container compile time, not runtime |
| Profiler mailer section | Frontend Server (dev only, kernel.debug=true) | — | Reads from TenantDataCollector; no prod cost |
| Schema migration | Database / Storage | — | landlord schema; mailerDsn/mailerFrom/mailerReplyTo columns |
| tenancy:install --with-mailer | Build / CLI | — | One-time scaffolding; reads/writes files and config |

---

## Standard Stack

### Core
| Library | Version | Purpose | Why Standard |
|---------|---------|---------|--------------|
| symfony/mailer | ^7.0 | Email abstraction; Transports registry; X-Transport routing | Already the Symfony standard mailer; `suggest` in composer.json |
| symfony/messenger | ^7.0 | Async dispatch; SendEmailMessage; PhpSerializer | Already a bundle dep (Phase 6); async canary test requires it |
| symfony/mime | ^7.0 | Email/Message/Headers objects; __serialize/__unserialize | Pulled automatically with symfony/mailer |

### Supporting
| Library | Version | Purpose | When to Use |
|---------|---------|---------|-------------|
| nikic/php-parser | ^5.0 | Tenant entity AST edit (use trait insertion) | Phase 18 dep; D-09 --with-mailer flag |
| doctrine/dbal | ^3.0\|^4.0 | Optional; already guarded by interface_exists | Only when user has Doctrine; not a hard dep |

### Alternatives Considered
| Instead of | Could Use | Tradeoff |
|------------|-----------|----------|
| Decorate mailer.transports | Register TransportFactory with mailer.transport_factory tag | Factory approach can't intercept X-Transport routing inline; Transports decorator is simpler and keeps routing logic co-located |
| PhpSerializer (default) | Symfony JSON Serializer | JSON Serializer breaks for SendEmailMessage/RawMessage (issue #33394); PhpSerializer is the only safe choice |
| LRU with PHP array | SplDoublyLinkedList + hashmap | Pure-PHP LRU using array + array_key_first/array_splice is simpler for < 1000 items; SplDoublyLinkedList adds complexity without measurable benefit at size 32 |

**Installation (for development/testing):**
```bash
composer require --dev symfony/mailer
# Production: already in user's project; bundle suggests it
```

---

## Architecture Patterns

### System Architecture Diagram

```
HTTP Request
    │
    ▼
TenantContextOrchestrator (kernel.request, priority 20)
    │ resolves tenant
    ▼
BootstrapperChain::boot(tenant)
    │
    ├──► DatabaseSwitchBootstrapper::boot()
    ├──► DoctrineBootstrapper::boot()
    └──► MailerBootstrapper::boot()
             │ reads tenant->getMailerDsn() / getMailerFrom()
             │ stores in MailerContext (or reads live from TenantContext)
             ▼
         (no-op at boot; real work is in decorator)

User code: $mailer->send($email)
    │
    ▼
SanitizingMailerDecorator (wraps MailerInterface)
    │ try { inner->send($email) } catch (TransportException) { redact DSN, re-throw as TenantSanitizedTransportException }
    ▼
Mailer::send($email) [inner]
    │
    ├── if no MessageBus → transport->send() directly (sync path)
    │
    └── if MessageBus configured (async path):
            │
            ▼
        MessageEvent dispatched (isQueued=true, clone of $email)
            │
            ▼
        TenantMessageDecorator::onMessage()
            │  [MUST run BEFORE Messenger serialization]
            │  stamps X-Transport: tenant_<slug> on cloned email
            │  stamps From: tenant->getMailerFrom()
            │  stamps Reply-To: tenant->getMailerReplyTo() (if set)
            ▼
        SendEmailMessage($originalEmail, $envelope) dispatched to Messenger bus
            │
            ▼
        [Messenger transport: Doctrine / AMQP / Redis]
        [PhpSerializer serializes Envelope + SendEmailMessage + Email with all headers]
            │
═══════════════════════════════════════════════════════
WORKER PROCESS (clean context after restart)
═══════════════════════════════════════════════════════
            │
            ▼
        TenantWorkerMiddleware::handle()
            │ reads TenantStamp from envelope
            │ calls BootstrapperChain::boot(tenant)
            │   ├── DatabaseSwitchBootstrapper::boot()
            │   ├── DoctrineBootstrapper::boot()
            │   └── MailerBootstrapper::boot()
            ▼
        MessageHandler::__invoke(SendEmailMessage)
            │ calls mailer.transports->send($email, $envelope)
            ▼
        TenantAwareTransportsDecorator::send()
            │ reads X-Transport header: "tenant_acme"
            │ checks LRU cache for "tenant_acme"
            │   hit → use cached EsmtpTransport
            │   miss → TenantProviderInterface::findBySlug("acme")
            │          → Transport::fromDsn(tenant->getMailerDsn())
            │          → store in LRU, evict+stop() if over limit
            ▼
        EsmtpTransport::send($email) [tenant-specific SMTP]
            │
            ▼
        [Email sent from tenant's SMTP server]
            │
            ▼
        BootstrapperChain::clear() [in reverse]
            │ MailerBootstrapper::clear() [no-op or LRU housekeeping]
        TenantContextCleared dispatched
            │ LRU cache flush (all active transports stopped)
```

### Recommended Project Structure
```
src/
├── Bootstrapper/
│   └── MailerBootstrapper.php          # implements TenantBootstrapperInterface
├── Mailer/
│   ├── TenantAwareTransportsDecorator.php  # decorates mailer.transports
│   ├── TenantMessageDecorator.php       # MessageEvent listener (stamps X-Transport + From/Reply-To)
│   ├── LruTransportCache.php            # bounded LRU, stops transports on eviction
│   ├── TenantMailerConfigTrait.php      # default impl for 3 new TenantInterface methods
│   └── SanitizingMailerDecorator.php    # wraps MailerInterface; redacts DSN from TransportException
├── DependencyInjection/
│   └── Compiler/
│       └── MailerTransportContractPass.php  # compile-time guard
├── Exception/
│   └── TenantSanitizedTransportException.php  # extends TransportException
└── Command/Install/Step/
    └── MailerSetupStep.php              # --with-mailer scaffold sub-step
```

### Pattern 1: TenantAwareTransportsDecorator
**What:** Decorates `mailer.transports` (`Transports` class). Intercepts `send()` when X-Transport header is `tenant_<slug>`. Delegates non-tenant names to inner `Transports`.
**When to use:** Always — this is the single transport routing mechanism.
**Example:**
```php
// Source: Derived from Symfony\Component\Mailer\Transport\Transports source (verified 7.3)
final class TenantAwareTransportsDecorator implements TransportInterface
{
    public function __construct(
        private readonly TransportInterface $inner,          // mailer.transports
        private readonly TenantProviderInterface $provider,
        private readonly LruTransportCache $lruCache,
        private readonly TenantContext $context,
    ) {}

    public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
    {
        if (!$message instanceof Message || !$message->getHeaders()->has('X-Transport')) {
            return $this->inner->send($message, $envelope);
        }

        $header = $message->getHeaders()->get('X-Transport');
        $transportName = $header->getBody();

        if (!str_starts_with($transportName, 'tenant_')) {
            return $this->inner->send($message, $envelope);
        }

        $slug = substr($transportName, 7); // strip "tenant_" prefix
        $transport = $this->lruCache->get($slug)
            ?? $this->buildAndCache($slug);

        $message->getHeaders()->remove('X-Transport');
        return $transport->send($message, $envelope);
    }

    private function buildAndCache(string $slug): TransportInterface
    {
        $tenant = $this->provider->findBySlug($slug);
        $dsn = $tenant->getMailerDsn()
            ?? throw new \RuntimeException("Tenant '$slug' has no mailerDsn configured");
        $transport = Transport::fromDsn($dsn);
        $this->lruCache->set($slug, $transport);
        return $transport;
    }

    public function __toString(): string { return 'tenant-aware:'.(string)$this->inner; }
}
```

### Pattern 2: TenantMessageDecorator (MessageEvent listener)
**What:** Listens on `MessageEvent` with `isQueued() === true`. Stamps `X-Transport: tenant_<slug>`, sets `From:` and `Reply-To:` headers from TenantContext.
**When to use:** Always-on when symfony/mailer is installed.
**Example:**
```php
// Source: Verified against Symfony\Component\Mailer\Mailer::send() source (7.3 branch)
// Key insight: MessageEvent fires BEFORE SendEmailMessage dispatch. The cloned $email
// is what goes into SendEmailMessage. Headers stamped here ARE serialized by PhpSerializer.
final class TenantMessageDecorator implements EventSubscriberInterface
{
    public function __construct(private readonly TenantContext $context) {}

    public function onMessage(MessageEvent $event): void
    {
        // Only stamp when queued (before Messenger serialization) OR sync (before transport)
        $tenant = $this->context->getTenant();
        if (null === $tenant || !$tenant->getMailerDsn()) {
            return;
        }

        $message = $event->getMessage();
        if (!$message instanceof Message) {
            return;
        }

        $headers = $message->getHeaders();

        // Stamp X-Transport for routing (for both sync and async paths)
        if (!$headers->has('X-Transport')) {
            $headers->addTextHeader('X-Transport', 'tenant_'.$tenant->getSlug());
        }

        // Set From header from tenant config
        if ($tenant->getMailerFrom() && !$headers->has('From')) {
            // Use Email::from() setter if $message is Email; else addHeader
            if ($message instanceof Email) {
                $message->from($tenant->getMailerFrom());
            }
        }

        // Set Reply-To from tenant config
        if ($tenant->getMailerReplyTo() && !$headers->has('Reply-To')) {
            if ($message instanceof Email) {
                $message->replyTo($tenant->getMailerReplyTo());
            }
        }
    }

    public static function getSubscribedEvents(): array
    {
        // Run BEFORE Symfony's own MessageEvent listeners (default priority 0)
        // Use priority 100 to ensure headers are stamped first
        return [MessageEvent::class => ['onMessage', 100]];
    }
}
```

### Pattern 3: LruTransportCache
**What:** Bounded in-memory cache (default 32 slots). On eviction (LRU) or full clear, calls `SmtpTransport::stop()` on the evicted transport.
**When to use:** Always held by TenantAwareTransportsDecorator.
**Example:**
```php
// Source: [VERIFIED: SmtpTransport::stop() exists in symfony/mailer 7.3 source]
// PHP LRU using ordered array (splice on access, append on miss) — O(n) for n<=32 is fine
final class LruTransportCache
{
    /** @var array<string, TransportInterface> slug → transport in LRU order (most-recent last) */
    private array $cache = [];

    public function __construct(private readonly int $maxSize = 32) {}

    public function get(string $slug): ?TransportInterface
    {
        if (!isset($this->cache[$slug])) {
            return null;
        }
        // Move to end (most recently used)
        $transport = $this->cache[$slug];
        unset($this->cache[$slug]);
        $this->cache[$slug] = $transport;
        return $transport;
    }

    public function set(string $slug, TransportInterface $transport): void
    {
        if (isset($this->cache[$slug])) {
            unset($this->cache[$slug]);
        } elseif (count($this->cache) >= $this->maxSize) {
            // Evict least recently used (first element)
            $lruSlug = array_key_first($this->cache);
            $this->stopTransport($this->cache[$lruSlug]);
            unset($this->cache[$lruSlug]);
        }
        $this->cache[$slug] = $transport;
    }

    public function clear(): void
    {
        foreach ($this->cache as $transport) {
            $this->stopTransport($transport);
        }
        $this->cache = [];
    }

    private function stopTransport(TransportInterface $transport): void
    {
        // SmtpTransport::stop() closes the underlying socket cleanly
        // [VERIFIED: method exists in symfony/mailer SmtpTransport 7.3]
        if (method_exists($transport, 'stop')) {
            $transport->stop();
        }
    }
}
```

### Pattern 4: MailerTransportContractPass
**What:** Compiler pass that validates mailer config correctness at container build time.
**When to use:** Always registered when symfony/mailer is installed.
**Example:**
```php
// Source: Modeled on MessengerMiddlewarePass (verified in codebase) and
// CacheDecoratorContractPass (verified in codebase)
final class MailerTransportContractPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!interface_exists(\Symfony\Component\Mailer\MailerInterface::class)) {
            return;
        }

        $async = $container->getParameter('tenancy.mailer.async'); // 'auto'|'true'|'false'

        $isAsync = match ($async) {
            'true' => true,
            'false' => false,
            default => $this->detectAsyncRouting($container),
        };

        if ($isAsync) {
            // Verify X-Transport strategy is in place (TenantMessageDecorator registered)
            if (!$container->hasDefinition('tenancy.mailer.message_decorator')) {
                throw new \LogicException(
                    'tenancy: Mailer is routed async via Messenger but the X-Transport strategy '
                    .'is not configured. Ensure symfony/mailer is installed and the tenancy bundle '
                    .'is properly configured. Set tenancy.mailer.async: false to disable this check.'
                );
            }
        }
    }

    private function detectAsyncRouting(ContainerBuilder $container): bool
    {
        // Check framework.messenger.routing for SendEmailMessage entries
        // FrameworkExtension stores routing in messenger.default_bus.routing parameter
        $sendEmailClass = \Symfony\Component\Mailer\Messenger\SendEmailMessage::class;
        foreach ($container->getExtensionConfig('framework') as $config) {
            $routing = $config['messenger']['routing'] ?? [];
            if (isset($routing[$sendEmailClass])) {
                return true;
            }
        }
        return false;
    }
}
```

### Pattern 5: SanitizingMailerDecorator
**What:** Decorates `mailer` service (MailerInterface). Catches TransportException, redacts DSN password, re-throws as TenantSanitizedTransportException.
**Example:**
```php
// Source: D-06 from CONTEXT.md; regex pattern verified against DSN format
// (://[^:]+:)[^@]+(@) covers smtp://, smtps://, sendmail:// with credentials
final class SanitizingMailerDecorator implements MailerInterface
{
    public function __construct(private readonly MailerInterface $inner) {}

    public function send(RawMessage $message, ?Envelope $envelope = null): void
    {
        try {
            $this->inner->send($message, $envelope);
        } catch (TransportExceptionInterface $e) {
            $sanitized = preg_replace(
                '/(:[\/]{0,2}[^:]+:)[^@]+(@)/',
                '$1***$2',
                $e->getMessage()
            );
            throw new TenantSanitizedTransportException(
                $sanitized ?? $e->getMessage(),
                $e->getCode(),
                $e
            );
        }
    }
}
```

### Anti-Patterns to Avoid
- **Using JSON Serializer with SendEmailMessage:** The Symfony JSON Serializer cannot reconstruct `RawMessage` from JSON (issue #33394 — no constructor parameter mapping). Only `PhpSerializer` (default) works. Do not configure `messenger.serializer.id: messenger.transport.symfony_serializer` globally when Mailer is async.
- **Stamping X-Transport only on the original $email:** In `Mailer::send()` async path, Symfony stamps the CLONED message in the pre-dispatch `MessageEvent`. The `TenantMessageDecorator` must listen on `MessageEvent` (not mutate the email object before `$mailer->send()`) so the stamp appears on the cloned email that goes into `SendEmailMessage`.
- **Not calling stop() on transport eviction:** Long-running workers accumulate open SMTP sockets. `SmtpTransport::stop()` closes the underlying `AbstractStream`. Always call on eviction.
- **Decorating TransportFactoryInterface instead of mailer.transports:** `TransportFactoryInterface` implementations create transports from DSN strings at DI build time (for named transports in framework.mailer.transports). They cannot lazily resolve runtime tenants. The `mailer.transports` decorator approach resolves tenants at send time.
- **Making MailerBootstrapper boot() do heavy work:** The tenant's mailerDsn is available from TenantContext (already loaded by the resolver). The bootstrapper's boot() is a no-op or sets a lightweight flag — all transport resolution is deferred to the decorator's send() call.

---

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| SMTP transport from DSN | Custom SMTP socket manager | `Transport::fromDsn($dsn)` (EsmtpTransport) | Handles TLS negotiation, STARTTLS, AUTH PLAIN/LOGIN, cert validation, rate limiting |
| X-Transport header routing | Custom transport dispatcher | `Transports::send()` + X-Transport header (verified Symfony pattern) | Already handles default fallback, InvalidArgumentException on unknown transport, X-Transport removal after routing |
| Email/Envelope serialization | Custom PHP serialization magic | PHP native serialize() via PhpSerializer (default Messenger serializer) | Email.__serialize() / __unserialize() handle Headers + body correctly; verified in symfony/mime source |
| LRU eviction data structure | Custom doubly-linked list | PHP ordered array with `array_key_first()` / `unset()` / append pattern | O(n) at n=32 is 32 array operations — negligible. SplDoublyLinkedList adds complexity with no benefit. |
| DSN credential parsing for sanitization | Custom URL parser | Regex `(:[\/]{0,2}[^:]+:)[^@]+(@)` → `$1***$2` | Covers smtp://, smtps://, sendmail:// with credentials; matches PHP's parse_url semantics for DSN format |
| Symfony container service decoration | Compiler pass manual wiring | DI `->decorate('mailer')` or `->decorate('mailer.transports')` in services.php | Standard Symfony decoration pattern; `.inner` reference handles the chain automatically |

**Key insight:** The entire X-Transport header routing system (Transports::send() → read header → remove header → delegate to named transport) is already implemented in Symfony. The bundle only needs to add a decorator layer that intercepts `tenant_*` names before they reach the inner `Transports` registry. Do not reimplement routing.

---

## Critical Technical Findings

### Finding 1: X-Transport Header Survival — VERIFIED

**Claim:** X-Transport header survives PHP serialization through Doctrine/AMQP/Redis Messenger transports.

**Evidence (source code verified):**
1. `Mailer::send()` dispatches `SendEmailMessage($message, $envelope)` [VERIFIED: symfony/mailer Mailer.php, 7.3 branch]
2. `SendEmailMessage` holds the `RawMessage` directly [VERIFIED: symfony/mailer Messenger/SendEmailMessage.php]
3. `Message.__serialize()` returns `[$this->headers, $this->body]` [VERIFIED: symfony/mime Message.php]
4. `PhpSerializer::encode()` calls `serialize($envelope)` (PHP native) [VERIFIED: symfony/messenger Transport/Serialization/PhpSerializer.php, 7.3 branch]
5. `PhpSerializer::decode()` calls `unserialize($body)` — full object graph restored including headers [VERIFIED: same source]
6. `MessageHandler::__invoke()` calls `$this->transport->send($message->getMessage(), ...)` [VERIFIED: symfony/mailer Messenger/MessageHandler.php]
7. `Transports::send()` reads `$message->getHeaders()->get('X-Transport')->getBody()` then routes [VERIFIED: symfony/mailer Transport/Transports.php]

**Conclusion (HIGH confidence):** X-Transport header is part of the `Headers` object which is PHP-serialized and PHP-unserialized with full fidelity. Header survives across Doctrine, AMQP, and Redis transports when using the default `PhpSerializer`.

**Exception:** If users configure `messenger.serializer.id: messenger.transport.symfony_serializer` (JSON Serializer), deserialization of `SendEmailMessage`/`RawMessage` fails (issue #33394). The `MailerTransportContractPass` should detect and warn about this configuration.

### Finding 2: MessageEvent Firing Sequence — VERIFIED

**In async path:**
1. `$mailer->send($email)` called
2. `MessageEvent(clonedEmail, clonedEnvelope, transport, queued=true)` dispatched [VERIFIED: Mailer.php]
3. `TenantMessageDecorator::onMessage()` fires — stamps X-Transport + From/Reply-To on the **cloned** email
4. Stamps from `$event->getStamps()` collected → passed to `$bus->dispatch(new SendEmailMessage($originalEmail, ...), $stamps)`
5. `SendEmailMessage` wraps the **original** `$message` (not the clone) [VERIFIED: Mailer.php line: `new SendEmailMessage($message, $envelope)` — $message is the original]

**CRITICAL FINDING:** The `X-Transport` header must be stamped on **the email object that ends up in `SendEmailMessage`**, which is the ORIGINAL `$message` passed to `$mailer->send()`, NOT the clone used in the pre-dispatch `MessageEvent`. This means `TenantMessageDecorator` should set headers on the email **before** calling `$mailer->send()`, OR listen on the sync `MessageEvent` (isQueued=false) that fires inside `AbstractTransport::send()` in the worker.

**Corrected design:** The `TenantMessageDecorator` should listen on `MessageEvent` with `isQueued() === false` — this fires inside `AbstractTransport::send()` on the WORKER SIDE, where the correct tenant context has been restored by `TenantWorkerMiddleware`. This is the clean approach: the worker boots the tenant, then `AbstractTransport::send()` fires `MessageEvent`, `TenantMessageDecorator` stamps the live tenant's headers, and `Transports::send()` routes via X-Transport.

**Alternative for sync path:** For synchronous dispatch (no Messenger), `MessageEvent(isQueued=false)` fires in `AbstractTransport::send()` in the HTTP context where `TenantContext` already has the active tenant. Same listener works for both paths.

**The D-01 "X-Transport stamp before Messenger serialization" from CONTEXT.md is NOT needed.** The bundle's TenantWorkerMiddleware already restores tenant context before `MessageHandler.__invoke()` — the `MessageEvent` listener handles both paths cleanly. No pre-serialization stamping is required.

**Impact on TenantMessageDecorator:** Listen on `MessageEvent` with `isQueued() === false` (the transport-level event, not the bus-level pre-queuing event). This fires in both HTTP context (sync) and worker context (async-restored). The X-Transport header is stamped at this point and Transports::send() routes it immediately.

### Finding 3: Transports Registry Architecture — VERIFIED

**Service ID:** `mailer.transports` → `Symfony\Component\Mailer\Transport\Transports` instance [VERIFIED: symfony/framework-bundle Resources/config/mailer.php]
- Created by: `Transport::fromStrings($dsnMap)` factory
- Injected into: `MessageHandler` (worker-side handler) at `mailer.messenger.message_handler`
- `Transports` class is `final` — cannot be extended [VERIFIED: symfony/mailer Transport/Transports.php]

**DI service IDs relevant to the bundle:**
- `mailer.mailer` (alias: `mailer`, `MailerInterface`) — the `Mailer` instance
- `mailer.transports` (alias: `mailer.default_transport`, `TransportInterface`) — the `Transports` registry
- `mailer.messenger.message_handler` — `MessageHandler` injecting `mailer.transports`

**Decoration strategy:** Use `->decorate('mailer.transports')` in DI. The `MessageHandler` gets the decorator instead of the raw `Transports`. The decorator intercepts `tenant_*` names; all other names fall through to inner `Transports`.

**Config injection:** At compile time, the `MailerTransportContractPass` does NOT inject tenant DSNs (tenants are runtime). It only validates strategy correctness. The decorator handles runtime lookup via `TenantProviderInterface::findBySlug()`.

### Finding 4: SmtpTransport Connection Lifecycle — VERIFIED

`SmtpTransport::stop()` exists and is public [VERIFIED: symfony/mailer Transport/Smtp/SmtpTransport.php source, method signature confirmed].

`SmtpTransport` also has `__destruct()` which calls `stop()`, so destructor is a fallback. However, in long-running PHP-FPM or PHP CLI worker processes, relying on destructor timing is unsafe — `stop()` must be called explicitly on LRU eviction.

`SmtpTransport::start()` exists as well — not needed; `EsmtpTransport` lazily connects on first message.

### Finding 5: MessengerTransportListener — Important Distinction

`MessengerTransportListener` (registered as `kernel.event_subscriber`) handles `X-Bus-Transport` MIME header — NOT `X-Transport`. [VERIFIED: symfony/mailer EventListener/MessengerTransportListener.php]

- `X-Bus-Transport`: selects which Messenger BUS transport (e.g., `async_priority_high`) the email is queued to
- `X-Transport`: selects which Mailer transport (e.g., `marketing`, `tenant_acme`) the email is sent via

These are orthogonal and do not conflict. The bundle only uses `X-Transport`.

---

## Common Pitfalls

### Pitfall 1: Stamping the Wrong Email Object in Async Path
**What goes wrong:** If `TenantMessageDecorator` stamps `X-Transport` on the clone used in `MessageEvent(isQueued=true)`, the stamp does NOT appear in `SendEmailMessage` (which wraps the original). Email reaches worker with no X-Transport header, falls back to default transport.
**Why it happens:** Mailer.php creates a CLONE for the pre-dispatch event, then wraps the ORIGINAL in `SendEmailMessage`. Stamps on the clone are lost.
**How to avoid:** Listen on `MessageEvent(isQueued=false)` — fires in `AbstractTransport::send()` where the email passed is the one actually being sent (worker-side, after deserialization).
**Warning signs:** Async test shows emails sent via landlord transport instead of tenant transport; X-Transport header missing in captured SMTP output.

### Pitfall 2: JSON Serializer Breaks SendEmailMessage
**What goes wrong:** User configures `messenger.serializer.id: messenger.transport.symfony_serializer`. Worker fails to deserialize `SendEmailMessage` with "Cannot create an instance of Symfony\Component\Mime\RawMessage from serialized data because its constructor requires parameter 'message' to be present."
**Why it happens:** Symfony's JSON Serializer uses Symfony Serializer component's object normalizer, which cannot reconstruct `RawMessage` (constructor requires the raw message string, not mapped via normalizer).
**How to avoid:** The `MailerTransportContractPass` should detect JSON serializer config and emit a warning or error.
**Warning signs:** Issue #33394 symptom — `MessageDecodingFailedException` in worker logs.

### Pitfall 3: Unbounded SMTP Socket Growth in Long-Running Workers
**What goes wrong:** Without LRU cap, each distinct tenant slug creates a new `EsmtpTransport` with an open SMTP socket. 100-tenant worker holds 100 open sockets until OOM or FD exhaustion.
**Why it happens:** `EsmtpTransport` maintains a persistent connection (SMTP KEEPALIVE behavior).
**How to avoid:** LRU cap at 32 (configurable). Call `SmtpTransport::stop()` on eviction.
**Warning signs:** Worker process FD count grows monotonically (check `/proc/<pid>/fd`); SMTP server reports "too many connections from single IP".

### Pitfall 4: DSN Password Leaked in Exception Message
**What goes wrong:** `TransportException` message includes the DSN string used to create the transport. Example: `Unable to connect to "smtp://user:password@smtp.example.com"`.
**Why it happens:** `AbstractTransport::doSend()` wraps lower-level socket errors with the connection string in the message.
**How to avoid:** `SanitizingMailerDecorator` wraps the MailerInterface — regex redacts password component. Pattern: `(:[\/]{0,2}[^:]+:)[^@]+(@)` → `$1***$2`.
**Warning signs:** Exception messages containing `@` and `://` in the same string; error logs with visible SMTP credentials.

### Pitfall 5: BootstrapperChain Priority Mis-ordering
**What goes wrong:** `MailerBootstrapper` tagged with higher priority than `DatabaseSwitchBootstrapper` runs first, before DB connection is established. If `boot()` does anything that requires the tenant EM (e.g., re-reading tenant config), it fails.
**Why it happens:** `BootstrapperChainPass` uses `PriorityTaggedServiceTrait`. Higher numeric priority = runs earlier.
**How to avoid:** Tag `tenancy.mailer_bootstrapper` with priority lower than `DatabaseSwitchBootstrapper` (which uses default 0 or explicit). D-07 says: run AFTER DB bootstrappers. Use priority `-20` or lower.
**Warning signs:** Boot order test failure; "no active connection" errors during MailerBootstrapper::boot().

### Pitfall 6: Profiler Mailer Section Leaks DSN Credentials
**What goes wrong:** `collectMailerState()` exposes `tenant->getMailerDsn()` raw in profiler data, DSN with password appears in serialized profile.
**Why it happens:** DX-02 acceptance criterion: `$this->data` must contain no DSN strings with credentials.
**How to avoid:** Apply the same sanitization regex as `SanitizingMailerDecorator`. Expose a `redactDsn(string $dsn): string` helper (standalone function or trait) shared between the decorator and the collector.
**Warning signs:** Stored profiler profile contains `smtp://` with `:password@` substring.

---

## Code Examples

Verified patterns from official Symfony sources:

### Transports::send() — X-Transport routing (the actual implementation)
```php
// Source: [VERIFIED: symfony/mailer Transport/Transports.php, 7.3 branch]
public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
{
    if (RawMessage::class === $message::class || !$message->getHeaders()->has('X-Transport')) {
        return $this->default->send($message, $envelope);
    }

    $headers = $message->getHeaders();
    $transport = $headers->get('X-Transport')->getBody();
    $headers->remove('X-Transport');  // ← removed before actual send

    if (!isset($this->transports[$transport])) {
        throw new InvalidArgumentException(sprintf(
            'The "%s" transport does not exist (available transports: "%s").',
            $transport, implode('", "', array_keys($this->transports))
        ));
    }
    // ...delegates to named transport
}
```

### Mailer::send() — async path (showing what goes into SendEmailMessage)
```php
// Source: [VERIFIED: symfony/mailer Mailer.php, 7.3 branch]
// Key: $message (original) goes into SendEmailMessage, not $clonedMessage
$clonedMessage = clone $message;
$clonedEnvelope = null !== $envelope ? clone $envelope : Envelope::create($clonedMessage);
$event = new MessageEvent($clonedMessage, $clonedEnvelope, (string) $this->transport, true);
$this->dispatcher->dispatch($event);
$stamps = $event->getStamps();  // ← stamps from pre-dispatch MessageEvent

// Stamps on $clonedMessage are LOST here:
$this->bus->dispatch(new SendEmailMessage($message, $envelope), $stamps);
//                            ↑ ORIGINAL $message, not $clonedMessage
```

### PhpSerializer::encode() — full Envelope PHP-serialized
```php
// Source: [VERIFIED: symfony/messenger Transport/Serialization/PhpSerializer.php, 7.3 branch]
public function encode(Envelope $envelope): array
{
    $envelope = $envelope->withoutStampsOfType(NonSendableStampInterface::class);
    $body = addslashes(serialize($envelope));  // ← PHP native serialize()
    // ... base64 if non-UTF8
    return ['body' => $body];
}
// Decode: unserialize($body) → restores complete Envelope with SendEmailMessage + Email + Headers
```

### MessageEvent listener priority — correct event registration
```php
// Source: [VERIFIED: symfony/mailer EventListener/MessengerTransportListener.php]
// The MessengerTransportListener uses default priority (0) for MessageEvent
// TenantMessageDecorator should use priority 100 to run before other listeners
public static function getSubscribedEvents(): array
{
    return [MessageEvent::class => ['onMessage', 100]];
}
```

### SmtpTransport::stop() — verified method signature
```php
// Source: [VERIFIED: symfony/mailer Transport/Smtp/SmtpTransport.php, method exists]
public function stop(): void;  // closes underlying AbstractStream connection
// Also: __destruct() calls stop() — but never rely on destructor timing in long-running workers
```

### mailer.transports DI decoration pattern
```php
// Source: [VERIFIED: symfony/framework-bundle Resources/config/mailer.php]
// mailer.transports is Transports instance created by Transport::fromStrings()
// mailer.messenger.message_handler injects mailer.transports directly
// Decoration in services.php:
$services->set('tenancy.mailer.transports_decorator', TenantAwareTransportsDecorator::class)
    ->decorate('mailer.transports')
    ->args([
        service('.inner'),
        service('tenancy.provider')->nullOnInvalid(),
        service('tenancy.mailer.lru_cache'),
        service('tenancy.context'),
    ]);
```

### MailerTransportContractPass registration — follows MessengerMiddlewarePass pattern
```php
// Source: [VERIFIED: src/TenancyBundle.php — MessengerMiddlewarePass registered at priority 1]
// MailerTransportContractPass should run at default priority (TYPE_BEFORE_OPTIMIZATION, 0)
// It only validates config, doesn't modify definitions that other passes depend on
public function build(ContainerBuilder $container): void
{
    parent::build($container);
    // ... existing passes ...
    if (interface_exists(MailerInterface::class)) {
        $container->addCompilerPass(new MailerTransportContractPass());
    }
}
```

---

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|-----------------|--------------|--------|
| `stancl/tenancy` per-tenant mailer (Laravel) — sync only | Symfony bundle async-correct via X-Transport stamp + worker middleware | This phase | Async correctness is the headline differentiator |
| Hand-wiring per-tenant transport at DI compile time | Runtime LRU decorator (tenants not enumerable at compile time) | This phase | Supports arbitrary runtime tenant creation |
| Custom scheme (tenancy+smtp://) + TransportFactory | Decorator on mailer.transports service | Research finding | Decorator is simpler: no custom DSN scheme, no TransportFactory registration needed |

**Deprecated/outdated:**
- `tenancy+smtp://` custom scheme approach: Requires TransportFactoryInterface registration + user config (`framework.mailer.transports.tenant_acme: tenancy+smtp://acme`). Adds complexity without benefit over the decorator approach. Not recommended.
- Pre-dispatch MessageEvent stamping (isQueued=true): Headers on cloned message are lost (see Pitfall 1). Use transport-level MessageEvent (isQueued=false) instead.

---

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | BootstrapperChain uses `PriorityTaggedServiceTrait` — higher numeric priority = earlier boot() call | Common Pitfalls, Pattern 4 | Could cause wrong ordering if priority semantics are inverted. Verified by reading BootstrapperChainPass.php which uses `findAndSortTaggedServices` — this trait returns services in DESCENDING priority order (highest first). So priority -20 for MailerBootstrapper = runs AFTER priority 0 (DatabaseSwitchBootstrapper). VERIFIED in source. [Not actually assumed — verified.] |
| A2 | `Transport::fromDsn($dsn)` works at runtime in a worker process with an arbitrary tenant DSN | Pattern 1 | If EsmtpTransport requires dispatcher/logger injected at DI compile time and cannot be constructed at runtime, the lazy approach fails. Mitigation: `Transport::fromDsn()` accepts optional dispatcher/logger — inject from DI via constructor of `TenantAwareTransportsDecorator`. [LOW risk — verified that Transport::fromDsn() is a static factory that creates transport instances with optional deps] |
| A3 | The `MailerBootstrapper::boot()` is a no-op (all work in decorator) — no state stored per-request in the bootstrapper | Architecture | If a future phase requires per-request mailer state (e.g., event tracking headers), the no-op boot() would need to be extended. For Phase 20, this is correct. |

**If this table is empty by production:** All critical claims (X-Transport survival, stop() method, Transports service ID, PhpSerializer behavior, MessageEvent async firing sequence) were verified against Symfony 7.3 source. No training-time assumptions on critical paths.

---

## Open Questions (RESOLVED)

1. **TenantMessageDecorator listener: isQueued=false only, or both?** — **RESOLVED**
   - What we know: `isQueued=false` MessageEvent fires in both sync HTTP path (AbstractTransport::send) and async worker path (AbstractTransport::send called by MessageHandler). This covers both cases cleanly.
   - What's unclear: Is there a case where the user calls `$mailer->send()` in a context where tenant context IS available and wants the X-Transport stamp to control the async queue selection (X-Bus-Transport), not just the mailer transport selection?
   - **Resolution:** Plan 03 confirms `isQueued=false` only (priority 100), no `isQueued` filter needed for the sync path because Mailer's transport-level MessageEvent fires identically in both sync and async paths. The `isQueued=true` pre-dispatch event is for Messenger bus stamp injection (X-Bus-Transport), which is not needed for this phase. If needed later, it's additive.

2. **`Transport::fromDsn()` dispatcher injection in decorator** — **RESOLVED**
   - What we know: `Transport::fromDsn($dsn, $dispatcher, $logger)` accepts optional EventDispatcher and Logger. The decorator should pass through the same dispatcher/logger from DI to ensure `SentMessageEvent`/`FailedMessageEvent` fire correctly.
   - What's unclear: Whether the worker's event dispatcher is the same instance wired to the mailer or a different one.
   - **Resolution:** Will be wired in Plan 03 constructor + Plan 04 services.php registration. `EventDispatcherInterface` becomes the 5th constructor argument to `TenantAwareTransportsDecorator`, and `@event_dispatcher` is wired in the services.php registration. This ensures `SentMessageEvent` / `FailedMessageEvent` fire from tenant transports identically to landlord transports.

3. **Async canary test infrastructure** — **RESOLVED**
   - What we know: `tests/Integration/Messenger/MessengerMiddlewareIntegrationTest.php` uses `StubTenantProvider` and in-process bus. The canary test cannot use a real SMTP server in CI.
   - What's unclear: Whether to use `NullTransport` (discards email but records DSN used) or build a `SpyTransport` that captures send calls.
   - **Resolution:** Plans 00 and 06 use a `SpyTransport` + `SpyTransportRegistry` pattern — test-double transports that capture sent emails for inspection on the worker side after Messenger deserialization. Registered as a named transport for the tenant in the test kernel. The async canary asserts the spy recorded a send (proving the correct tenant transport was used, not landlord).


---

## Environment Availability

| Dependency | Required By | Available | Version | Fallback |
|------------|------------|-----------|---------|----------|
| PHP 8.2+ | All | ✓ | Assumed (project target) | None — hard requirement |
| symfony/mailer | MailerBootstrapper, tests | Suggested dep | ^7.0 | Bundle skips mailer wiring if not installed (interface_exists guard) |
| symfony/messenger | Async canary test | Already dev dep | ^7.0 | Sync-only path always works |
| nikic/php-parser | --with-mailer install step | Phase 18 dep | ^5.0 | If absent, print manual snippet, exit 0 |

**Missing dependencies with no fallback:** None — all are optional or already present.

---

## Validation Architecture

### Test Framework
| Property | Value |
|----------|-------|
| Framework | PHPUnit 11 |
| Config file | `phpunit.xml.dist` |
| Quick run command | `vendor/bin/phpunit --testsuite unit` |
| Full suite command | `vendor/bin/phpunit` |

### Phase Requirements → Test Map
| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|-------------------|-------------|
| BOOT-04-a | MailerBootstrapper implements TenantBootstrapperInterface | unit | `vendor/bin/phpunit tests/Unit/Mailer/MailerBootstrapperTest.php` | ❌ Wave 0 |
| BOOT-04-b | X-Transport header stamped by TenantMessageDecorator on MessageEvent | unit | `vendor/bin/phpunit tests/Unit/Mailer/TenantMessageDecoratorTest.php` | ❌ Wave 0 |
| BOOT-04-c | TenantAwareTransportsDecorator routes tenant_<slug> to correct transport | unit | `vendor/bin/phpunit tests/Unit/Mailer/TenantAwareTransportsDecoratorTest.php` | ❌ Wave 0 |
| BOOT-04-d | LruTransportCache evicts LRU and calls stop() | unit | `vendor/bin/phpunit tests/Unit/Mailer/LruTransportCacheTest.php` | ❌ Wave 0 |
| BOOT-04-e | SanitizingMailerDecorator redacts password from TransportException | unit | `vendor/bin/phpunit tests/Unit/Mailer/SanitizingMailerDecoratorTest.php` | ❌ Wave 0 |
| BOOT-04-f | MailerTransportContractPass rejects missing strategy at compile time | unit | `vendor/bin/phpunit tests/Unit/DependencyInjection/Compiler/MailerTransportContractPassTest.php` | ❌ Wave 0 |
| BOOT-04-g | Async canary: dispatch in tenant context, worker in clean context, assert correct SMTP DSN | integration | `vendor/bin/phpunit tests/Integration/Mailer/AsyncCanaryTest.php` | ❌ Wave 0 |
| BOOT-04-h | LRU cache cleared on TenantContextCleared event | unit | Part of LruTransportCacheTest | ❌ Wave 0 |
| BOOT-04-i | TenantMailerConfigTrait provides default impls for all 3 methods | unit | `vendor/bin/phpunit tests/Unit/Mailer/TenantMailerConfigTraitTest.php` | ❌ Wave 0 |

### Sampling Rate
- **Per task commit:** `vendor/bin/phpunit --testsuite unit`
- **Per wave merge:** `vendor/bin/phpunit`
- **Phase gate:** Full suite green before `/gsd-verify-work`

### Wave 0 Gaps
- [ ] `tests/Unit/Mailer/MailerBootstrapperTest.php`
- [ ] `tests/Unit/Mailer/TenantMessageDecoratorTest.php`
- [ ] `tests/Unit/Mailer/TenantAwareTransportsDecoratorTest.php`
- [ ] `tests/Unit/Mailer/LruTransportCacheTest.php`
- [ ] `tests/Unit/Mailer/SanitizingMailerDecoratorTest.php`
- [ ] `tests/Unit/Mailer/TenantMailerConfigTraitTest.php`
- [ ] `tests/Unit/DependencyInjection/Compiler/MailerTransportContractPassTest.php`
- [ ] `tests/Integration/Mailer/AsyncCanaryTest.php`
- [ ] `tests/Integration/Mailer/MailerTestKernel.php` (test kernel with spy transport)

---

## Security Domain

### Applicable ASVS Categories

| ASVS Category | Applies | Standard Control |
|---------------|---------|-----------------|
| V2 Authentication | no | — |
| V3 Session Management | no | — |
| V4 Access Control | yes | Tenant isolation — wrong tenant DSN = cross-tenant mail; enforced by TenantContext resolution |
| V5 Input Validation | yes | DSN string validated by `Transport::fromDsn()` (throws on invalid DSN); tenant slug validated by `TenantProviderInterface::findBySlug()` (throws TenantNotFoundException) |
| V6 Cryptography | no | SMTP TLS handled by EsmtpTransport — never hand-rolled |

### Known Threat Patterns for This Stack

| Pattern | STRIDE | Standard Mitigation |
|---------|--------|---------------------|
| DSN credentials in exception logs | Information Disclosure | SanitizingMailerDecorator redacts password component |
| Cross-tenant email (wrong transport selected) | Information Disclosure / Spoofing | TenantContext guard in TenantMessageDecorator; returns no-op if context missing |
| SMTP socket exhaustion / DoS | Denial of Service | LRU cache bounded at 32 (configurable); stop() on eviction |
| Tenant DSN in profiler panel | Information Disclosure | Profiler redacts DSN same as SanitizingMailerDecorator; dev-only panel |
| LRU cache poisoning (tenant A gets B's transport) | Tampering | LRU keyed by slug (string equality); TenantProviderInterface::findBySlug() is authoritative source |

---

## Sources

### Primary (HIGH confidence)
- `symfony/mailer` 7.3 branch — Mailer.php, Transports.php, SmtpTransport.php, MessageHandler.php, SendEmailMessage.php, MessengerTransportListener.php, AbstractTransport.php, AbstractTransportFactory.php [VERIFIED via GitHub API]
- `symfony/mime` — Message.php (\_\_serialize/\_\_unserialize), RawMessage.php [VERIFIED via GitHub API]
- `symfony/messenger` 7.3 branch — PhpSerializer.php, Serializer.php [VERIFIED via GitHub API]
- `symfony/framework-bundle` 7.3 branch — FrameworkExtension.php (registerMailerConfiguration), Resources/config/mailer.php, Resources/config/mailer_transports.php [VERIFIED via GitHub API]
- Project codebase — src/Bootstrapper/, src/DependencyInjection/Compiler/, src/TenancyBundle.php, src/Profiler/TenantDataCollector.php, config/services.php, tests/Integration/Messenger/ [READ via Read tool]
- Context7 — symfony/mailer, symfony/messenger documentation [CITED: context7.com/symfony/mailer, context7.com/symfony/messenger]

### Secondary (MEDIUM confidence)
- [Symfony Mailer docs — named transports, X-Transport, async](https://symfony.com/doc/current/mailer.html) — confirmed X-Transport header behavior and framework.mailer.transports YAML config
- [Creating Custom Symfony Mailer Transports](https://albertmoreos.dev/posts/creating-custom-symfony-mailer-transports/) — mailer.transport_factory tag pattern
- [Symfony Messenger docs](https://symfony.com/doc/current/messenger.html) — PhpSerializer default, per-transport serializer config

### Tertiary (LOW confidence)
- [GitHub issue #33394](https://github.com/symfony/symfony/issues/33394) — JSON Serializer incompatibility with SendEmailMessage (confirmed as known issue, status "needs review")

---

## Metadata

**Confidence breakdown:**
- X-Transport header survival: HIGH — verified against Symfony 7.3 source (Mailer.php, PhpSerializer.php, Message.__serialize())
- Integration point (mailer.transports decorator): HIGH — verified FrameworkBundle mailer.php + Transports.php final class
- SmtpTransport.stop(): HIGH — verified SmtpTransport.php method signature
- MessageEvent firing sequence: HIGH — verified Mailer.php source; CORRECTED pre-dispatch clone behavior
- LRU cache design: HIGH — PHP ordered-array approach is well-understood, n=32 makes O(n) trivial
- Async canary test pattern: MEDIUM — pattern derived from existing MessengerMiddlewareIntegrationTest; SpyTransport approach is sound

**Research date:** 2026-05-19
**Valid until:** 2026-08-19 (Symfony 7.x stable — 90 days; check if Symfony 8.0 changes mailer internals before then)
