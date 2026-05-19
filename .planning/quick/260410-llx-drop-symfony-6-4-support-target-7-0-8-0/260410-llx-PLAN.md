---
phase: quick
plan: 260410-llx
type: execute
wave: 1
depends_on: []
files_modified:
  - composer.json
  - .github/workflows/ci.yml
  - src/Cache/TenantAwareCacheAdapter.php
  - README.md
  - CLAUDE.md
autonomous: true
must_haves:
  truths:
    - "composer.json requires Symfony ^7.0||^8.0 for all symfony/* deps (no 6.4)"
    - "CI matrix tests Symfony 7.4 and 8.0 only (no 6.4 rows)"
    - "TenantAwareCacheAdapter uses intersection type with no runtime instanceof check"
    - "README and CLAUDE.md reflect Symfony 7/8 only"
  artifacts:
    - path: "composer.json"
      provides: "Updated Symfony constraints ^7.0||^8.0"
      contains: "^7.0||^8.0"
    - path: ".github/workflows/ci.yml"
      provides: "Updated CI matrix without 6.4"
    - path: "src/Cache/TenantAwareCacheAdapter.php"
      provides: "Clean intersection type, no conditional"
      contains: "AdapterInterface&NamespacedPoolInterface"
    - path: "README.md"
      provides: "Updated requirements section"
    - path: "CLAUDE.md"
      provides: "Updated stack and CI references"
  key_links:
    - from: "composer.json"
      to: ".github/workflows/ci.yml"
      via: "symfony version constraints match CI matrix"
      pattern: "7\\.0.*8\\.0"
---

<objective>
Drop Symfony 6.4 support and target ^7.0||^8.0 for all Symfony dependencies.

Purpose: Symfony 6.4 compat constraints add conditional code paths and CI complexity. Moving to 7.0+ allows cleaner code (intersection types without runtime guards) and forward-looking 8.x support.

Output: Updated composer.json, CI matrix, clean TenantAwareCacheAdapter, updated docs.
</objective>

<execution_context>
@/Users/danplaton/dev/packages/[claude-code] symfony-multitenancy/.claude/get-shit-done/workflows/execute-plan.md
@/Users/danplaton/dev/packages/[claude-code] symfony-multitenancy/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@composer.json
@.github/workflows/ci.yml
@src/Cache/TenantAwareCacheAdapter.php
@tests/Unit/Cache/TenantAwareCacheAdapterTest.php
@README.md
@CLAUDE.md

<interfaces>
<!-- From vendor/symfony/cache-contracts/NamespacedPoolInterface.php -->
```php
interface NamespacedPoolInterface
{
    public function withSubNamespace(string $namespace): static;
}
```

<!-- From vendor/symfony/cache/Adapter/AdapterInterface.php -->
```php
interface AdapterInterface extends CacheItemPoolInterface
{
    public function getItem(mixed $key): CacheItem;
    public function getItems(array $keys = []): iterable;
    public function clear(string $prefix = ''): bool;
}
```

NOTE: In Symfony 7+, AdapterInterface does NOT extend NamespacedPoolInterface at the interface level.
However, ALL concrete adapters (AbstractAdapter, ArrayAdapter, NullAdapter, etc.) implement BOTH.
The clean approach is: type-hint constructor as intersection `AdapterInterface&NamespacedPoolInterface`
and declare the class implements both.
</interfaces>
</context>

<tasks>

<task type="auto">
  <name>Task 1: Update composer.json and CI matrix</name>
  <files>composer.json, .github/workflows/ci.yml</files>
  <action>
**composer.json changes:**

1. In `require` block, change ALL `symfony/*` constraints from `^6.4||^7.0` to `^7.0||^8.0`:
   - symfony/cache
   - symfony/config
   - symfony/console
   - symfony/dependency-injection
   - symfony/event-dispatcher
   - symfony/http-foundation
   - symfony/http-kernel
   - symfony/process

2. In `require-dev` block, change ALL `symfony/*` constraints from `^6.4||^7.0` to `^7.0||^8.0`:
   - symfony/framework-bundle
   - symfony/messenger
   - symfony/phpunit-bridge

3. In `suggest` block, update the symfony/messenger suggestion text from `^6.4||^7.0` to `^7.0||^8.0`.

4. Keep PHP constraint at `^8.2` (Symfony 7 requires PHP 8.2+, so this is correct).

**CI matrix changes (.github/workflows/ci.yml):**

1. In the `tests` job matrix, change `symfony: ['6.4.*', '7.4.*']` to `symfony: ['7.4.*', '8.0.*']`.

2. In the `coverage` job, keep `SYMFONY_REQUIRE: '7.4.*'` (this is fine as-is).

3. In the `cs-fixer` job, keep `php-version: '8.2'` (no changes needed).

4. In the `no-doctrine` job, keep as-is (uses composer install defaults which will resolve to allowed range).

Do NOT change any non-Symfony dependencies (doctrine, phpstan, phpunit, php-cs-fixer).
  </action>
  <verify>
    <automated>cd "/Users/danplaton/dev/packages/[claude-code] symfony-multitenancy" && php -r '$c = json_decode(file_get_contents("composer.json"), true); $fail = false; foreach ($c["require"] as $pkg => $v) { if (str_starts_with($pkg, "symfony/") && str_contains($v, "6.4")) { echo "FAIL: $pkg still has 6.4\n"; $fail = true; } } foreach ($c["require-dev"] as $pkg => $v) { if (str_starts_with($pkg, "symfony/") && str_contains($v, "6.4")) { echo "FAIL: $pkg (dev) still has 6.4\n"; $fail = true; } } if (!$fail) echo "OK: no 6.4 in symfony deps\n";' && grep -c '6\.4' .github/workflows/ci.yml | xargs -I{} test {} -eq 0 && echo "OK: no 6.4 in CI" || echo "FAIL: 6.4 still in CI"</automated>
  </verify>
  <done>All symfony/* constraints in composer.json are ^7.0||^8.0. CI matrix tests 7.4 and 8.0 only. No trace of 6.4 in either file.</done>
</task>

<task type="auto">
  <name>Task 2: Clean TenantAwareCacheAdapter intersection type</name>
  <files>src/Cache/TenantAwareCacheAdapter.php</files>
  <action>
Refactor TenantAwareCacheAdapter to use a clean intersection type now that Symfony 7+ is the minimum:

1. Change class declaration from:
   `final class TenantAwareCacheAdapter implements AdapterInterface`
   to:
   `final class TenantAwareCacheAdapter implements AdapterInterface, NamespacedPoolInterface`

2. Change constructor `$inner` parameter type from `AdapterInterface` to `AdapterInterface&NamespacedPoolInterface` (intersection type).

3. Change `pool()` return type from `AdapterInterface` to `AdapterInterface&NamespacedPoolInterface`.

4. In `pool()`, remove the `instanceof NamespacedPoolInterface` conditional check. The method body becomes:
   ```php
   private function pool(): AdapterInterface&NamespacedPoolInterface
   {
       $tenant = $this->tenantContext->getTenant();
       if (null !== $tenant) {
           return $this->inner->withSubNamespace($tenant->getSlug());
       }

       return $this->inner;
   }
   ```

5. In `withSubNamespace()`, ensure the method still works correctly — `$clone->inner` assignment from `$this->inner->withSubNamespace($namespace)` returns `static` from the inner pool which satisfies the intersection type via covariance. No changes needed to this method body.

The `use Symfony\Contracts\Cache\NamespacedPoolInterface;` import is already present -- keep it.

Do NOT change the test file -- the tests already use intersection mocks and the `testImplementsNamespacedPoolInterface` assertion will now pass correctly against the explicit implements declaration.
  </action>
  <verify>
    <automated>cd "/Users/danplaton/dev/packages/[claude-code] symfony-multitenancy" && vendor/bin/phpunit tests/Unit/Cache/TenantAwareCacheAdapterTest.php && vendor/bin/phpstan analyse src/Cache/TenantAwareCacheAdapter.php</automated>
  </verify>
  <done>TenantAwareCacheAdapter declares `implements AdapterInterface, NamespacedPoolInterface`, constructor takes intersection type `AdapterInterface&NamespacedPoolInterface`, pool() has no instanceof conditional, all unit tests pass, PHPStan clean.</done>
</task>

<task type="auto">
  <name>Task 3: Update documentation references</name>
  <files>README.md, CLAUDE.md</files>
  <action>
**README.md changes:**

1. Line 110 — Requirements section: Change `- Symfony \`^6.4\` or \`^7.0\`` to `- Symfony \`^7.0\` or \`^8.0\``

**CLAUDE.md changes:**

1. Line 7 — Change `Targets PHP 8.2+ and Symfony 6.4/7.x.` to `Targets PHP 8.2+ and Symfony 7.x/8.x.`

2. Line 12 — Change `- **Framework:** Symfony 6.4 / 7.x (bundle architecture)` to `- **Framework:** Symfony 7.x / 8.x (bundle architecture)`

3. Line 17 — Change `- **CI:** GitHub Actions (PHP 8.2/8.3/8.4 x Symfony 6.4/7.4 matrix)` to `- **CI:** GitHub Actions (PHP 8.2/8.3/8.4 x Symfony 7.4/8.0 matrix)`

**CONTRIBUTING.md** — no changes needed (it does not reference Symfony version numbers).

Do NOT change any other content in these files.
  </action>
  <verify>
    <automated>cd "/Users/danplaton/dev/packages/[claude-code] symfony-multitenancy" && ! grep -n '6\.4' README.md CLAUDE.md CONTRIBUTING.md && echo "OK: no 6.4 in docs"</automated>
  </verify>
  <done>README.md requirements section says Symfony ^7.0 or ^8.0. CLAUDE.md references Symfony 7.x/8.x throughout. No trace of 6.4 in any documentation file.</done>
</task>

</tasks>

<verification>
1. `grep -rn '6\.4' composer.json .github/workflows/ci.yml src/ README.md CLAUDE.md CONTRIBUTING.md` returns no matches
2. `vendor/bin/phpunit tests/Unit/Cache/TenantAwareCacheAdapterTest.php` passes (all 7 tests)
3. `vendor/bin/phpstan analyse src/Cache/TenantAwareCacheAdapter.php` passes at level 9
4. `php -r "echo json_encode(json_decode(file_get_contents('composer.json'))->require, JSON_PRETTY_PRINT);"` shows ^7.0||^8.0 for all symfony deps
5. `grep 'instanceof NamespacedPoolInterface' src/Cache/TenantAwareCacheAdapter.php` returns no matches
</verification>

<success_criteria>
- Zero references to Symfony 6.4 across the entire project (composer.json, CI, docs, source)
- All Symfony constraints are ^7.0||^8.0
- CI matrix covers Symfony 7.4 and 8.0
- TenantAwareCacheAdapter uses clean intersection type with no runtime instanceof guard
- All existing tests pass
- PHPStan clean at level 9
</success_criteria>

<output>
After completion, create `.planning/quick/260410-llx-drop-symfony-6-4-support-target-7-0-8-0/260410-llx-SUMMARY.md`
</output>
