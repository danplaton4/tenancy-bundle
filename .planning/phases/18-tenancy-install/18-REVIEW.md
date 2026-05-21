---
phase: 18-tenancy-install
reviewed: 2026-05-21T00:00:00Z
depth: standard
files_reviewed: 7
files_reviewed_list:
  - src/Resolver/HostResolver.php
  - src/Resolver/HeaderResolver.php
  - src/Resolver/QueryParamResolver.php
  - src/Resolver/ConsoleResolver.php
  - src/Command/TenantRunCommand.php
  - src/Messenger/TenantWorkerMiddleware.php
  - tests/Integration/ZeroConfigKernelBootTest.php
findings:
  critical: 1
  warning: 4
  info: 5
  total: 10
status: issues_found
---

# Phase 18: Code Review Report (Gap-Closure Delta)

**Reviewed:** 2026-05-21
**Depth:** standard
**Files Reviewed:** 7
**Status:** issues_found

## Summary

This is the delta review for the Phase 18 gap-closure work (plans 18-08..18-11) that
makes `?TenantProviderInterface` constructor parameters nullable across six defect
sites so that a fresh `composer require danplaton4/tenancy-bundle` skeleton boots
cleanly when no `tenancy:` config block is present. It supersedes the prior
`18-REVIEW.md` which covered the original plans 18-01..18-07.

The core fix is sound: every defect-site constructor was correctly switched to a
nullable type-hint, and the fail-silent / fail-loud split between read-path resolvers
and write-path consumers is the right policy. However, several quality concerns and
one functional gap surfaced:

- **One BLOCKER**: the four read-path resolvers were updated with `= null` default
  values, but `ConsoleResolver`, `TenantRunCommand`, and `TenantWorkerMiddleware`
  declare `?TenantProviderInterface` *without* `= null`. The DI container does
  pass null via `nullOnInvalid()`, so today this compiles — but the inconsistency
  is itself the blocker: there is no PHPStan rule, no contract test, and no comment
  preventing the next contributor from dropping the `?` on any one of these six
  sites and resurrecting the exact TypeError this gap-closure existed to prevent.
  See CR-01.
- WARNINGs around `RuntimeException` (wrong exception class for a misconfiguration
  signal — has real consequences for Messenger retry semantics), the fragile
  guard-ordering in `ConsoleResolver` that mutates global Application state, and
  a subtle type-handling asymmetry between `QueryParamResolver` and its siblings.
- The test file is largely correct and faithfully reproduces the regression, but
  has a few weaknesses: a stale "MUST fail on master" docblock, a
  `setCatchExceptions(false)` setting that nullifies the assertion message,
  parallel-process cache-dir collision, and an unobvious implicit-namespace
  import.

## Critical Issues

### CR-01: Nullable-with-default is applied inconsistently — six sites, two patterns

**File:** `src/Resolver/ConsoleResolver.php:20-26`, `src/Command/TenantRunCommand.php:18-24`, `src/Messenger/TenantWorkerMiddleware.php:18-24`

**Issue:**
The four read-path resolvers were uniformly updated to default the provider to null:
- `src/Resolver/HostResolver.php:14-18` → `private readonly ?TenantProviderInterface $tenantProvider = null,`
- `src/Resolver/HeaderResolver.php:16-19` → `= null,`
- `src/Resolver/QueryParamResolver.php:16-19` → `= null,`

The three remaining defect sites are nullable but NOT defaulted:
- `src/Resolver/ConsoleResolver.php:21` → `private readonly ?TenantProviderInterface $tenantProvider,`
- `src/Command/TenantRunCommand.php:19` → `private readonly ?TenantProviderInterface $tenantProvider,`
- `src/Messenger/TenantWorkerMiddleware.php:21` → `private readonly ?TenantProviderInterface $tenantProvider,`

This is a functional + consistency hazard:

1. The defect this fix existed to prevent was *exactly* the TypeError from passing
   null to a non-nullable parameter. The fix is robust only as long as every site
   *remains* `?TenantProviderInterface`. Because the only enforcement is
   convention (no PHPStan rule, no contract test, no comment), a contributor who
   removes the `?` on any one of these six sites re-introduces the regression
   silently — and the half-applied default pattern makes it look like an
   intentional asymmetry rather than a mistake.
2. The integration test in `ZeroConfigKernelBootTest` only exercises wiring
   through the DI container. If a future test instantiates `ConsoleResolver`
   directly with positional args (and many integration tests do), the missing
   default forces callers to know the implementation detail and pass `null`
   explicitly. The DI container masking this is exactly how the original
   regression escaped detection until 2026-05-21.
3. Two of six defect sites have defaults, four don't (counting the three
   non-defaulted promoted-property declarations as one). That ratio screams
   half-applied refactor.

**Fix:**

Apply `= null` uniformly to all six promoted-property declarations. PHP allows
nullable-with-default to be followed by non-nullable-no-default params inside
constructor promotion (it's a constructor, not a function call):

```php
// src/Resolver/ConsoleResolver.php
public function __construct(
    private readonly ?TenantProviderInterface $tenantProvider = null,
    private readonly TenantContext $tenantContext,
    private readonly BootstrapperChain $bootstrapperChain,
    private readonly EventDispatcherInterface $eventDispatcher,
) {

// src/Command/TenantRunCommand.php
public function __construct(
    private readonly ?TenantProviderInterface $tenantProvider = null,
    private readonly string $projectDir,
    private readonly ?\Closure $processFactory = null,
) {

// src/Messenger/TenantWorkerMiddleware.php
public function __construct(
    private readonly TenantContext $tenantContext,
    private readonly BootstrapperChain $bootstrapperChain,
    private readonly ?TenantProviderInterface $tenantProvider = null,
    private readonly EventDispatcherInterface $eventDispatcher,
) {
```

Additionally, lock the invariant with a PHPStan rule or a static reflection test
(`tests/Static/NullableProviderInjectionTest.php`) that scans `src/` for every
constructor parameter typed `TenantProviderInterface` and asserts the type
allows null AND the parameter has a default value. Without this enforcement, the
fix is one careless edit away from regressing.

## Warnings

### WR-01: `RuntimeException` is the wrong exception class for misconfiguration

**File:** `src/Command/TenantRunCommand.php:35-37`, `src/Messenger/TenantWorkerMiddleware.php:34-36`

**Issue:**
Both write-path consumers throw `\RuntimeException` when the provider is null.
This is technically functional (the bundle is in an unexpected runtime state),
but PSR-style and Symfony convention reserve `RuntimeException` for unexpected
*runtime* failures, while a missing config block is a *misconfiguration* — a
programmer/operator error that should be `LogicException` or, even better, a
domain-specific `MissingTenantProviderException` under the bundle's existing
`Tenancy\Bundle\Exception\` hierarchy.

Why this matters in practice for `TenantWorkerMiddleware`:
- Symfony's MessengerBundle and several common worker-retry strategies treat
  `RuntimeException` as a *retryable* worker failure. With a real Messenger
  transport, a misconfigured worker would re-queue the message until the retry
  cap is hit, generating noise and delaying the visible error, instead of
  failing fast and surfacing the config issue immediately.
- The exception message is currently the *only* signal the user has. With a
  dedicated exception class, downstream tooling (and the user's own error
  handlers) can `instanceof` check for it and apply different handling than
  for a transient worker failure.

The exception messages themselves are good — they are actionable, mention
`tenancy:install`, and explain the cause. The class type is the issue.

**Fix:**

Create `Tenancy\Bundle\Exception\MissingTenantProviderException` extending
`\LogicException` (programmer error, not transient runtime failure), and throw
it from both sites:

```php
namespace Tenancy\Bundle\Exception;

final class MissingTenantProviderException extends \LogicException
{
    public static function forContext(string $context): self
    {
        return new self(sprintf(
            '%s requires a configured tenant provider, but `tenancy.provider` is unbound. '
            .'Add a `tenancy:` config block (run `bin/console tenancy:install`) and ensure '
            .'doctrine/orm is installed before invoking this code path.',
            $context,
        ));
    }
}
```

Then in `TenantRunCommand:36`:
```php
throw MissingTenantProviderException::forContext('tenancy:run');
```

And in `TenantWorkerMiddleware:35`, include the offending slug in the context:
```php
throw MissingTenantProviderException::forContext(sprintf(
    'TenantWorkerMiddleware (envelope stamped slug=\'%s\')',
    $stamp->getTenantSlug(),
));
```

### WR-02: `ConsoleResolver` mutates global Application definition — guard ordering is fragile

**File:** `src/Resolver/ConsoleResolver.php:52-61`

**Issue:**
The provider-null guard at lines 31-33 (now correct) returns *before* the
Application definition is mutated, which is good. But the broader pattern still
has a latent landmine: lines 52-57 add the `--tenant` global option to the
Symfony `Application` definition the *first* time `onConsoleCommand` fires, and
this mutation persists for the entire CLI process. If at any point in the future
a refactor moves the `null === $this->tenantProvider` check *after* the
application-definition mutation, every console command (in zero-config mode)
will silently gain a `--tenant` option that does nothing. PHPStan won't catch
it; the integration test in `ZeroConfigKernelBootTest::testConsoleApplicationVersionCommandExitsZero`
might, depending on assertion strictness — but `list` doesn't fail just because
an unused option exists.

The guard is also brittle because there is no comment at the mutation site (line
52) warning that the early return must remain ABOVE it.

**Fix:**

Either:
1. Add a defensive `assert(null !== $this->tenantProvider)` immediately before
   line 52, and a `// MUST run after the null-provider guard above` comment at
   the mutation site, OR
2. Move the `--tenant` global option registration out of the event listener
   entirely and into a one-shot compiler pass / kernel boot hook so it isn't
   mutating mutable global state on each command invocation.

Option 2 is the cleaner long-term fix and removes the entire class of bugs.

### WR-03: `QueryParamResolver` empty-string check happens before cast — asymmetric with siblings

**File:** `src/Resolver/QueryParamResolver.php:28-35`

**Issue:**
```php
$slug = $request->query->get(self::PARAM_NAME);

if (null === $slug || '' === $slug) {
    return null;
}

try {
    return $this->tenantProvider->findBySlug((string) $slug);
```

`Request::query->get()` is typed `mixed` on current Symfony versions — it can
return a non-string scalar in some edge cases (`?_tenant[]=foo` historically
returned `null` or an array on different Symfony minor versions). The early-return
guard only rejects exact-null and exact-empty-string. The author clearly knew
about this risk (hence the `(string)` cast at line 35), but the cast *after*
the empty check means: a query like `?_tenant=0` passes the guard (`'0' !== ''`),
casts to `'0'`, and hits `findBySlug('0')` — fine, but the deeper issue is the
asymmetry with `ConsoleResolver:65`, which already uses the more robust
`!\is_string($slug) || '' === $slug` pattern, and `HeaderResolver:30`, which
works on `headers->get()` (guaranteed string-or-null).

This is not a security issue — `findBySlug` is the only consumer and the
underlying repository will safely reject an unknown slug — but it's an
intra-bundle consistency drift.

**Fix:**

Reorder cast-and-check to match `ConsoleResolver`:
```php
$slug = $request->query->get(self::PARAM_NAME);

if (!\is_string($slug) || '' === $slug) {
    return null;
}

try {
    return $this->tenantProvider->findBySlug($slug);
```

This removes the redundant `(string)` cast and matches the
`\is_string()`-based pattern already used elsewhere in the bundle.

### WR-04: `TenantRunCommand` injects unescaped `$commandString` into a shell command line

**File:** `src/Command/TenantRunCommand.php:50-56`

**Issue:**
```php
$commandLine = sprintf(
    '%s %s %s --tenant=%s',
    escapeshellarg(\PHP_BINARY),
    escapeshellarg($consolePath),
    $commandString,                  // <-- not escaped
    escapeshellarg($tenantSlug),
);
```

`$commandString` comes directly from `InputArgument::REQUIRED` (line 30) and is
spliced into a shell command line *without escaping*. The author's clear
intent is that the user pass a *command with its args* as a single argument
(e.g., `tenancy:run acme "app:do-thing arg1 arg2"`), and `Process::fromShellCommandline`
needs the words to remain space-separated — so blanket `escapeshellarg` would
break the feature. But the current implementation is a command-injection
vector by any reasonable security review: a malicious caller (e.g., via a HTTP
endpoint that constructs the inner command from user input, which the bundle
docs warn against but does not prevent) can pass `app:do-thing; rm -rf /` and
get arbitrary shell execution.

This is *technically* pre-existing behavior, not introduced by the gap-closure
changes, but the gap-closure review is the natural place to surface it because
the file was modified in this batch. Note as WARNING (not BLOCKER) because the
intended caller is a developer with full shell access already, and the docs
should make the trust boundary explicit.

**Fix:**

Replace `Process::fromShellCommandline` with the array-form `new Process([...])`
constructor and parse `$commandString` into argv-style tokens (e.g., via
`Symfony\Component\Console\Input\StringInput::__construct` + `getArguments`,
or PHP's `str_getcsv($commandString, ' ', '"')`). The array form does not invoke
a shell, eliminating injection entirely:

```php
// Parse command string into argv tokens without invoking a shell.
$argv = str_getcsv($commandString, ' ', '"');
$argv = array_values(array_filter($argv, static fn ($t) => '' !== $t));

$argv = array_merge(
    [\PHP_BINARY, $consolePath],
    $argv,
    ['--tenant='.$tenantSlug],
);

$process = (null !== $this->processFactory)
    ? ($this->processFactory)($argv)
    : new Process($argv);
```

If the array-form refactor is too large for this gap-closure phase, at minimum
add a docblock to `configure()` documenting that `command_string` is
shell-interpolated and MUST come from a trusted source.

## Info

### IN-01: Stale "MUST fail on master" docblock in canary test

**File:** `tests/Integration/ZeroConfigKernelBootTest.php:23-37`

**Issue:**
The class docblock says:
> This test MUST fail on master before plans 18-09/18-10 land.
> After those plans, it becomes the GREEN-bar regression gate.

And carries `@group canary-red`. After plans 18-09/18-10 land (which this review
is for), the test is no longer red-bar — it's a regression gate. Leaving the
red-bar framing in place misleads future readers about its current role and
breaks any CI selector that uses `--exclude-group canary-red` to skip
intentionally-failing tests.

**Fix:**
Rewrite the docblock as a plain regression-gate description, replace
`@group canary-red` with `@group regression` (or remove the annotation).

### IN-02: `setCatchExceptions(false)` may mask the documented exit-code assertion

**File:** `tests/Integration/ZeroConfigKernelBootTest.php:139-150`

**Issue:**
```php
$application->setAutoExit(false);
$application->setCatchExceptions(false);

$tester = new ApplicationTester($application);
$tester->run(['command' => 'list']);

$this->assertSame(0, $tester->getStatusCode(), 'bin/console list must exit 0 ...');
```

With `setCatchExceptions(false)`, any exception thrown during `list` propagates
out of `$tester->run(...)` as a real PHP exception — the `assertSame(0, ...)`
line is never reached, the test still fails (good), but the assertion message
("bin/console list must exit 0 in zero-config mode. Output: ...") is never
printed. The failure manifests as a stack trace instead, which is the opposite
of what the assertion message suggests is being checked. This is a documented
risky-test pattern: an exception handler / catch policy that defeats the
diagnostic value of the assertion.

**Fix:**
Either remove `setCatchExceptions(false)` (Symfony will convert exceptions to
non-zero exit codes and the assertion produces the documented diagnostic), or
wrap the run in `try { ... } catch (\Throwable $e) { $this->fail($e->getMessage()."\n".$tester->getDisplay()); }`.

### IN-03: Cache-dir hash collides across parallel PHPUnit runs

**File:** `tests/Integration/ZeroConfigKernelBootTest.php:207-215`

**Issue:**
```php
public function getCacheDir(): string
{
    return sys_get_temp_dir().'/tenancy_bundle_test_'.md5(static::class).'_'.$this->environment.'/cache';
}
```

The hash key is purely `static::class` + environment — no PID, no run-id, no
random suffix. Two PHPUnit processes (`--parallel`, or two contributors on the
same CI runner) pick the same dir and race on container cache compilation.

**Fix:**
Append `getmypid()` or `uniqid('', true)` to the cache dir path. Or — better —
have the test use `$this->createMock` of Filesystem-isolated cache dirs.

### IN-04: `tearDownAfterClass` removes shared parent dir twice

**File:** `tests/Integration/ZeroConfigKernelBootTest.php:62-68`

**Issue:**
```php
$fs = new Filesystem();
foreach ([$cacheDir, $logDir] as $dir) {
    $parent = \dirname($dir);
    if ($fs->exists($parent)) {
        $fs->remove($parent);
    }
}
```

`getCacheDir()` and `getLogDir()` share the same parent
(`/tmp/tenancy_bundle_test_<hash>_<env>`), so the loop removes the same
directory twice (the second iteration is a no-op only because the first already
removed it). Cosmetic but misleading.

**Fix:**
```php
$parent = \dirname(static::$kernel->getCacheDir());
if ($fs->exists($parent)) {
    $fs->remove($parent);
}
```

### IN-05: `TenantWorkerMiddleware` references `TenantStamp::class` without an explicit import

**File:** `src/Messenger/TenantWorkerMiddleware.php:28`

**Issue:**
Line 28 references `TenantStamp::class` without an import. This is correct
because `TenantStamp` lives in the same namespace (`Tenancy\Bundle\Messenger`),
but the implicit reference is a quiet trap: if anyone moves `TenantStamp` to a
sub-namespace (`Tenancy\Bundle\Messenger\Stamp\`), this file will compile-error
in a non-obvious way. Every other class referenced in this file has an explicit
`use` statement, so the implicit one is also a consistency drift.

**Fix:**
Add `use Tenancy\Bundle\Messenger\TenantStamp;` for symmetry with the other
imports, even though it's technically redundant inside the same namespace.

## Narrative Findings (AI reviewer)

All findings above are narrative — no `<structural_findings>` block was provided
for this review. The gap-closure changes are scoped enough that structural
analysis would not add much beyond what's captured above.

---

_Reviewed: 2026-05-21_
_Reviewer: Claude (gsd-code-reviewer)_
_Depth: standard_
