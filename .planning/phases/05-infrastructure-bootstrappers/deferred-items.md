# Deferred Items — Phase 05

## Out-of-Scope Pre-Existing Failures

### TenantAwareCacheAdapterTest (4 failures)

Found during: 05-01 Task 2 verification (phpunit --testsuite unit)

**Nature:** Pre-existing failures — confirmed by running tests at git stash state before any 05-01 changes.

**Root cause:** `MockObject_Intersection` PHPUnit mock objects for `AdapterInterface&NamespacedPoolInterface` cannot satisfy `withSubNamespace()` return type (must return same mock instance type). PHP 8.4 / PHPUnit 11 type enforcement breaks intersection mock return types.

**Files affected:**
- `tests/Unit/Cache/TenantAwareCacheAdapterTest.php`
- `src/Cache/TenantAwareCacheAdapter.php`

**Action required:** This will be addressed in 05-02 (TenantAwareCacheAdapter plan) where cache adapter work is planned.

---

### EntityManagerResetIntegrationTest::testResetManagerClearsIdentityMap (1 failure)

Found during: 05-03 full-suite regression check

**Nature:** Pre-existing failure — confirmed by running tests at git stash state before any 05-03 changes.

**Test file:** `tests/Integration/EntityManagerResetIntegrationTest.php:137`

**Failure message:** `Identity map must be empty in fresh EM after TenantContextCleared reset — Failed asserting that an array is empty.`

**Root cause:** DoctrineBundle 2.x lazy proxy semantics — `resetManager()` creates a new EM under the proxy but the old EM reference held by the test still has a populated identity map. The test needs to re-fetch the EM from the registry after dispatch, not rely on the pre-reset reference.

**Action required:** Out-of-scope for 05-03 (integration test work, not a regression caused by this plan). Flagged for a future maintenance plan or Phase 06+.
