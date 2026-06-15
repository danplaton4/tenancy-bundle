---
phase: 27-async-shared-entities
reviewed: 2026-06-15T11:40:51Z
depth: standard
files_reviewed: 14
files_reviewed_list:
  - src/DependencyInjection/Compiler/SharedAsyncContractPass.php
  - src/Exception/SharedEntityAsyncFanOutException.php
  - src/Message/SharedEntityChangedMessage.php
  - src/MessageHandler/SharedEntityChangedMessageHandler.php
  - src/Shared/SharedEntityCopier.php
  - src/Shared/SharedEntityCopierInterface.php
  - src/Subscriber/SharedEntitySyncSubscriber.php
  - src/TenancyBundle.php
  - tests/Integration/SharedEntity/SharedEntityAsyncCanaryTest.php
  - tests/Integration/SharedEntity/Support/MakeSharedEntityAsyncServicesPublicPass.php
  - tests/Integration/SharedEntity/Support/SharedEntityAsyncTestKernel.php
  - tests/Unit/DependencyInjection/Compiler/SharedAsyncContractPassTest.php
  - tests/Unit/Message/SharedEntityChangedMessageTest.php
  - tests/Unit/Subscriber/SharedEntitySyncSubscriberAsyncTest.php
findings:
  critical: 0
  warning: 4
  info: 5
  total: 9
status: issues_found
---

# Phase 27: Code Review Report

**Reviewed:** 2026-06-15T11:40:51Z
**Depth:** standard
**Files Reviewed:** 14
**Status:** issues_found

## Summary

This phase adds opt-in async shared-entity fan-out over Symfony Messenger: a new
`SharedEntityChangedMessage` value object, an async dispatch branch in
`SharedEntitySyncSubscriber::postFlush()`, a worker-side `SharedEntityChangedMessageHandler`,
a `SharedAsyncContractPass` compile-time guard, a `SharedEntityAsyncFanOutException`
retry-signal, a `deleteRow()` extraction in `SharedEntityCopier`, and DI wiring in
`TenancyBundle`. Tests cover the unit (message scalar-discipline, subscriber dispatch
branch, contract-pass guard) and integration (sync:// round-trip canary) layers.

The two security-critical mechanisms hold up under adversarial tracing:

1. **Stamp-clearing (D-01).** The subscriber clears `TenantContext` before `bus->dispatch()`
   and restores it in `finally`. `TenantSendingMiddleware` only stamps when `getTenant()` is
   non-null, so the fan-to-all message ships unstamped; `TenantWorkerMiddleware` is a pure
   pass-through on a missing stamp. The worker therefore fans out to every tenant rather than
   the active one. Confirmed correct against the actual middleware source.

2. **Class-injection gate (T-27-02-CLASSINJ).** The handler validates `$message->entityClass`
   against `findSharedClasses()` with `in_array(..., strict: true)` before any `find()`/fan-out,
   throwing an `UnrecoverableExceptionInterface` (no retry) on mismatch. Solid.

No cross-tenant data leak or injection vector was found. The landlord EM (and its connection)
are never reset inside the per-tenant loop, so the re-fetched `$landlordEntity` stays managed
and valid across tenant switches, and `applyRow()` copies scalar fields only (no association
lazy-load that could cross connections). No **BLOCKER** findings.

The findings below are robustness / correctness-hardening gaps (WARNING) and
documentation/coverage drift (INFO). The most consequential are WR-01 (unvalidated
`changeType` silently routes unknown change types to upsert) and WR-02 (a duplicated
tenant-switch twin with an explicit "keep in sync" contract that has no enforcing test).

## Warnings

### WR-01: Unvalidated `changeType` silently routes unknown values to upsert

**File:** `src/MessageHandler/SharedEntityChangedMessageHandler.php:93-101`, `src/Message/SharedEntityChangedMessage.php:28-33`
**Issue:** `SharedEntityChangedMessage::$changeType` is typed only as `string` (the
`'insert'|'update'|'delete'` constraint lives only in a docblock with no runtime enforcement).
The handler branches solely on `'delete' !== $changeType`:

```php
$landlordEntity = ('delete' !== $changeType)
    ? $this->landlordEm->find($class, $identifier)
    : null;
```

Any value that is not exactly the lowercase string `'delete'` (e.g. `'remove'`, `'DELETE'`,
`'deleted'`, an empty string, or a malformed payload that survived a transport/serialization
edit) is treated as an insert/update and **upserts the row onto every tenant**. For a message
whose true intent was a deletion, this resurrects a row the operator deleted — a silent
correctness inversion on the tenant side. Messages are bundle-internal today, but the payload
crosses a serialization boundary (transport, DLQ replay, manual re-dispatch in
`testVanishedRowPropagatesToTenantDelete`/`testHandlerThrowsOnTenantFailure` already construct
messages by hand), so a typo or version skew is reachable.
**Fix:** Validate `changeType` at the top of `__invoke()` (alongside the existing class gate)
and treat an unknown value as unrecoverable, mirroring the class-injection gate:

```php
if (!\in_array($changeType, ['insert', 'update', 'delete'], true)) {
    $this->logger->error('tenancy.shared_entity_async_unknown_change_type', [
        'entity_class' => $class,
        'change_type'  => $changeType,
    ]);
    throw new class(sprintf('tenancy: SharedEntityChangedMessage carries unknown changeType "%s".', $changeType)) extends \RuntimeException implements UnrecoverableExceptionInterface {};
}
```
Optionally narrow the constructor parameter type or assert it in `SharedEntityChangedMessage`
so the invariant is enforced at construction time, not only at handle time.

### WR-02: "Source-of-truth twin" duplication has no drift-detecting test

**File:** `src/MessageHandler/SharedEntityChangedMessageHandler.php:163-207`, `src/Subscriber/SharedEntitySyncSubscriber.php:246-263,330-343`
**Issue:** `switchToTenant()` and `restoreTenantContext()` are duplicated verbatim between the
handler and the subscriber. The handler docblock (lines 42-50) explicitly states "duplicated
verbatim ... If the subscriber's tenant-switch logic changes, update this class in sync." This
is a deliberate, documented DRY-violation, but nothing mechanically enforces the invariant —
the two copies have *already* drifted cosmetically (the subscriber writes
`\Doctrine\DBAL\Connection`, the handler imports and uses `Connection`; the subscriber's
`switchToTenant` carries the WR-04 identity-map comment, the handler's does not). These
copies guard the bundle's core tenant-isolation guarantee (CR-01/CR-02: connection close +
context restore). A future fix applied to only one copy would silently leave the other path
leaking a connection against the wrong tenant's DB — exactly the class of defect CR-02 was
introduced to prevent — with no test to catch it.
**Fix:** Either (preferred) extract the two methods into a small shared collaborator
(e.g. `TenantEmSwitcher`) injected into both classes so there is a single implementation, or
add a unit test that reflects both methods' bodies and asserts equivalence, or at minimum a
test that exercises the handler's `switchToTenant`/`restoreTenantContext` and asserts the
tenant connection is closed and context restored (the integration canary covers the happy
path but not connection-close/restore assertions specifically for the handler).

### WR-03: Per-tenant failure resets the tenant EM but does not switch context back — next tenant boots from prior tenant's residual context

**File:** `src/MessageHandler/SharedEntityChangedMessageHandler.php:138-148`
**Issue:** On a per-tenant fan-out failure the handler logs, records the slug, and calls
`$this->registry->resetManager('tenant')`, then continues the loop. The next iteration calls
`switchToTenant($nextTenant)` which sets context and closes/reopens the tenant connection, so
the *next* tenant is fine. However, between the `catch` and the next `switchToTenant`, the
`TenantContext` still points at the **failed** tenant, and `resetManager('tenant')` is called
while that stale context is active. This is benign today because nothing queries the tenant EM
between the catch and the next switch, but it is fragile: the reset manager's first lazy
connect (if anything touched it) would resolve against the failed tenant's params. The
subscriber's sync path has the same shape but resets via `applyChange()` returning a fresh EM
that is immediately re-bound on the next outer-loop `switchToTenant`. Note also that
`resetManager('tenant')` is called in the catch **and** again in the next `switchToTenant`
(double reset).
**Fix:** Drop the `resetManager('tenant')` from the catch block (the subsequent
`switchToTenant()` already resets it), or clear the context immediately after recording the
failure so no stale-tenant connection can be established. Add a test that fails tenant A and
asserts tenant B's row carries tenant-B-correct data and connection (the current
`testHandlerThrowsOnTenantFailure` asserts tenant B received the row but does not assert the
intervening reset happened under a cleared/correct context).

### WR-04: `findAll()` materialization loops are dead defensive code; comments misstate the contract

**File:** `src/MessageHandler/SharedEntityChangedMessageHandler.php:113-118`, `src/Subscriber/SharedEntitySyncSubscriber.php:209-218`
**Issue:** Both sites copy `$this->tenantProvider->findAll()` into a local `$tenants` array
with a `foreach` and justify it with "a Generator-backed findAll() exhausts after the first
iteration." But `TenantProviderInterface::findAll()` is declared to return `TenantInterface[]`
(a plain array — see `src/Provider/TenantProviderInterface.php` and `DoctrineTenantProvider`),
not `iterable`/`Generator`. Per the published contract a Generator return is impossible, so the
materialization loop is dead defensive code and the rationale comment is inaccurate. This is a
maintainability trap: a reader may "fix" the interface to `iterable` believing the consumers
already handle it, when in fact only these two sites do and other consumers
(`SharedEntityResyncCommand`, `TenantMigrateCommand`) iterate `findAll()` directly. Either the
interface should be widened to `iterable` (and all consumers audited), or these loops + comments
should be simplified to `$tenants = $this->tenantProvider->findAll();`.
**Fix:** Replace each `foreach`-into-array block with a direct assignment
(`$tenants = $this->tenantProvider->findAll();`) and delete the Generator-exhaustion comment,
**or** widen `TenantProviderInterface::findAll(): iterable` and audit every caller. Do not leave
the contract and the defensive code contradicting each other.

## Info

### IN-01: Handler claims "latest-state re-fetch (D-05)" but no test mutates landlord state before handling

**File:** `tests/Integration/SharedEntity/SharedEntityAsyncCanaryTest.php:133-189`
**Issue:** `testAsyncRoundTripCanary` is annotated `SHARE-03-d: latest-state re-fetch (handler
uses current landlord state, not message snapshot)`, but it only persists once and asserts the
inserted values propagate — it never updates the landlord row after dispatch to prove the
handler re-fetches *current* state rather than a message snapshot. Since the message carries
only scalar IDs (never a snapshot), the re-fetch property is structurally true, but the test
does not actually exercise the divergence it claims to cover. A regression that started reading
state from the message payload would not be caught by this test.
**Fix:** Add a test that inserts, then updates the landlord row to a new value, then dispatches
a message and asserts every tenant DB reflects the **updated** value (proving re-fetch, not
snapshot). Or downgrade the annotation to match what is actually asserted.

### IN-02: Stale "shared_db short-circuit" docblock on the async subscriber branch

**File:** `src/Subscriber/SharedEntitySyncSubscriber.php:56-61,160-165`
**Issue:** The class docblock and the `postFlush` body retain the `'shared_db' === $this->driver`
short-circuit and its long explanation, but `TenancyBundle::loadExtension()` (lines 274-351 and
the WR-05 note at 280-286) only ever registers this subscriber inside the `database.enabled`
block, which the config validator forbids combining with `shared_db`. The branch is therefore
unreachable under any wiring the bundle produces (the bundle's own comment acknowledges this).
This is acceptable defence-in-depth, but the async branch added in this phase sits *after* the
short-circuit and inherits the same dead-path framing without a note that it, too, is
unreachable under `shared_db`. Low risk; flagged for documentation accuracy.
**Fix:** Add a one-line cross-reference to the WR-05 note in `TenancyBundle` so a future reader
does not spend effort reasoning about an async-under-shared_db path that cannot be wired.

### IN-03: `SharedAsyncContractPass` negative path is only structurally (grep) verified

**File:** `tests/Unit/DependencyInjection/Compiler/SharedAsyncContractPassTest.php:50-73`
**Issue:** `testGuardThrowsWhenMessengerAbsent` skips whenever `symfony/messenger` is installed
(which is the CI norm), so the `throw new \LogicException` branch (the entire point of D-06) is
never executed in the test suite — coverage relies on the SHARE-03-i grep assertion. This
mirrors the established `MailerTransportContractPassTest` precedent, so it is consistent with
project convention, but the guard's actual throw remains unexercised at runtime.
**Fix:** Consider extracting the messenger-presence check behind an injectable predicate (a
closure or a `protected function messengerInstalled(): bool`) so a unit test can drive the
`true`-config + absent-messenger combination and assert the exception message, removing the
skip.

### IN-04: Cosmetic duplication drift between the twin methods

**File:** `src/MessageHandler/SharedEntityChangedMessageHandler.php:176-179`, `src/Subscriber/SharedEntitySyncSubscriber.php:254-257`
**Issue:** The "verbatim" twins already differ in import style — the handler imports
`Doctrine\DBAL\Connection` and uses the short name; the subscriber uses the fully-qualified
`\Doctrine\DBAL\Connection` inline. Behaviorally identical, but it undercuts the "verbatim copy"
claim and makes a literal diff between the two methods noisy, weakening WR-02's manual-sync
contract.
**Fix:** Normalize the import style across both files (prefer the imported short name to match
the handler) so a future maintainer can diff the two methods cleanly.

### IN-05: Misleading kernel docblock — async test DBs collide with the sync suite's tenant DB paths

**File:** `tests/Integration/SharedEntity/Support/SharedEntityAsyncTestKernel.php:26-32`, `tests/Integration/SharedEntity/Support/StubMultiTenantProvider.php:36-44`
**Issue:** The async kernel docblock advertises "distinct SQLite DB filenames
(tenancy_shared_async_test_*.db) to avoid colliding with the sync kernel's files," but the
per-tenant DBs are supplied by `StubMultiTenantProvider`, which returns the **shared**
`tenancy_shared_test_tenant_a.db` / `_tenant_b.db` paths (the docblock itself admits this in the
fine print at lines 30-32: "same path as sync kernel ... tests must not run in parallel with the
sync suite"). The headline claim contradicts the detail. With PHPUnit's default ordering this is
fine, but any parallel/randomized execution (paratest, `--order-by=random` across suites) would
cross-contaminate the two suites' tenant DBs, producing flaky cross-suite failures.
**Fix:** Give the async suite its own tenant DB filenames (e.g.
`tenancy_shared_async_test_tenant_a.db`) in a dedicated provider, or correct the headline
docblock to state plainly that the tenant DBs are shared with the sync suite and the suites are
mutually exclusive by file lock / ordering.

---

_Reviewed: 2026-06-15T11:40:51Z_
_Reviewer: Claude (gsd-code-reviewer)_
_Depth: standard_
