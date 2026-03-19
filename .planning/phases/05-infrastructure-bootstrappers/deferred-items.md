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
