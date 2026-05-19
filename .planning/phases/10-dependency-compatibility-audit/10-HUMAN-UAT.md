---
status: complete
phase: 10-dependency-compatibility-audit
source: [10-VERIFICATION.md]
started: 2026-04-10T00:00:00Z
updated: 2026-04-11T00:00:00Z
---

## Current Test

[testing complete]

## Tests

### 1. prefer-lowest CI job runtime validation
expected: Push to GitHub Actions; `prefer-lowest` job runs PHP 8.2, `SYMFONY_REQUIRE=7.4.*`, `--prefer-lowest --prefer-stable`, and `vendor/bin/phpunit` exits 0 with oldest stable dependencies
result: issue
reported: "6 integration test errors: DoctrineBundle 2.13 (resolved by prefer-lowest) does not recognize enable_native_lazy_objects config key. Unrecognized option error in CacheBootstrapperIntegrationTest, DatabaseSwitchIntegrationTest, DoctrineBootstrapperIntegrationTest, EntityManagerResetIntegrationTest, SharedDbFilterIntegrationTest, InteractsWithTenancyTest."
severity: blocker
fix: Conditional enable_native_lazy_objects via separate loadFromExtension call, gated on DoctrineBundle >= 2.14 via Composer\InstalledVersions

### 2. no-messenger CI job runtime validation
expected: Push to GitHub Actions; `no-messenger` job runs PHP 8.2, `composer remove --dev symfony/messenger` succeeds, and `vendor/bin/phpunit` exits 0 with messenger-related unit dirs excluded, proving `interface_exists(MessageBusInterface::class)` guards hold
result: pass

### 3. PHP 8.4 + Symfony 8.0 CI job runtime validation
expected: Push to GitHub Actions; PHP 8.4 + Symfony 8.0 job runs `vendor/bin/phpunit` and exits 0
result: issue
reported: "5 errors in ConsoleResolverTest: Call to undefined method Symfony\Component\Console\Application::add() — method removed in Symfony 8.0, deprecated since 7.4 in favor of addCommand()."
severity: blocker
fix: Replaced Application::add() with Application::addCommand() in ConsoleResolverTest (available in Symfony 7.4+)

## Summary

total: 3
passed: 1
issues: 2
pending: 0
skipped: 0
blocked: 0

## Gaps

- truth: "prefer-lowest CI job should pass with oldest stable dependencies"
  status: fixed
  reason: "User reported: 6 integration test errors from unrecognized enable_native_lazy_objects config key on DoctrineBundle 2.13"
  severity: blocker
  test: 1
  root_cause: "enable_native_lazy_objects config key unconditionally included in 4 test kernels; DoctrineBundle 2.13.x (prefer-lowest floor) doesn't support this key"
  artifacts:
    - path: "tests/Integration/Support/DoctrineTestKernel.php"
      issue: "Unconditional enable_native_lazy_objects config"
    - path: "tests/Integration/Support/SharedDbTestKernel.php"
      issue: "Unconditional enable_native_lazy_objects config"
    - path: "tests/Integration/Support/BootstrapperTestKernel.php"
      issue: "Unconditional enable_native_lazy_objects config"
    - path: "tests/Integration/Testing/Support/TenancyTestKernel.php"
      issue: "Unconditional enable_native_lazy_objects config"
  missing:
    - "Conditional loadFromExtension gated on DoctrineBundle >= 2.14 via Composer\\InstalledVersions"

- truth: "PHP 8.4 + Symfony 8.0 CI job should pass"
  status: fixed
  reason: "User reported: 5 errors — Application::add() removed in Symfony 8.0"
  severity: blocker
  test: 3
  root_cause: "ConsoleResolverTest uses Application::add() which was deprecated in Symfony 7.4 and removed in 8.0"
  artifacts:
    - path: "tests/Unit/Resolver/ConsoleResolverTest.php"
      issue: "Application::add() calls on lines 81 and 99"
  missing:
    - "Replace add() with addCommand() (available since Symfony 7.4)"
