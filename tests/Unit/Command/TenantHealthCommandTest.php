<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Unit\Command;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Tenancy\Bundle\Command\TenantHealthCommand;
use Tenancy\Bundle\Exception\TenantNotFoundException;
use Tenancy\Bundle\Health\BootstrapperHealthResult;
use Tenancy\Bundle\Health\HealthResponseSanitizer;
use Tenancy\Bundle\Health\HealthStatus;
use Tenancy\Bundle\Health\TenantHealthCheckerInterface;
use Tenancy\Bundle\Health\TenantHealthReport;
use Tenancy\Bundle\Provider\TenantProviderInterface;
use Tenancy\Bundle\TenantInterface;

/**
 * Unit tests for TenantHealthCommand (HEALTH-05).
 *
 * No kernel boot — all dependencies are mocks or real lightweight value objects.
 * CommandTester drives execute().
 *
 * Coverage:
 *   Task 1: option validation (format), null-provider guard, unknown slug, no-scope given
 *   Task 2: streaming txt output, exit aggregation (D-09), --format=json aggregate, DSN redaction
 */
final class TenantHealthCommandTest extends TestCase
{
    private TenantProviderInterface&MockObject $tenantProvider;
    private TenantHealthCheckerInterface&MockObject $checker;
    private HealthResponseSanitizer $sanitizer;

    protected function setUp(): void
    {
        $this->tenantProvider = $this->createMock(TenantProviderInterface::class);
        $this->checker = $this->createMock(TenantHealthCheckerInterface::class);
        $this->sanitizer = new HealthResponseSanitizer();
    }

    private function makeCommand(?TenantProviderInterface $provider = null): TenantHealthCommand
    {
        return new TenantHealthCommand(
            $provider ?? $this->tenantProvider,
            $this->checker,
            $this->sanitizer,
        );
    }

    private function makeTenant(string $slug): TenantInterface&MockObject
    {
        $tenant = $this->createMock(TenantInterface::class);
        $tenant->method('getSlug')->willReturn($slug);

        return $tenant;
    }

    private function makeReport(string $slug, HealthStatus $status, ?string $output = null): TenantHealthReport
    {
        $results = [];
        if (null !== $output) {
            $results[] = new BootstrapperHealthResult('SomeBootstrapper', $status, $output);
        } else {
            $results[] = BootstrapperHealthResult::pass('SomeBootstrapper');
        }

        return new TenantHealthReport($slug, $status, $results);
    }

    // -----------------------------------------------------------------------
    // Task 1: Option and scope validation
    // -----------------------------------------------------------------------

    public function testInvalidFormatReturnsFailureWithoutInvokingChecker(): void
    {
        $this->checker->expects($this->never())->method('checkOne');
        $this->tenantProvider->expects($this->never())->method('findAll');
        $this->tenantProvider->expects($this->never())->method('findBySlug');

        $tester = new CommandTester($this->makeCommand());
        $exitCode = $tester->execute(['--format' => 'xml']);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('xml', $tester->getDisplay());
    }

    public function testNullProviderReturnsFailureWithClearError(): void
    {
        $this->checker->expects($this->never())->method('checkOne');

        $command = new TenantHealthCommand(null, $this->checker, $this->sanitizer);
        $tester = new CommandTester($command);
        $exitCode = $tester->execute(['--all' => true]);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('provider', strtolower($tester->getDisplay()));
    }

    public function testUnknownTenantSlugReturnsFailureWithClearError(): void
    {
        $this->tenantProvider
            ->method('findBySlug')
            ->with('nonexistent')
            ->willThrowException(new TenantNotFoundException('Tenant "nonexistent" not found.'));

        $this->checker->expects($this->never())->method('checkOne');

        $tester = new CommandTester($this->makeCommand());
        $exitCode = $tester->execute(['--tenant' => 'nonexistent']);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('nonexistent', $tester->getDisplay());
    }

    public function testNoScopeGivenReturnsFailureWithUsageGuidance(): void
    {
        $this->checker->expects($this->never())->method('checkOne');
        $this->tenantProvider->expects($this->never())->method('findAll');
        $this->tenantProvider->expects($this->never())->method('findBySlug');

        $tester = new CommandTester($this->makeCommand());
        $exitCode = $tester->execute([]);

        $this->assertSame(Command::FAILURE, $exitCode);
        // Should mention --tenant and/or --all
        $display = $tester->getDisplay();
        $this->assertTrue(
            str_contains($display, '--tenant') || str_contains($display, '--all'),
            'Expected usage guidance mentioning --tenant or --all in: '.$display
        );
    }

    public function testCommandDoesNotDependOnTenantContext(): void
    {
        // Structural assertion: TenantHealthCommand must not import TenantContext (no `use` statement).
        // The checker owns the probe lifecycle (set->probe->clear-in-finally).
        // Comments/docblocks may mention TenantContext by name — only the `use` statement matters.
        $source = file_get_contents(__DIR__.'/../../../src/Command/TenantHealthCommand.php') ?: '';
        $this->assertDoesNotMatchRegularExpression(
            '/^use\s+.*TenantContext\s*;/m',
            $source,
            'TenantHealthCommand must not have a `use` statement for TenantContext — the checker owns the lifecycle',
        );
    }

    public function testCommandIsNamedTenancyHealth(): void
    {
        $command = $this->makeCommand();
        $this->assertSame('tenancy:health', $command->getName());
    }

    // -----------------------------------------------------------------------
    // Task 2: Streaming output, exit aggregation, JSON, sanitization
    // -----------------------------------------------------------------------

    public function testAllPassFleetExitsSuccess(): void
    {
        $tenantA = $this->makeTenant('acme');
        $tenantB = $this->makeTenant('beta');

        $this->tenantProvider->method('findAll')->willReturn([$tenantA, $tenantB]);

        $this->checker
            ->method('checkOne')
            ->willReturnCallback(static function (TenantInterface $t) {
                return new TenantHealthReport($t->getSlug(), HealthStatus::Pass, [BootstrapperHealthResult::pass('Bootstrapper')]);
            });

        $tester = new CommandTester($this->makeCommand());
        $exitCode = $tester->execute(['--all' => true]);

        $this->assertSame(Command::SUCCESS, $exitCode);
    }

    public function testMixedFleetWithOneFailExitsFailure(): void
    {
        $tenantA = $this->makeTenant('acme');
        $tenantB = $this->makeTenant('beta');
        $tenantC = $this->makeTenant('gamma');

        $this->tenantProvider->method('findAll')->willReturn([$tenantA, $tenantB, $tenantC]);

        $this->checker
            ->method('checkOne')
            ->willReturnCallback(static function (TenantInterface $t): TenantHealthReport {
                if ('beta' === $t->getSlug()) {
                    return new TenantHealthReport('beta', HealthStatus::Fail, [
                        BootstrapperHealthResult::fail('DBBootstrapper', 'Connection failed'),
                    ]);
                }

                return new TenantHealthReport($t->getSlug(), HealthStatus::Pass, [BootstrapperHealthResult::pass('Bootstrapper')]);
            });

        $tester = new CommandTester($this->makeCommand());
        $exitCode = $tester->execute(['--all' => true]);

        $this->assertSame(Command::FAILURE, $exitCode);

        $display = $tester->getDisplay();
        // Pass tenants should show ✓, fail tenant should show ✗
        $this->assertStringContainsString('acme', $display);
        $this->assertStringContainsString('beta', $display);
        $this->assertStringContainsString('gamma', $display);
    }

    public function testSingleTenantHealthCheck(): void
    {
        $tenant = $this->makeTenant('acme');
        $this->tenantProvider->method('findBySlug')->with('acme')->willReturn($tenant);

        $this->checker
            ->expects($this->once())
            ->method('checkOne')
            ->with($tenant)
            ->willReturn(new TenantHealthReport('acme', HealthStatus::Pass, [
                BootstrapperHealthResult::pass('DBBootstrapper'),
            ]));

        $tester = new CommandTester($this->makeCommand());
        $exitCode = $tester->execute(['--tenant' => 'acme']);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('acme', $tester->getDisplay());
    }

    public function testJsonFormatProducesParseableAggregate(): void
    {
        $tenantA = $this->makeTenant('acme');
        $tenantB = $this->makeTenant('beta');

        $this->tenantProvider->method('findAll')->willReturn([$tenantA, $tenantB]);

        $this->checker
            ->method('checkOne')
            ->willReturnCallback(static function (TenantInterface $t): TenantHealthReport {
                return new TenantHealthReport($t->getSlug(), HealthStatus::Pass, [BootstrapperHealthResult::pass('B')]);
            });

        $tester = new CommandTester($this->makeCommand());
        $exitCode = $tester->execute(['--all' => true, '--format' => 'json']);

        $this->assertSame(Command::SUCCESS, $exitCode);

        $display = trim($tester->getDisplay());
        $decoded = json_decode($display, true, 512, \JSON_THROW_ON_ERROR);

        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('tenants', $decoded);
        $this->assertArrayHasKey('summary', $decoded);

        /** @var array{pass: int, warn: int, fail: int, total: int} $summary */
        $summary = $decoded['summary'];
        $this->assertArrayHasKey('pass', $summary);
        $this->assertArrayHasKey('warn', $summary);
        $this->assertArrayHasKey('fail', $summary);
        $this->assertArrayHasKey('total', $summary);

        $this->assertSame(2, $summary['total']);
        $this->assertSame(2, $summary['pass']);
        $this->assertSame(0, $summary['fail']);
    }

    public function testJsonFormatWithFailingTenantExitsFailure(): void
    {
        $tenant = $this->makeTenant('acme');
        $this->tenantProvider->method('findAll')->willReturn([$tenant]);

        $this->checker
            ->method('checkOne')
            ->willReturn(new TenantHealthReport('acme', HealthStatus::Fail, [
                BootstrapperHealthResult::fail('DBBootstrapper', 'DB down'),
            ]));

        $tester = new CommandTester($this->makeCommand());
        $exitCode = $tester->execute(['--all' => true, '--format' => 'json']);

        $this->assertSame(Command::FAILURE, $exitCode);

        $decoded = json_decode(trim($tester->getDisplay()), true, 512, \JSON_THROW_ON_ERROR);
        $this->assertSame(1, $decoded['summary']['fail']);
    }

    public function testDsnPasswordIsRedactedInTxtOutput(): void
    {
        $tenant = $this->makeTenant('acme');
        $this->tenantProvider->method('findAll')->willReturn([$tenant]);

        $this->checker
            ->method('checkOne')
            ->willReturn(new TenantHealthReport('acme', HealthStatus::Fail, [
                BootstrapperHealthResult::fail('DBBootstrapper', 'mysql://user:s3cr3t@host/db connection refused'),
            ]));

        $tester = new CommandTester($this->makeCommand());
        $tester->execute(['--all' => true]);

        $display = $tester->getDisplay();
        $this->assertStringNotContainsString('s3cr3t', $display, 'Raw DSN password must be redacted in txt output');
        $this->assertStringContainsString('***', $display, 'Redacted placeholder must appear in txt output');
    }

    public function testDsnPasswordIsRedactedInJsonOutput(): void
    {
        $tenant = $this->makeTenant('acme');
        $this->tenantProvider->method('findAll')->willReturn([$tenant]);

        $this->checker
            ->method('checkOne')
            ->willReturn(new TenantHealthReport('acme', HealthStatus::Fail, [
                BootstrapperHealthResult::fail('DBBootstrapper', 'mysql://user:s3cr3t@host/db connection refused'),
            ]));

        $tester = new CommandTester($this->makeCommand());
        $tester->execute(['--all' => true, '--format' => 'json']);

        $rawOutput = $tester->getDisplay();
        $this->assertStringNotContainsString('s3cr3t', $rawOutput, 'Raw DSN password must be redacted in json output');
    }

    public function testCheckerIsCalledOncePerTenantNoContextClear(): void
    {
        // The command must delegate entirely to checkOne() — no TenantContext::clear() in the loop.
        $tenantA = $this->makeTenant('acme');
        $tenantB = $this->makeTenant('beta');

        $this->tenantProvider->method('findAll')->willReturn([$tenantA, $tenantB]);

        $this->checker
            ->expects($this->exactly(2))
            ->method('checkOne')
            ->willReturnCallback(static function (TenantInterface $t): TenantHealthReport {
                return new TenantHealthReport($t->getSlug(), HealthStatus::Pass, [BootstrapperHealthResult::pass('B')]);
            });

        $tester = new CommandTester($this->makeCommand());
        $tester->execute(['--all' => true]);

        // If we reached here without error and checkOne was called exactly twice, the loop is correct.
        // The "no TenantContext::clear()" assertion is a source assertion in testCommandDoesNotDependOnTenantContext.
    }

    public function testWarnStatusDoesNotCauseFailureExitCode(): void
    {
        // Warn is not a failure for exit-code purposes — only Fail triggers non-zero exit.
        $tenant = $this->makeTenant('acme');
        $this->tenantProvider->method('findAll')->willReturn([$tenant]);

        $this->checker
            ->method('checkOne')
            ->willReturn(new TenantHealthReport('acme', HealthStatus::Warn, [
                new BootstrapperHealthResult('SomeBootstrapper', HealthStatus::Warn, 'High latency'),
            ]));

        $tester = new CommandTester($this->makeCommand());
        $exitCode = $tester->execute(['--all' => true]);

        // Warn => exit 0 (not a hard failure per D-09)
        $this->assertSame(Command::SUCCESS, $exitCode);
    }
}
