---
slug: tenancy-install-null-provider
status: resolved
trigger: "Fresh-skeleton human UAT (Phase 18, 2026-05-21) failed: `composer require danplaton4/tenancy-bundle` on a clean Symfony 8.0/PHP 8.4 skeleton triggers Symfony Flex auto-recipe → bundle registered in bundles.php → cache:clear crashes with `ConsoleResolver::__construct(): Argument #1 ($tenantProvider) must be of type TenantProviderInterface, null given`. bin/console tenancy:install cannot run because the kernel cannot boot. Phase 18 install onboarding path is broken."
created: 2026-05-21T00:00:00Z
updated: 2026-06-02T12:26:12.645Z
resolved: 2026-06-02T12:26:12.645Z
mode: diagnose_only
related:
  - .planning/phases/18-tenancy-install/18-VERIFICATION.md
  - .planning/phases/18-tenancy-install/18-CONTEXT.md
---

## Resolution (2026-06-02 — reconciliation)

**RESOLVED.** Closed as CR-01 follow-up on 2026-05-21 (commit `31465dc` — "fix(18-followup): CR-01 — lock nullable-provider invariant with contract test"). The fix made the affected constructors accept a nullable provider with an explicit null-guard instead of a hard non-null type hint:

- `src/Resolver/ConsoleResolver.php:21` — constructor now `private readonly ?TenantProviderInterface $tenantProvider`, with a null-guard at line 31 that fails loudly only when the resolver is actually used without a provider (not at container build).
- `config/services.php` wires `service('tenancy.provider')->nullOnInvalid()`, so kernel boot / `cache:clear` no longer throws a `TypeError` on a fresh skeleton with no concrete provider yet.

Phase 18 human-UAT subsequently passed; v0.2.2 patch was readied. This file is retained for the root-cause record and archived out of the active debug queue.

## Symptoms

### Expected behavior
On a fresh Symfony skeleton:
1. `composer require danplaton4/tenancy-bundle` completes successfully.
2. Post-install scripts (including `cache:clear`) succeed.
3. `bin/console tenancy:install` runs, registers the bundle in `config/bundles.php`, writes `config/packages/tenancy.yaml`, prints "Next steps", exits 0.

### Actual behavior
1. `composer require` resolves the bundle and Flex's **auto-generated recipe** registers it in `config/bundles.php` (no published recipe exists per project decision — see Memory `feedback_no_flex.md`).
2. Post-install `cache:clear` **crashes**: container build fails with TypeError when constructing `ConsoleResolver`.
3. Any subsequent `bin/console` invocation — including `tenancy:install` itself — fails with the same error. The kernel cannot boot; the onboarding command is **unreachable**.

### Error messages
```
[critical] Error thrown while running command "cache:clear". Message:
"Tenancy\Bundle\Resolver\ConsoleResolver::__construct(): Argument #1 ($tenantProvider)
 must be of type Tenancy\Bundle\Provider\TenantProviderInterface, null given,
 called in /private/tmp/tenancy-install-uat/var/cache/dev/ContainerSdKnCfh/getConsoleResolverService.php on line 32"

In ConsoleResolver.php line 20:
  Tenancy\Bundle\Resolver\ConsoleResolver::__construct(): Argument #1 ($tenantProvider)
  must be of type TenantProviderInterface, null given ...
```

### Timeline
- Present at current master HEAD (commit `4c61fe5`, post-Phase 20 mailer-bootstrapper).
- Present at latest git tag `v0.2.1` — confirmed by source inspection (all non-nullable constructors at that tag match current HEAD; the `nullOnInvalid()` wiring predates all current tags).
- NOTE: Memory references "v1.0.0 released 2026-04-13" but the repo has no such tag. Latest tag is `v0.2.1`. The defect is present in v0.2.1.
- Resolver wiring with `->nullOnInvalid()` predates Phase 18; non-nullable constructors appear stable across all phases that introduced them.
- The full bundle test suite (545 tests, 2011 assertions) passes — the regression is invisible to the existing test kernels.

### Reproduction
```bash
rm -rf /tmp/tenancy-install-uat
cd /tmp && composer create-project symfony/skeleton tenancy-install-uat
cd tenancy-install-uat
composer config repositories.tenancy path /Users/danplaton/dev/tenancy-bundle-src
composer config minimum-stability dev
composer require danplaton4/tenancy-bundle:@dev
# → Symfony Flex auto-recipe adds bundle to config/bundles.php
# → cache:clear post-script crashes with the TypeError above
bin/console tenancy:install  # same TypeError; kernel cannot boot
```

Evidence captured at:
- `/tmp/tenancy-install-uat` (live broken environment)
- `/tmp/tu-fresh.txt` (failed install transcript)
- `/tmp/tu-out2.txt` (successful dry-run on a pre-configured demo for comparison)

Environment: Symfony 8.0.x (auto-resolved via skeleton), PHP 8.4.12, Composer 2.9.5, macOS 25.4.0.

## Current Focus

```
hypothesis: |
  CONFIRMED. Contract mismatch: config/services.php injects `tenancy.provider` via
  `->nullOnInvalid()` (which resolves to null when no provider service is
  bound, i.e. zero-config installs) into resolvers whose constructors declare
  non-nullable `TenantProviderInterface` parameters. PHP throws TypeError
  before the kernel can boot.

  Full defect inventory — all 5 non-nullable sites confirmed:
    - HostResolver          — services.php:68 nullOnInvalid → src/Resolver/HostResolver.php:15 non-nullable
    - HeaderResolver        — services.php:74 nullOnInvalid → src/Resolver/HeaderResolver.php:17 non-nullable
    - QueryParamResolver    — services.php:78 nullOnInvalid → src/Resolver/QueryParamResolver.php:17 non-nullable
    - ConsoleResolver       — services.php:84 nullOnInvalid → src/Resolver/ConsoleResolver.php:21 non-nullable
    - TenantRunCommand      — services.php:123 nullOnInvalid → src/Command/TenantRunCommand.php:19 non-nullable
    - TenantWorkerMiddleware— services.php:153 nullOnInvalid → src/Messenger/TenantWorkerMiddleware.php:21 non-nullable

  3 nullOnInvalid sites are ALREADY SAFE (nullable constructor + null guard):
    - TenancyInstallCommand/mailer_step — services.php:140 → ?MailerSetupStep (nullable, safe)
    - MailerBootstrapper/lru_cache      — services.php:167 → ?LruTransportCache (nullable, safe)
    - TenantAwareTransportsDecorator    — services.php:187 → ?TenantProviderInterface (nullable + null guard in buildAndCache(), safe)

  Root cause: the original intent of nullOnInvalid() was "tolerate optional Doctrine
  provider" but was never propagated as nullable type + null guard to the constructor
  of each consuming class. When no tenancy.provider binding exists (no tenancy.yaml,
  no Doctrine), the DI container passes null to a non-nullable PHP 8.x typed param.

test: |
  Sweep complete — all 9 nullOnInvalid() sites in config/services.php verified.

expecting: |
  6 non-nullable defects confirmed (5 provider injections + DoctrineBootstrapper
  is guarded by interface_exists so does NOT apply). See resolution below.

next_action: |
  DONE — root cause confirmed. Pass to /gsd:plan-phase 18 --gaps for fix planning.

reasoning_checkpoint: |
  Why does the existing integration test suite miss this? Two compounding reasons:
  1. CommandTestKernel (and all subclass kernels) inject a TenancyBundle config block
     via registerContainerConfiguration() that includes 'driver', 'database', and
     'tenant_entity_class' — so tenancy.provider IS always defined in tests.
  2. ReplaceTenancyProviderPass (line 19) contains an early return: `if (!$container->
     hasDefinition('tenancy.provider')) { return; }`. In zero-config skeleton, this
     pass would silently skip (provider is never defined), doing nothing to prevent
     the null injection. Even if the pass ran, it replaces the provider — it does not
     test the ABSENCE of a provider.
  These conditions only surface when:
    a) The bundle is registered in bundles.php
    b) No tenancy.yaml provides a tenancy.provider service binding
    c) Doctrine ORM is not installed (no interface_exists guard triggers)

tdd_checkpoint: |
  Before any fix is applied (by /gsd:plan-phase --gaps later), the
  regression-test specification should land first. Recommended test
  shape: a container-build test that boots a kernel registering the
  bundle but providing NO tenancy.yaml config at all (no 'tenancy'
  extension block loaded), asserting the container compiles cleanly
  and the kernel boots. This is the missing canary.
```

## Evidence

- timestamp: 2026-05-21T00:00:00Z
  finding: "Repro confirmed on fresh symfony/skeleton (Symfony 8.0.x, PHP 8.4.12). Composer Flex auto-recipe added `Tenancy\Bundle\TenancyBundle::class => ['all' => true]` to config/bundles.php; cache:clear post-script crashed with the documented TypeError."
  evidence_path: "/tmp/tenancy-install-uat (broken environment), /tmp/tu-fresh.txt (transcript)"

- timestamp: 2026-05-21T00:00:00Z
  finding: "Workaround attempt (manually remove TenancyBundle from bundles.php) makes `bin/console` boot again BUT removes the `tenancy:install` command from the registry — confirming the chicken-and-egg: bundle must be registered for the command to exist, but registered + zero config = TypeError."
  evidence_path: "Manual test transcript; observation captured in 18-VERIFICATION.md"

- timestamp: 2026-05-21T00:00:00Z
  finding: "Same command works cleanly against a pre-configured project (/Users/danplaton/dev/hype/tests/symfony8x-demo with valid tenancy.yaml + nikic/php-parser): `bin/console tenancy:install --dry-run` exits 0, prints `[NOTE] already registered`, `[NOTE] tenancy.yaml already exists`, and `Next steps`. The installer logic itself is correct; only bundle bootability with zero config is broken."
  evidence_path: "/tmp/tu-out2.txt"

- timestamp: 2026-05-21T00:00:00Z
  finding: "Contract mismatch verified by source inspection: config/services.php lines 68, 74, 78, 84, 123, 153 wire HostResolver/HeaderResolver/QueryParamResolver/ConsoleResolver/TenantRunCommand/TenantWorkerMiddleware with service('tenancy.provider')->nullOnInvalid(), while all six constructors declare private readonly TenantProviderInterface $tenantProvider (non-nullable). PHP 8.x enforces this at instantiation time."
  evidence_path: "config/services.php; src/Resolver/{HostResolver,HeaderResolver,QueryParamResolver,ConsoleResolver}.php; src/Command/TenantRunCommand.php; src/Messenger/TenantWorkerMiddleware.php"

- timestamp: 2026-05-21T00:00:00Z
  finding: "3 other nullOnInvalid sites are already safe: TenancyInstallCommand receives ?MailerSetupStep (nullable, line 140); MailerBootstrapper receives ?LruTransportCache (nullable with null-safe operator on clear(), line 167); TenantAwareTransportsDecorator receives ?TenantProviderInterface (nullable with explicit null guard in buildAndCache(), line 187). These were implemented correctly."
  evidence_path: "config/services.php:140,167,187; src/Command/TenancyInstallCommand.php:42; src/Bootstrapper/MailerBootstrapper.php:27; src/Mailer/TenantAwareTransportsDecorator.php:53,131"

- timestamp: 2026-05-21T00:00:00Z
  finding: "Test suite gap confirmed: CommandTestKernel always loads a 'tenancy' extension config block (driver, database, tenant_entity_class), ensuring tenancy.provider IS defined. ReplaceTenancyProviderPass has an early return when tenancy.provider does not exist. No test exercises kernel boot with zero tenancy config. Tag v0.2.1 (latest) has identical non-nullable constructors — defect present since initial implementation."
  evidence_path: "tests/Integration/Command/Support/CommandTestKernel.php:107-112; tests/Integration/Support/ReplaceTenancyProviderPass.php:19-21"

## Eliminated

- Installer logic bugs: the tenancy:install command itself executes correctly when the kernel can boot (verified against symfony8x-demo). Problem is pre-boot, not in command logic.
- Flex recipe auto-config: Flex behavior is working as expected (per memory feedback_no_flex.md — no recipe is published). The bundle auto-registration is the trigger, not the root cause.
- Symfony version regression: same wiring defect is present at v0.2.1 (oldest available tag), so this is not a Symfony 8.0 compatibility regression — it was always broken in zero-config scenarios.

## Resolution

root_cause: |
  In config/services.php, six consumer services receive service('tenancy.provider')->nullOnInvalid()
  but their constructors declare non-nullable TenantProviderInterface parameters. When the bundle
  is registered with no tenancy configuration (zero-config fresh skeleton), tenancy.provider is
  never bound, nullOnInvalid() resolves to null, and PHP 8.x throws TypeError at container
  instantiation — preventing kernel boot entirely.

  Affected files and precise locations:
    1. src/Resolver/HostResolver.php:15       — TenantProviderInterface $tenantProvider (non-nullable)
    2. src/Resolver/HeaderResolver.php:17     — TenantProviderInterface $tenantProvider (non-nullable)
    3. src/Resolver/QueryParamResolver.php:17 — TenantProviderInterface $tenantProvider (non-nullable)
    4. src/Resolver/ConsoleResolver.php:21    — TenantProviderInterface $tenantProvider (non-nullable)
    5. src/Command/TenantRunCommand.php:19    — TenantProviderInterface $tenantProvider (non-nullable)
    6. src/Messenger/TenantWorkerMiddleware.php:21 — TenantProviderInterface $tenantProvider (non-nullable)

fix_surface: |
  For each of the 6 defect sites, two coordinated changes are needed:
    A. Constructor parameter: change `TenantProviderInterface $param` to `?TenantProviderInterface $param = null`
    B. Entry method: add null guard at the top — if provider is null, return early (resolvers return null;
       TenantRunCommand/TenantWorkerMiddleware throw a clear ConfigurationException or return early with
       an error message).

  The null guard behavior per class:
    - HostResolver::resolve()         → return null (provider absent = cannot resolve = pass to next)
    - HeaderResolver::resolve()       → return null (same rationale)
    - QueryParamResolver::resolve()   → return null (same rationale)
    - ConsoleResolver::onConsoleCommand() → return early/no-op (no tenant context without provider)
    - TenantRunCommand::execute()     → throw clear exception: "No TenantProviderInterface configured.
                                        Install and configure tenancy.yaml before using tenancy:run."
    - TenantWorkerMiddleware::handle() → throw or log + pass-through: provider absent means no
                                         tenant context available; safest is throw so messages fail
                                         explicitly rather than silently skip tenant context.

  No changes needed to config/services.php (nullOnInvalid() is already the correct DI intent).
  No changes needed to TenantAwareTransportsDecorator, MailerBootstrapper, TenancyInstallCommand
  (already nullable + guarded correctly).

regression_test: |
  New test: tests/Integration/BareBootTest.php (or ZeroConfigKernelBootTest.php)
  Kernel: a minimal test kernel that registers TenancyBundle but loads NO tenancy extension config.
  Assert: container compiles without error (no TypeError thrown).
  Assert: kernel->getContainer() is reachable.
  Assert: bin/console --version equivalent (Application::run(['--version'])) exits 0.
  This test must fail on current master and pass after the fix — it is the canary for this class of regression.

test_suite_gap: |
  Every existing integration test kernel loads a 'tenancy' config block that includes
  'tenant_entity_class', which causes the bundle extension to conditionally bind
  tenancy.provider. This means nullOnInvalid() always resolves to a real (or stub)
  service — the null path is never exercised. Additionally, ReplaceTenancyProviderPass
  has an early return when the provider is not defined, so even if it were used in a
  no-config kernel it would silently skip rather than expose the gap.

tag_risk: |
  Tag v0.2.1 (latest published tag, memory references "v1.0.0" but no such tag exists in git)
  is AFFECTED. The constructor signatures and services.php wiring at v0.2.1 are identical
  to current master for all 6 defect sites. The bug has been present since the initial
  resolver implementations. Any user who composer-requires the bundle and has it auto-added
  to bundles.php (via Flex or manually) before running tenancy:install will hit this.
