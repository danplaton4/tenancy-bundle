---
phase: 20-mailer-bootstrapper
verified: 2026-05-20T07:42:07Z
status: gaps_found
score: 3/6 roadmap success criteria fully verified; 2 partial; 1 implemented but architecturally unproven
overrides_applied: 0
re_verification: null
gaps:
  - truth: "BOOT-04 contract: the X-Transport listener-based stamping strategy actually drives routing without user intervention"
    status: failed
    reason: |
      Plan 20-06 SUMMARY itself documents that TenantMessageDecorator listens on
      MessageEvent which fires from AbstractTransport::send (the LEAF transport),
      AFTER the TenantAwareTransportsDecorator's routing decision has been made.
      The AsyncCanaryTest pre-stamps X-Transport on every email rather than
      relying on the listener. This means the load-bearing claim of BOOT-04
      ("TenantMessageDecorator listens on MessageEvent and stamps X-Transport
      BEFORE Messenger serialization; multi-transport mailer config routes
      envelopes to per-tenant tenant_<slug> transports" — REQUIREMENTS.md
      BOOT-04 acceptance bullet 2) is NOT operationally true in the shipped
      code. The decorator is wired, has correct priority, and stamps headers,
      but those header writes cannot influence the routing because the routing
      already happened upstream.
    artifacts:
      - path: "src/Mailer/TenantMessageDecorator.php"
        issue: |
          Subscribes to MessageEvent at priority 100 but the event only fires
          from AbstractTransport::send (the leaf transport) — by which time
          TenantAwareTransportsDecorator has already made the routing decision.
          The stamping is a no-op for routing purposes in production code.
      - path: "tests/Integration/Mailer/AsyncCanaryTest.php"
        issue: |
          Both test methods explicitly call $email->getHeaders()->addTextHeader('X-Transport', 'tenant_acme')
          before sending. Test docblock (lines 47-55, 156-167, 281-284) and Plan
          20-06 SUMMARY "Issues Encountered" section explicitly acknowledge that
          listener-driven stamping does not work end-to-end. User code or a
          custom upstream listener is required for tenant routing to engage.
    missing:
      - "An upstream X-Transport stamping mechanism that fires BEFORE Transports::send routing — options listed in Plan 20-06 SUMMARY: decorator that wraps the mailer.mailer service, pre-Mailer::send hook, or compiler-pass-driven sender-side listener"
      - "An integration test that exercises the full sync + async path WITHOUT pre-stamping X-Transport in test code — proving the bundle ships a zero-user-intervention routing guarantee"
      - "Docs callout (UPGRADE.md or user-guide page deferred to Phase 22) that the current bundle requires the application to stamp X-Transport — until the upstream stamping is fixed"

  - truth: "DSN credentials never appear in exception traces or logs — sanitization wrapper redacts password component (REQUIREMENTS.md BOOT-04 acceptance bullet 6)"
    status: partial
    reason: |
      The sanitization wrapper exists and redacts $e->getMessage(). However, the
      Phase 20 code review (20-REVIEW.md BL-01) identifies a confirmed data-leak
      vector: TenantSanitizedTransportException inherits from
      Symfony\Component\Mailer\Exception\TransportException, which exposes
      getDebug() (returning $debug) and is reachable via the wrapped previous
      exception. The current code:
        - does NOT override or sanitize getDebug() on the wrapper
        - preserves the original TransportException as getPrevious(), giving
          callers walking the cause chain access to unsanitized credentials
      This is the documented Symfony pattern — user logger code typically writes
      ['debug' => $e->getDebug()] to a structured log. Real SMTP bridges
      populate $debug via setDebug() with SMTP transcripts that often contain
      the DSN string. The acceptance criterion as written ("credentials never
      appear in exception traces or logs") is therefore not fully met.
    artifacts:
      - path: "src/Exception/TenantSanitizedTransportException.php"
        issue: "Class is a no-body subclass — does not override setDebug/getDebug, so unsanitized debug content from the parent class API survives sanitization"
      - path: "src/Mailer/SanitizingMailerDecorator.php"
        issue: "Passes the original exception via getPrevious() — preserves the unsanitized $debug payload on the cause chain"
    missing:
      - "Override TenantSanitizedTransportException constructor to copy + sanitize the previous TransportException's getDebug() output (REVIEW.md BL-01 suggested patch)"
      - "Either drop the $previous chain link OR also sanitize the chained exception's message/debug"

  - truth: "TenantAwareTransportsDecorator refuses empty-slug X-Transport headers (defensive against malformed input)"
    status: failed
    reason: |
      REVIEW.md BL-02: when a message arrives with literal 'X-Transport: tenant_'
      (no slug after the underscore), $slug = substr(...) === ''. The code then
      forwards '' to TenantProviderInterface::findBySlug(''). The bundle has no
      positive validation for empty slug — relies on provider semantics. A
      pathological provider could return the first tenant in the table for an
      empty-slug query, silently routing the message to whatever tenant happens
      to come back. The cross-tenant guard at line 82 only catches the case
      where an active tenant is set; the no-active-tenant path (worker-pre-
      restoration, sync-context misuse) is unguarded.
    artifacts:
      - path: "src/Mailer/TenantAwareTransportsDecorator.php"
        issue: "Line 74: $slug = substr($headerValue, 7); no empty-string guard before passing to provider->findBySlug()"
    missing:
      - "Explicit empty-slug guard: if ('' === $slug) throw new \\RuntimeException('tenancy: refusing to route mail — X-Transport \"tenant_\" has empty slug')"
      - "Optionally: slug character-set validation against [a-z0-9_-]+ before provider round-trip"

deferred:
  - truth: "Documentation of the X-Transport stamping limitation in user-facing docs"
    addressed_in: "Phase 22"
    evidence: |
      Phase 22 ROADMAP success criterion 2 mentions a new user-guide page
      docs/user-guide/mailer-bootstrapper.md "with X-Transport strategy +
      async failure-mode warning + migration recipe". The warning about the
      current stamping-mechanism gap can land there. Note: this defers the
      DOCS gap, not the underlying ARCHITECTURAL gap above — the latter is
      still a blocker for the bundle's BOOT-04 contract.

human_verification: []
---

# Phase 20: Mailer Bootstrapper Verification Report

**Phase Goal:** A tenant with a `mailerDsn` configured sends mail from that DSN with the tenant's `From`/`Reply-To` headers — correct under BOTH synchronous Mailer dispatch AND Messenger-routed async dispatch.

**Verified:** 2026-05-20T07:42:07Z
**Status:** gaps_found
**Re-verification:** No — initial verification

## Goal Achievement

### Observable Truths (Roadmap Success Criteria)

| # | Truth (Roadmap SC) | Status | Evidence |
|---|---|---|---|
| 1 | Sync dispatch: `$mailer->send()` delivers via tenant A's SMTP DSN with tenant A's From header — verified by a Mailer\Test\TransportListener capture | PARTIAL | `testSyncDispatchUsesTenantDsn` passes (AsyncCanaryTest.php) — but test must PRE-STAMP X-Transport on the email. The routing logic is correct; the stamping mechanism does not engage in production without user intervention. See Architectural Gap below. |
| 2 | Async dispatch (the canary): worker-side capture asserts tenant A's SMTP DSN was used — NOT the landlord DSN | PARTIAL | `testAsyncDispatchInWorkerUsesTenantDsnNotLandlord` passes — same caveat as #1. The PhpSerializer-round-trip + worker restoration is verified, but X-Transport is pre-stamped in test code (line 284). |
| 3 | Container compilation fails when bootstrapper enabled but no transport strategy configured; fails when async without `x_transport` strategy | VERIFIED | `MailerTransportContractPass` exists at `src/DependencyInjection/Compiler/MailerTransportContractPass.php`; throws 3 distinct LogicExceptions (missing param / invalid value / async-without-strategy). Three integration tests in `ContainerCompilationTest` cover the cases. |
| 4 | User's custom Tenant entity (without `mailerDsn`) breaks compilation with clear migration path | VERIFIED | `TenantInterface` declares 3 new abstract methods (lines 20-24); `TenantMailerConfigTrait` ships in `src/Mailer/`; `UPGRADE.md` §"0.2 to 0.3" documents trait + manual migration paths (6 mentions of TenantMailerConfigTrait, 1 ALTER TABLE snippet). |
| 5 | Thrown `TransportException` during send does NOT leak the DSN's password component in its message or trace | PARTIAL | Message is redacted via `DsnSanitizer::redact` in `SanitizingMailerDecorator::send`. BUT — confirmed by REVIEW.md BL-01 — `getDebug()` on the parent class and chained previous exception's debug output remain unsanitized. The acceptance criterion uses the word "trace" which encompasses getDebug; this is therefore a partial fail. |
| 6 | After `TenantContextCleared` event, the per-tenant transport cache is cleared — verified by long-running-worker simulation that processes 100 distinct tenants without unbounded socket growth | VERIFIED | `TenantContextClearedListener` exists; `LongRunningWorkerSimulationTest` has 3 methods including `testCacheSizeRemainsBoundedAcross100Tenants`. Full suite (519 tests / 1946 assertions) passes including these. |

**Score:** 3/6 fully verified, 2/6 partial (Truths 1+2 share a single root cause; Truth 5 is REVIEW BL-01), 1/6 implementation-present-but-design-broken.

### Required Artifacts

| Artifact | Expected | Status | Details |
|---|---|---|---|
| `src/Bootstrapper/MailerBootstrapper.php` | TenantBootstrapperInterface impl, joins chain | VERIFIED | Final class, no-op boot(), clear() flushes LRU cache via nullable inject. |
| `src/Mailer/DsnSanitizer.php` | Static `redact()` helper with REDACTION_REGEX constant | VERIFIED | Final class; `REDACTION_REGEX = '/(:[\/]{0,2}[^:]+:)[^@]+(@)/'`. (REVIEW WR-07 notes regex could over-redact non-DSN text — info-level.) |
| `src/Mailer/LruTransportCache.php` | Bounded LRU with stop()-on-eviction | VERIFIED | Final class, default size 32, `array_key_first`-based eviction, `method_exists('stop')` guarded teardown. REVIEW WR-06 notes a stop()-throws-aborts-clear hazard. |
| `src/Mailer/SanitizingMailerDecorator.php` | MailerInterface decorator wrapping TransportException → TenantSanitizedTransportException | STUB (functional but incomplete) | Catches `TransportExceptionInterface` only — REVIEW WR-01 notes bridge-factory throws and `UnsupportedSchemeException` bypass redaction. Functional but the contract "no credentials in any thrown exception" is not airtight. |
| `src/Exception/TenantSanitizedTransportException.php` | Extends TransportException, preserves catch contract | STUB (incomplete contract) | No-body subclass — does NOT override getDebug; REVIEW BL-01 is a confirmed data-leak path. |
| `src/Mailer/TenantAwareTransportsDecorator.php` | Decorates mailer.transports; intercepts tenant_<slug> | WIRED but with vulnerability | Final class, correctly decorates `mailer.transports`, accepts 5th-arg event_dispatcher, has Closure factory for testability. BUT — REVIEW BL-02: empty-slug guard absent. |
| `src/Mailer/TenantMessageDecorator.php` | MessageEvent listener stamping X-Transport + From/Reply-To at priority 100 | WIRED, but architecturally broken for routing | Final class, correctly subscribed at priority 100, idempotency guards present. The fundamental design flaw (listener fires AFTER routing) is documented in Plan 20-06 SUMMARY "Issues Encountered" section. |
| `src/DependencyInjection/Compiler/MailerTransportContractPass.php` | Compile-time guard rejecting missing strategy + async-without-x_transport | VERIFIED | Final class; reads `tenancy.mailer.async` param + walks `getExtensionConfig('framework')` for SendEmailMessage routing. REVIEW WR-09 notes env-var-driven routing escapes detection. |
| `src/Mailer/TenantContextClearedListener.php` | Subscribes to TenantContextCleared, calls cache->clear() | VERIFIED | Final class; subscribes `TenantContextCleared::class => 'onContextCleared'`; passes integration test in `LongRunningWorkerSimulationTest::testListenerActuallyWiredIntoEventDispatcher`. |
| `src/Mailer/TenantMailerConfigTrait.php` | Trait providing default getMailerDsn/From/ReplyTo + ORM columns | VERIFIED | Trait with 3 `#[ORM\Column]` properties + 3 getters + 3 fluent setters returning static. |
| `src/TenantInterface.php` | Adds 3 new abstract method signatures | VERIFIED | Lines 20-24: `getMailerDsn()`, `getMailerFrom()`, `getMailerReplyTo()` all `: ?string`. |
| `src/Entity/Tenant.php` | Adds 3 nullable string columns + getters/setters (inlined, NOT via trait) | VERIFIED | Lines 32-42 (columns) + 102-134 (6 methods). Comment notes user-entity equivalence via trait. Note REVIEW IN-03: simultaneous trait-use + inheritance causes a PHP fatal error — documented hazard not currently in UPGRADE.md. |
| `src/Profiler/TenantDataCollector.php` (modified) | Adds mailer subsection with 10 scalar keys | VERIFIED | Constructor accepts `?LruTransportCache $cache` + `?string $mailerAsync`; `collectMailerState` builds the 10-key array; DSN goes through `DsnSanitizer::redact`; defense-in-depth tripwire present. |
| `src/Resources/views/Collector/tenant.html.twig` (modified) | Mailer subsection in panel | VERIFIED | `<h3>Mailer</h3>` block guarded by `collector.data.mailer is defined`; renders badge + 4 cache metrics + strategy/async + 3-row from/reply-to/redacted-DSN table. |
| `src/Command/Install/Step/MailerSetupStep.php` | --with-mailer step: AST trait insert + migration scaffold + tenancy.yaml append | VERIFIED | Final class with 5 public method body; nikic/php-parser AST detection; atomic .bak write + lint restore. REVIEW WR-02 (migration class name not picked up by default Doctrine Migrations finder regex), WR-03 (re-runs not idempotent), WR-04 (hardcoded table name), WR-05 (Windows path comparison fails) are correctness warnings on the install step. |
| `src/Command/TenancyInstallCommand.php` (modified) | --with-mailer option delegating to MailerSetupStep | VERIFIED | Option declared (line 54); delegation method `runMailerSetupStep()` (line 173); gracefully no-ops with warning if step service is null. |
| `config/services.php` (modified) | 6 mailer services + install step registered inside interface_exists block | VERIFIED | 8 service registrations: lru_cache, bootstrapper (priority -20), message_decorator, transports_decorator (with 5-arg ctor), sanitizing_decorator, install_step, context_cleared_listener. All inside `if (interface_exists(MailerInterface::class))`. |
| `UPGRADE.md` (modified) | §0.2→0.3 with BC break + trait recipe + SQL | VERIFIED | Header `## 0.2 to 0.3`; two migration paths (A/B) named; ALTER TABLE snippet present; 6 references to TenantMailerConfigTrait. |
| `composer.json` (modified) | symfony/mailer + symfony/mime in require-dev only | VERIFIED | 2 mentions of symfony/mailer in composer.json (suggest + require-dev); 5 mentions in composer.lock (lock entries + transitive). Not in `require`. |
| 9 plan SUMMARY.md files | One SUMMARY per plan | VERIFIED | 9 SUMMARY files match 9 PLAN files. |

### Key Link Verification

| From | To | Via | Status | Details |
|---|---|---|---|---|
| `config/services.php` `tenancy.mailer.transports_decorator` | `mailer.transports` | `->decorate('mailer.transports')` | WIRED | Confirmed in config/services.php line 182. |
| `config/services.php` `tenancy.mailer.sanitizing_decorator` | `mailer` | `->decorate('mailer')` | WIRED | Confirmed in config/services.php line 195. |
| `config/services.php` `tenancy.mailer.transports_decorator` | `event_dispatcher` | 5th positional arg | WIRED | Per Plan 20-04 acceptance criterion (decorator passes dispatcher to Transport::fromDsn). |
| `config/services.php` `tenancy.mailer.bootstrapper` | bootstrapper chain | `tag('tenancy.bootstrapper', ['priority' => -20])` | WIRED | Per Plan 20-04. |
| `MailerTransportContractPass::X_TRANSPORT_SERVICE` | `config/services.php` `tenancy.mailer.message_decorator` | service ID equality | WIRED | Both files contain the literal string `tenancy.mailer.message_decorator`. |
| `src/TenancyBundle.php` build() | `MailerTransportContractPass` | `interface_exists(MailerInterface::class)` guard + `addCompilerPass` | WIRED | Line 251. |
| `TenantContextClearedListener` | `TenantContextCleared` event | `getSubscribedEvents` + autoconfigure tag | WIRED | LongRunningWorkerSimulationTest::testListenerActuallyWiredIntoEventDispatcher proves the listener fires through the real container's dispatcher. |
| `TenantMessageDecorator` | `MessageEvent` | listener at priority 100 | WIRED but ineffectual for routing | The decorator IS subscribed and DOES execute, but the firing point of MessageEvent in Symfony Mailer 7.x/8.x is AbstractTransport::send — AFTER `TenantAwareTransportsDecorator` has already routed. So the listener's header writes have no effect on routing. See gap #1 in frontmatter. |
| `MailerSetupStep` | `nikic/php-parser` | `ParserFactory::createForNewestSupportedVersion()` | WIRED | Per Plan 20-08 acceptance criteria. |

### Data-Flow Trace (Level 4)

Mailer dispatch data flow under sync path (`testSyncDispatchUsesTenantDsn`):

| Artifact | Data Variable | Source | Produces Real Data | Status |
|---|---|---|---|---|
| Tenant entity `mailerDsn` | `$mailerDsn` | tenant repository / StubTenantProvider in tests | Yes (synthetic test DSN, but real getter chain) | FLOWING |
| LruTransportCache | `$cache[$slug]` | `TenantAwareTransportsDecorator::buildAndCache` → SpyTransportFactory closure | Yes (SpyTransport instance with DSN) | FLOWING |
| Sent message | spy `$sends[0]['dsn']` | SpyTransport::send records | Yes (recorded tenant DSN) | FLOWING |
| **X-Transport header** | **`$message->getHeaders()->get('X-Transport')`** | **Test pre-stamps it (line 196 of AsyncCanaryTest); production code path has no upstream stamper that runs before routing** | **NO — production data flow is broken** | **HOLLOW_PROP** |

The last row is the data-flow gap. The TenantMessageDecorator IS wired and DOES run, but it fires at a point in the call graph where its writes cannot influence routing. Without an upstream stamper, real-world `$mailer->send($email)` calls (without user-written X-Transport stamping) fall through to the landlord transport because the decorator sees no `X-Transport` header. This is verified by inspection of the AsyncCanaryTest setup pre-stamping the header.

### Requirements Coverage

| Requirement | Source Plan | Description | Status | Evidence |
|---|---|---|---|---|
| BOOT-04 (a) | 20-03 | `MailerBootstrapper implements TenantBootstrapperInterface`; optional dep guarded | SATISFIED | src/Bootstrapper/MailerBootstrapper.php + `interface_exists` guard in config/services.php and TenancyBundle.php. |
| BOOT-04 (b) | 20-03 | `TenantMessageDecorator` listens on MessageEvent stamping X-Transport BEFORE Messenger serialization | **BLOCKED** | Listener exists but fires AFTER routing in Symfony Mailer 7.x/8.x topology — see gap #1. The acceptance criterion's "BEFORE Messenger serialization" wording is not satisfied by the current implementation in the production code path. |
| BOOT-04 (c) | 20-01 | `TenantMailerConfigTrait` ships as default impl; `Tenant` gains mailerDsn column; landlord migration documented | SATISFIED | Trait + entity + UPGRADE.md + MailerSetupStep migration scaffold. |
| BOOT-04 (d) | 20-01 | `TenantInterface` adds `getMailerDsn(): ?string` (BC break — UPGRADE documents trait migration) | SATISFIED | TenantInterface lines 20-24; UPGRADE.md §0.2 to 0.3. |
| BOOT-04 (e) | 20-04 | `MailerTransportContractPass` rejects "mailer bootstrapper enabled + no transport strategy"; rejects async without x_transport | SATISFIED | Compiler pass implemented; 3 LogicException paths; 3 integration tests. |
| BOOT-04 (f) | 20-02 | DSN credentials never appear in exception traces or logs — sanitization wrapper redacts password | **PARTIALLY SATISFIED** | Message is sanitized; `getDebug()` on the parent class + chained previous exception preserve unsanitized debug content per REVIEW BL-01. |
| BOOT-04 (g) | 20-06 | Async canary test — dispatch in tenant A's HTTP context, run worker in clean context, assert recorded SMTP DSN matches tenant A | **SATISFIED WITH CAVEAT** | Test passes, but pre-stamps X-Transport so it exercises only the downstream half of the contract. The upstream stamping mechanism it relies on is not in production code (see gap #1). |
| BOOT-04 (h) | 20-05 | Transport cache cleared on `TenantContextCleared` event to prevent SMTP socket leaks in long-running workers | SATISFIED | TenantContextClearedListener + LongRunningWorkerSimulationTest (3 methods, including 100-tenant bounded-cache proof). |

### Behavioral Spot-Checks

| Behavior | Command | Result | Status |
|---|---|---|---|
| Full PHPUnit suite passes | `vendor/bin/phpunit --no-progress` | 519 tests, 1946 assertions, OK | PASS |
| Mailer-specific test suite passes | `vendor/bin/phpunit tests/Unit/Mailer/ tests/Integration/Mailer/` | All passing (counted in 519) | PASS |
| Async canary specifically passes | `vendor/bin/phpunit tests/Integration/Mailer/AsyncCanaryTest.php` | 2/2 passing (counted in 519) | PASS |
| TenantInterface declares 3 mailer methods | `grep -c 'public function getMailer' src/TenantInterface.php` | 3 | PASS |
| MailerTransportContractPass registered in TenancyBundle | `grep -c 'addCompilerPass.*MailerTransportContractPass' src/TenancyBundle.php` | 1 | PASS |
| All 8 mailer service IDs registered | `grep -c "'tenancy.mailer\." config/services.php` | 14 occurrences (set + ref) — confirms 8 set definitions | PASS |
| symfony/mailer in require-dev only | `grep -c '"symfony/mailer"' composer.json` | 2 (require-dev + suggest); 0 in `require` | PASS |
| UPGRADE.md documents BC break | `grep -c '## 0\.2 to 0\.3' UPGRADE.md` | 1 | PASS |

### Anti-Patterns Found

| File | Line | Pattern | Severity | Impact |
|---|---|---|---|---|
| `src/Exception/TenantSanitizedTransportException.php` | 17-21 | Empty subclass relying on parent contract; no override of getDebug — REVIEW BL-01 | BLOCKER | DSN credentials leak via TransportException::getDebug() on the chained previous exception |
| `src/Mailer/TenantAwareTransportsDecorator.php` | 74 | `substr($headerValue, 7)` without empty-slug guard — REVIEW BL-02 | BLOCKER | `tenant_` (literal) routes to provider->findBySlug('') — undefined behavior across user providers |
| `src/Mailer/TenantMessageDecorator.php` | 56-58 | Listener stamps X-Transport at a firing point (MessageEvent from AbstractTransport::send) that cannot influence routing | BLOCKER (architectural) | Production code path falls back to landlord transport when user does not stamp X-Transport in app code |
| `src/Mailer/SanitizingMailerDecorator.php` | 30-38 | Catches only `TransportExceptionInterface` — REVIEW WR-01 | WARNING | Bridge-factory throws (UnsupportedSchemeException, InvalidArgumentException) bypass redaction |
| `src/Mailer/LruTransportCache.php` | 68-101 | clear() throws on `stop()` exception leaving cache populated — REVIEW WR-06 | WARNING | Partial-clear leaves cache in inconsistent state with stopped transports |
| `src/Mailer/DsnSanitizer.php` | 19 | Regex `[\/]{0,2}` matches free-text colons too aggressively — REVIEW WR-07 | INFO | Over-redacts non-DSN error text; not a security regression |
| `src/Command/Install/Step/MailerSetupStep.php` | 238-242 | Migration class name `VersionYYYYMMDDHHMMSS_AddTenantMailerColumns` (underscore) — REVIEW WR-02 | WARNING | Default Doctrine Migrations finder regex may skip the migration file silently |
| `src/Command/Install/Step/MailerSetupStep.php` | 222-227 | Hardcoded `ALTER TABLE tenancy_tenants` — REVIEW WR-04 | WARNING | Wrong table name for user-supplied Tenant entities mapped elsewhere |
| `src/Command/Install/Step/MailerSetupStep.php` | 220-258 | Re-runs produce a new migration file every time — REVIEW WR-03 | WARNING | Second `tenancy:install --with-mailer` produces a migration that fails on `migrate` |
| `src/Command/TenancyInstallCommand.php` | 241-250 | `str_starts_with($fileReal, rtrim($projectDirReal, '/').'/')` — REVIEW WR-05 | INFO | Path-prefix check fails on Windows (backslash separator) — bundle CI is Linux/macOS-only |
| `src/DependencyInjection/Compiler/MailerTransportContractPass.php` | 71-105 | `getExtensionConfig('framework')` reads unprocessed config — REVIEW WR-09 | WARNING | Env-var-driven `framework.messenger.routing` keys escape detection; `'auto'` mode silently disables the check |
| `src/Profiler/TenantDataCollector.php` | 157 | Tripwire regex `'/:(?!\\/\\/)(?!\\*\\*\\*@)[^:@\\/]+@/'` — REVIEW IN-04 | INFO | Defensive but could be strengthened with positive assertion |
| `src/Entity/Tenant.php` (vs `TenantMailerConfigTrait`) | 35-42 vs 21-28 | Both inline and trait define the same 3 properties — REVIEW IN-03 | INFO | Users extending bundle Tenant + using trait get PHP fatal "trait property conflicts with parent" — not currently called out in UPGRADE.md |

**Categorized totals:** 3 BLOCKER (BL-01 + BL-02 from REVIEW; the X-Transport architectural gap discovered in Plan 20-06), 7 WARNING, 3 INFO.

### Human Verification Required

None automatically required — all verification is observable via code inspection + test runs.

### Gaps Summary

Phase 20 delivers a large, well-structured implementation of the per-tenant Mailer bootstrapper: 7 new src/Mailer files, 1 new exception, 1 new bootstrapper, 1 new compiler pass, 1 new install step, profiler integration, comprehensive test infrastructure (SpyTransport, SpyTransportRegistry, MailerTestKernel, 2 compiler passes), and a 100-tenant bounded-cache integration proof. The full PHPUnit suite (519 tests / 1946 assertions) passes. Plans 00-08 each have a matching SUMMARY. The compile-time guard (MailerTransportContractPass) and the LRU cache + cleanup listener (Plans 05) are model implementations.

However, three blocker-class issues prevent the phase from satisfying its stated goal as written in ROADMAP success criteria and REQUIREMENTS.md BOOT-04:

**1. Architectural gap (most consequential).** Plan 20-06's own SUMMARY documents the discovery that `TenantMessageDecorator` listens on `MessageEvent`, which fires from `AbstractTransport::send` — AFTER `TenantAwareTransportsDecorator` has already routed the message. The listener executes; its header writes land on the message; but they cannot drive a routing decision that already happened upstream. The AsyncCanaryTest pre-stamps `X-Transport: tenant_acme` in test code to make the test pass. In a production app, a user calling `$mailer->send($email)` without stamping X-Transport will route via the landlord transport even when a tenant is active in the context. This means BOOT-04 acceptance bullet (b) — "TenantMessageDecorator listens on MessageEvent and stamps X-Transport BEFORE Messenger serialization" — is not satisfied by production code. The Plan 20-06 SUMMARY flagged this for a follow-up plan: "A future plan in Phase 20 (or a Phase 21 mailer-hardening plan) should add an upstream stamping mechanism that fires BEFORE Transports::send".

This finding should NOT be treated as info-level. The bundle's headline differentiator is "tenant A's mail goes via tenant A's DSN with zero boilerplate." The current code requires user-written or framework-extension boilerplate to stamp X-Transport on every outbound message. The phase's goal as written in ROADMAP is therefore not met.

**Recommendation:** Add a follow-up plan in Phase 20 (or a Phase 20.5) that wraps the `mailer.mailer` service with a decorator that stamps X-Transport before delegating to the inner mailer. The decorator would call `TenantContext::getTenant()`, read its slug + mailerDsn, and stamp the header before the message enters the bus / transports chain. This is the canonical position for the stamping per Symfony's design — and matches what the BOOT-04 acceptance criterion seemed to envision when it said "BEFORE Messenger serialization."

**2. REVIEW BL-01: DSN leak via TransportException::getDebug().** TenantSanitizedTransportException inherits getDebug() from the parent class and preserves the original exception via getPrevious(). Real SMTP bridges populate $debug with transcripts that include the DSN. Acceptance criterion 5 ("DSN credentials never appear in exception traces or logs") is not airtight.

**Fix (REVIEW.md suggested patch):** Override the constructor to sanitize $previous->getDebug() at construction time, OR drop the previous chain link entirely.

**3. REVIEW BL-02: Empty-slug routing vector.** `X-Transport: tenant_` (literal, no slug) flows `''` to `provider->findBySlug('')` with no positive bundle-level validation. A pathological provider implementation could return the wrong tenant.

**Fix (REVIEW.md suggested patch):** Add explicit empty-slug guard in `TenantAwareTransportsDecorator::send` before the provider call.

**4. Plan↔Summary↔code drift on the architectural gap.** The 9 PLAN files describe the X-Transport listener as the routing-driver. The Plan 20-06 SUMMARY documents the gap as "Issues Encountered" but the verification frontmatter does not surface it as a phase blocker. The 20-VALIDATION.md and 20-PATTERNS.md files (read for context) still describe the listener as the strategy. There is a structural discrepancy between the plan's documented strategy and what shipped.

Pragmatic note: the phase still represents 90%+ of the work needed for BOOT-04. The bootstrapper, LRU cache, sanitization layer, compile-time guard, profiler tab, install command, BC break + migration, and all test infrastructure are in place. What is missing is the small upstream-stamping decorator that completes the contract. The blockers above are addressable in a single follow-up plan of maybe 100-150 lines of code plus tests.

---

_Verified: 2026-05-20T07:42:07Z_
_Verifier: Claude (gsd-verifier)_
