# Deferred Items — Phase 04

## Pre-existing Test Failures (Out of Scope)

### ListenerPriorityTest failures

**Discovered during:** 04-02, Task 2 full suite run
**Status:** Pre-existing (confirmed via git stash before/after comparison)
**Tests:**
- `Tenancy\Bundle\Tests\Integration\ListenerPriorityTest::testOrchestratorRegisteredAtPriority20OnKernelRequest`
- `Tenancy\Bundle\Tests\Integration\ListenerPriorityTest::testOrchestratorRegisteredOnKernelTerminate`

**Error:** `ArgumentCountError: Too few arguments to function TenantContextOrchestrator::__construct(), 3 passed and exactly 4 expected`

**Root cause:** The test kernel's compiled container was cached with 3 args but `TenantContextOrchestrator` now has 4 required constructor params. The compiled container cache at `/tmp/tenancy_bundle_test_*/cache/Container*` is stale.

**Fix:** Clear the test kernel cache and/or regenerate it. Not related to 04-02 changes.
