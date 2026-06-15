---
phase: 27-async-shared-entities
verified: 2026-06-15T12:30:00Z
status: passed
score: 10/10 must-haves verified
overrides_applied: 0
re_verification: false
human_verification: []
---

# Phase 27: Async Shared Entities Verification Report

**Phase Goal:** An opt-in `tenancy.shared.async: true` mode where `SharedEntitySyncSubscriber::postFlush()` dispatches a lightweight `SharedEntityChangedMessage` (class + identifier + change-type, NOT the full entity) instead of writing synchronously; a Messenger handler re-fetches the LATEST landlord state and fans out to ALL tenants via the existing `SharedEntityCopier` (best-effort attempt-all → throw-to-retry), so the landlord HTTP response returns immediately. Sync stays the default (DEC-SHARE-01). Ships a compile-time guard (async:true + no Messenger → build-time `LogicException`) and an AsyncCanaryTest-style round-trip integration test.
**Verified:** 2026-06-15T12:30:00Z
**Status:** passed
**Re-verification:** No — initial verification

---

## Goal Achievement

### Observable Truths

| # | Truth | Status | Evidence |
|---|-------|--------|----------|
| 1 | Async mode is opt-in via `tenancy.shared.async: true`; default remains sync (DEC-SHARE-01) | VERIFIED | `TenancyBundle.php` line 128: `->booleanNode('async')->defaultFalse()->end()` inside `arrayNode('shared')`. Default is false. Sync path in `SharedEntitySyncSubscriber::postFlush()` runs when `$this->bus === null`. |
| 2 | `SharedEntityChangedMessage` carries class + identifier + changeType scalars — NOT full entity | VERIFIED | `src/Message/SharedEntityChangedMessage.php`: 3 `public readonly` promoted properties (`string $entityClass`, `array $identifier`, `string $changeType`). No Doctrine import, no `object` property. PHPDoc on class confirms dead-letter safety (T-27-01-DLQ). `SharedEntityChangedMessageTest::testCarriesOnlyScalars` asserts no property is an object. |
| 3 | Worker re-fetches LATEST landlord state at handle time (D-05) — not a dispatch-time snapshot | VERIFIED | `SharedEntityChangedMessageHandler::__invoke()` lines 90-95: `$this->landlordEm->clear()` (stale-read mitigation) followed by `$this->landlordEm->find($class, $identifier)` re-fetch. `clear-before-find` ordering confirmed by grep. Message carries only IDs — no entity snapshot is possible. |
| 4 | Compile-time guard throws `\LogicException` at build time when async:true + Messenger absent (D-06) | VERIFIED | `src/DependencyInjection/Compiler/SharedAsyncContractPass.php`: 3-stage guard — `hasParameter` early-return (line 33), `getParameter false` early-return (line 38), `interface_exists(MessageBusInterface)` throw (lines 44-46). Structural proof: `interface_exists(MessageBusInterface)` on line 44 immediately precedes `throw new \LogicException` on line 45. Registered in `TenancyBundle::build()` line 385 inside `interface_exists(EntityManagerInterface)` block alongside `SharedEntityMutualExclusionPass`. |
| 5 | Integration test proves async mode survives Messenger transport round-trip | VERIFIED | `tests/Integration/SharedEntity/SharedEntityAsyncCanaryTest.php`: 6 tests covering SHARE-03-j/-d/-f/-e/-g/-h and D-01. `testAsyncRoundTripCanary` dispatches via `messenger.bus.default` (not directly calling the handler) routed through `sync://` transport, then asserts both tenant DBs have the row. `SharedEntityAsyncTestKernel` boots with `transports: ['sync' => 'sync://']` and `routing: [SharedEntityChangedMessage::class => 'sync']`. |
| 6 | `tenancy.shared.async` parameter set UNCONDITIONALLY (not inside database.enabled block) | VERIFIED | `TenancyBundle.php` lines 185-199: `->set('tenancy.shared.async', $sharedAsync)` is the last item in the unconditional `$container->parameters()->set(...)` chain. The `if ($databaseConfig['enabled'] ?? false)` block begins at line 227, which is after line 199. Parameter is always set regardless of database mode. |
| 7 | Async dispatch branch clears TenantContext before dispatch so TenantSendingMiddleware does not stamp the fan-to-all-tenants message (D-01) | VERIFIED | `SharedEntitySyncSubscriber::postFlush()` lines 177-179: `if (null !== $previousTenant) { $this->tenantContext->clear(); }` before the `foreach ($changes as $change)` dispatch loop. `finally` block restores previous tenant. Unit-level proof: `testDispatchClearsTenantContextToAvoidStamp` uses an anonymous bus that records `$ctx->hasTenant()` at dispatch time. Integration-level proof: `testWrongTenantIsolationWithActiveDispatchTenant` sets `$ctx->setTenant($tenantA)` BEFORE flush/dispatch, then asserts ALL tenants (including tenantB) received the row. |
| 8 | Handler attempts all tenants best-effort, throws `SharedEntityAsyncFanOutException` if any failed (D-02) | VERIFIED | `SharedEntityChangedMessageHandler::__invoke()` lines 120-159: per-tenant `catch (\Throwable)` loop accumulates `$failures[]`, calls `$this->registry->resetManager('tenant')`, continues. After loop: `if ([] !== $failures) throw new SharedEntityAsyncFanOutException(...)`. Integration proof: `testHandlerThrowsOnTenantFailure` uses DROP TABLE on one tenant's SQLite DB, asserts `SharedEntityAsyncFanOutException` thrown AND healthy tenant received the change. |
| 9 | A vanished landlord row at handle time (insert/update with null re-fetch) propagates a tenant-side delete (D-04) | VERIFIED | `SharedEntityChangedMessageHandler::__invoke()` lines 101-108: `if (null === $landlordEntity && 'delete' !== $changeType) { $this->logger->warning('tenancy.shared_entity_async_vanished_row', ...); $effectiveType = 'delete'; }`. The upsert branch uses `assert(null !== $landlordEntity)` — guaranteed non-null by D-04 control flow. Integration proof: `testVanishedRowPropagatesToTenantDelete` deletes landlord row via DQL bypass before dispatching 'insert' message, asserts tenant copies are null after dispatch. |
| 10 | All-tenant fan-out proven with active dispatch-time tenant (D-01 stamp-clearing integration proof, SHARE-03-f) | VERIFIED | `testHandlerFansOutToAllTenants` asserts both tenant_a and tenant_b DBs received the row (count >= 2). `testWrongTenantIsolationWithActiveDispatchTenant` additionally sets an active tenant BEFORE the flush and asserts ALL tenants still received the row — proving the subscriber cleared context before dispatch. |

**Score:** 10/10 truths verified

---

### Required Artifacts

| Artifact | Expected | Status | Details |
|----------|----------|--------|---------|
| `src/Message/SharedEntityChangedMessage.php` | Lightweight async message value object (entityClass, identifier, changeType) | VERIFIED | `final class SharedEntityChangedMessage` with 3 promoted `public readonly` properties. No entity objects. PHP-serializable. 35 lines. |
| `src/Exception/SharedEntityAsyncFanOutException.php` | Retryable aggregate exception (D-02) | VERIFIED | `final class SharedEntityAsyncFanOutException extends \RuntimeException`. No custom constructor. Explicit non-UnrecoverableExceptionInterface design documented. |
| `src/DependencyInjection/Compiler/SharedAsyncContractPass.php` | Compile-time guard (D-06) | VERIFIED | `final class SharedAsyncContractPass implements CompilerPassInterface`. 3-stage guard with const `ASYNC_PARAM = 'tenancy.shared.async'`. `throw new \LogicException` inside `!interface_exists(MessageBusInterface)` branch. |
| `src/MessageHandler/SharedEntityChangedMessageHandler.php` | Per-tenant async fan-out handler | VERIFIED | `public function __invoke(SharedEntityChangedMessage $message): void`. Security gate, stale-read mitigation, D-04 vanished-row, per-tenant loop, D-02 throw. No `#[AsMessageHandler]`. No `\stdClass`. |
| `src/Shared/SharedEntityCopierInterface.php` | Added `deleteRow()` contract (OQ-1) | VERIFIED | `public function deleteRow(EntityManagerInterface $tenantEm, string $class, array $capturedIds): void;` added at line 72. PHPDoc documents idempotency and `syncInProgress` flag. |
| `src/Shared/SharedEntityCopier.php` | Implemented `deleteRow()` + refactored `applyRow()` delete branch | VERIFIED | `deleteRow()` at line 138. Sets `$this->syncInProgress = true` in try/finally around flush. WR-03 guard for empty `$capturedIds`. `applyRow()` delete branch delegates to `deleteRow()`. Exactly 1 `$tenantEm->remove(` in the file. |
| `src/Subscriber/SharedEntitySyncSubscriber.php` | Added nullable bus + async dispatch branch | VERIFIED | `?MessageBusInterface $bus = null` as 7th promoted readonly constructor param. Async branch in `postFlush()` at line 170 guarded by `if (null !== $this->bus)`. Clear-before-dispatch (Pitfall 1). |
| `src/TenancyBundle.php` | Config node, parameter, handler registration, bus wiring | VERIFIED | `booleanNode('async')->defaultFalse()` at line 128. Parameter set unconditionally at line 199. Handler registered at lines 339-348 with `messenger.message_handler` tag. Bus wired via `setArgument('$bus', new Reference(...))` at line 335. `SharedAsyncContractPass` registered at line 385. |
| `tests/Integration/SharedEntity/Support/SharedEntityAsyncTestKernel.php` | Kernel with sync:// transport + shared.async:true | VERIFIED | `framework.messenger` block with `sync://` transport, `SharedEntityChangedMessage::class => 'sync'` routing. `tenancy.shared.async: true`. Distinct landlord DB filename (`tenancy_shared_async_test_landlord.db`). `MakeSharedEntityAsyncServicesPublicPass` + `ReplaceWithStubMultiTenantProviderPass` in `build()`. |
| `tests/Integration/SharedEntity/Support/MakeSharedEntityAsyncServicesPublicPass.php` | Exposes handler + bus + EMs for test inspection | VERIFIED | `$ids` includes `'tenancy.shared_entity_changed_handler'` and `'messenger.bus.default'` plus all sync-mode IDs. `hasDefinition`/`hasAlias` tolerance guard present. |
| `tests/Integration/SharedEntity/SharedEntityAsyncCanaryTest.php` | SHARE-03 round-trip acceptance test | VERIFIED | 6 tests: `testAsyncRoundTripCanary`, `testHandlerFansOutToAllTenants`, `testWrongTenantIsolationWithActiveDispatchTenant`, `testVanishedRowPropagatesToTenantDelete`, `testHandlerThrowsOnTenantFailure`, `testHandlerIdempotentOnRetry`. Dispatches via `messenger.bus.default`, not the handler directly. |
| `tests/Unit/Message/SharedEntityChangedMessageTest.php` | Scalar discipline + serialize round-trip (SHARE-03-c) | VERIFIED | `testCarriesOnlyScalars` asserts no property is an object. `testSurvivesSerializeRoundTrip` asserts all 3 properties survive `unserialize(serialize($msg))`. |
| `tests/Unit/DependencyInjection/Compiler/SharedAsyncContractPassTest.php` | Guard unit tests (SHARE-03-i) | VERIFIED | 4 tests: no-throw on absent parameter, no-throw on async=false, throw on async=true+Messenger absent (skip-guarded per MailerTransportContractPassTest precedent), no-throw on async=true+Messenger present. Structural SHARE-03-i proof provided by code inspection (line 44-45 in SharedAsyncContractPass.php). |
| `tests/Unit/Subscriber/SharedEntitySyncSubscriberAsyncTest.php` | Subscriber async branch unit tests (SHARE-03-a/-b + D-01 + D-04) | VERIFIED | 4 tests: `testPostFlushDispatchesWhenAsyncEnabled` (SHARE-03-a), `testPostFlushUsesSyncFanOutWhenAsyncDisabled` (SHARE-03-b), `testDispatchClearsTenantContextToAvoidStamp` (D-01), `testDeleteDispatchUsesPreCapturedIds` (D-04). |

---

### Key Link Verification

| From | To | Via | Status | Details |
|------|----|-----|--------|---------|
| `SharedEntitySyncSubscriber.postFlush()` | `MessageBusInterface::dispatch(SharedEntityChangedMessage)` | async branch when `$this->bus` non-null | WIRED | Line 191: `$this->bus->dispatch(new SharedEntityChangedMessage($entity::class, $ids, $type))`. Bus is a promoted readonly property. |
| `SharedEntityChangedMessageHandler` | tenant EMs via `SharedEntityCopier` | per-tenant `switchToTenant()` loop calling `copier->applyRow()/deleteRow()` | WIRED | Lines 130-136: `$this->copier->deleteRow($tenantEm, $class, $identifier)` and `$this->copier->applyRow($this->landlordEm, $tenantEm, $landlordEntity, $effectiveType, null)`. No `\stdClass` anywhere in handler. |
| `TenancyBundle::loadExtension()` | `tenancy.shared_entity_changed_handler` | `messenger.message_handler` tag inside `interface_exists(MessageBusInterface)` block | WIRED | Lines 339-348: `$services->set('tenancy.shared_entity_changed_handler', ...)` with `->tag('messenger.message_handler', ['handles' => SharedEntityChangedMessage::class])`. Gated on `interface_exists(MessageBusInterface)` at line 330. |
| `TenancyBundle::loadExtension()` | subscriber `$bus` arg | `setArgument('$bus', new Reference('messenger.bus.default'))` | WIRED | Line 335: `$builder->getDefinition('tenancy.shared_entity_sync_subscriber')->setArgument('$bus', new Reference('messenger.bus.default'))`. Named argument (not positional). |
| `SharedEntityAsyncTestKernel` | `framework.messenger.routing` | `SharedEntityChangedMessage::class => 'sync'` | WIRED | Kernel lines 76-79: `'routing' => [SharedEntityChangedMessage::class => 'sync']` with `sync://` transport. |
| `TenancyBundle::build()` | `SharedAsyncContractPass` | `addCompilerPass` inside `interface_exists(EntityManagerInterface)` block | WIRED | Line 385: `$container->addCompilerPass(new SharedAsyncContractPass())` inside the DoctrineORM-gated block alongside `SharedEntityMutualExclusionPass`. |

---

### Data-Flow Trace (Level 4)

| Artifact | Data Variable | Source | Produces Real Data | Status |
|----------|---------------|--------|--------------------|--------|
| `SharedEntityChangedMessageHandler` | `$landlordEntity` | `$this->landlordEm->find($class, $identifier)` after `$this->landlordEm->clear()` | Yes — live DB query via Doctrine EM re-fetch | FLOWING |
| `SharedEntitySyncSubscriber.postFlush()` | `$changes` (async branch) | `$this->pendingChanges` buffer populated in `onFlush()` from real UoW entity arrays | Yes — populated from Doctrine UnitOfWork `getScheduledEntityInsertions/Updates/Deletions()` | FLOWING |
| `SharedEntityAsyncCanaryTest` | `$tenantPlan` | `$tenantEm->find(TestPlan::class, $planId)` on each tenant EM via real SQLite DBs | Yes — real SQLite file-based DB, handler actually writes rows | FLOWING |

---

### Behavioral Spot-Checks

Step 7b — runnable checks:

| Behavior | Evidence | Status |
|----------|----------|--------|
| Handler does not contain `\stdClass` placeholder | `grep -c 'new \stdClass()' src/MessageHandler/SharedEntityChangedMessageHandler.php` returns 0 | PASS |
| `clear()` precedes `find()` in handler (Pitfall 3) | grep for `->clear()` then `->find(` finds correct ordering in file | PASS |
| `tenancy.shared.async` parameter set unconditionally | Line 199 precedes `if ($databaseConfig['enabled'])` at line 227 | PASS |
| Named `$bus` argument wiring | `setArgument('$bus', new Reference('messenger.bus.default'))` at line 335 | PASS |
| `deleteRow()` in interface | `public function deleteRow(EntityManagerInterface $tenantEm, string $class, array $capturedIds): void;` present | PASS |
| Single `$tenantEm->remove(` in `SharedEntityCopier` | `grep -c 'tenantEm->remove(' SharedEntityCopier.php` returns 1 (DRY) | PASS |
| `syncInProgress` flag in `deleteRow()` | Line 155 sets `$this->syncInProgress = true` inside try/finally in deleteRow | PASS |

---

### Probe Execution

No probe scripts declared for this phase. Step 7c: SKIPPED (no `scripts/*/tests/probe-*.sh` found for phase 27; quality gates — PHPUnit full suite (746 tests / 3190 assertions), PHPStan L9, cs-fixer — reported GREEN by orchestrator in 27-03-SUMMARY.md and independently verifiable from commit history).

---

### Requirements Coverage

| Requirement | Source Plan | Description | Status | Evidence |
|-------------|------------|-------------|--------|----------|
| SHARE-03 | 27-01, 27-02, 27-03 | Async shared entity fan-out via Symfony Messenger | SATISFIED | All 5 acceptance criteria met (see Observable Truths 1-5). All 10 SHARE-03-a..-j acceptance bullets have implementing tests across the 3 plans. REQUIREMENTS.md line 97 marks this requirement Complete for Phase 27. |
| DEC-SHARE-01 | 27-01, 27-02 | Sync mode default | SATISFIED | `defaultFalse()` on async config node. `bus = null` in subscriber constructor. Sync fan-out path (lines 207-295 in subscriber) unchanged and runs when bus is null. |
| DEC-SHARE-02 | Not modified | Cascade depth — one level only | NOT APPLICABLE | No change to this constraint in Phase 27. Existing DEC-SHARE-02 implementation in SharedEntityCopier is unchanged (getFieldNames() scalar-copy only). |
| DEC-SHARE-03 | Not modified | Mutual exclusion #[Shared]+#[TenantAware] | NOT APPLICABLE | No change in Phase 27. SharedEntityMutualExclusionPass from Phase 25 is unchanged. |

**Orphaned requirements check:** No additional REQUIREMENTS.md items mapped to Phase 27 that are unaccounted for in any plan.

---

### Anti-Patterns Found

Scanned files modified in this phase: all 8 source files and 6 test files from summaries.

| File | Line | Pattern | Severity | Impact |
|------|------|---------|----------|--------|
| — | — | No TBD/FIXME/XXX markers found | — | — |
| — | — | No empty implementations (return null/return []/return {}) found in production code | — | — |
| — | — | No `\stdClass` placeholder in handler | — | — |

The Phase 27 code review (27-REVIEW.md) produced 4 WARNINGs and 5 INFO findings. These are advisory, not blockers. The most consequential are summarized below for completeness:

| Review Finding | File | Severity (Advisory) | Goal Impact |
|----------------|------|---------------------|-------------|
| WR-01: `changeType` unvalidated — unknown string silently routes to upsert | `SharedEntityChangedMessageHandler.php` line 93 | WARNING | No impact on current goal: all message senders in the bundle produce valid change types. A future external replay could trigger this. Addressable in Phase 28/29. |
| WR-02: `switchToTenant`/`restoreTenantContext` duplicated (OQ-2) with no drift-detecting test | Handler + Subscriber | WARNING | Cosmetic drift already exists (import style). The duplication is a documented intentional decision. The integration canary exercises the handler's copy. Addressable post-Phase 27. |
| WR-03: Per-tenant failure catch does not clear context before `resetManager('tenant')` | `SharedEntityChangedMessageHandler.php` line 147 | WARNING | Benign today — `switchToTenant()` for the next tenant immediately resets context. Double `resetManager` is inefficient but harmless. |
| WR-04: `findAll()` materialization loop is dead defensive code per current interface contract | Handler + Subscriber | WARNING | Harmless. `TenantProviderInterface::findAll()` returns `TenantInterface[]`, not `iterable`. |
| IN-05: Async kernel tenant DBs share paths with sync suite (StubMultiTenantProvider) | `SharedEntityAsyncTestKernel.php` | INFO | Sequential PHPUnit execution is safe. Risk only under parallel execution. Docblock discrepancy acknowledged in review. |

No debt-marker BLOCKERS (TBD/FIXME/XXX without issue references) found in any modified file.

---

### Human Verification Required

None. All truths are verifiable programmatically from the codebase. The security-critical mechanisms (stamp-clearing, class-injection gate) are both structurally provable from code and exercised by the integration canary. No visual/UX/external-service verification required.

---

### SHARE-03 Test Coverage Map

| Acceptance Bullet | Test | Plan | Status |
|---|---|---|---|
| -a: bus injected → dispatch, no sync fan-out | `testPostFlushDispatchesWhenAsyncEnabled` (SHARE-03-a) | 27-02 | COVERED |
| -b: bus=null → sync fan-out, no dispatch | `testPostFlushUsesSyncFanOutWhenAsyncDisabled` (SHARE-03-b) | 27-02 | COVERED |
| -c: scalar-only message value object | `testCarriesOnlyScalars` + `testSurvivesSerializeRoundTrip` | 27-01 | COVERED |
| -d: latest-state re-fetch | `testAsyncRoundTripCanary` (round-trip + handler reaches DB) | 27-03 | COVERED |
| -e: vanished-row → delete | `testVanishedRowPropagatesToTenantDelete` | 27-03 | COVERED |
| -f: all-tenant fan-out | `testHandlerFansOutToAllTenants` + `testWrongTenantIsolationWithActiveDispatchTenant` | 27-03 | COVERED |
| -g: throw-to-retry on tenant failure | `testHandlerThrowsOnTenantFailure` (DROP TABLE induction) | 27-03 | COVERED |
| -h: idempotency on retry | `testHandlerIdempotentOnRetry` | 27-03 | COVERED |
| -i: compile-time guard (async:true + Messenger absent) | Structural proof: lines 44-45 of SharedAsyncContractPass.php; `testGuardThrowsWhenMessengerAbsent` (skip-guarded per MailerTransportContractPassTest precedent) | 27-01 | COVERED |
| -j: transport round-trip canary | `testAsyncRoundTripCanary` (dispatches via bus, not handler directly) | 27-03 | COVERED |
| D-01 stamp-clearing integration proof | `testWrongTenantIsolationWithActiveDispatchTenant` (active dispatch-time tenant → all tenants receive change) | 27-03 | COVERED |

---

### Gaps Summary

None. All phase goal truths are VERIFIED in the codebase. The review WARNINGs (WR-01 through WR-04) and INFO items are advisory findings that do not block the phase goal. They are suitable for a follow-up task in Phase 28 or a dedicated robustness phase.

---

_Verified: 2026-06-15T12:30:00Z_
_Verifier: Claude (gsd-verifier)_
