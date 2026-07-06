<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Unit\Health;

use PHPUnit\Framework\TestCase;
use Tenancy\Bundle\Health\BootstrapperHealthResult;
use Tenancy\Bundle\Health\HealthStatus;
use Tenancy\Bundle\Health\TenantHealthReport;

final class TenantHealthReportTest extends TestCase
{
    // fromResults() — worst-of status derivation

    public function testFromResultsAllPassYieldsPass(): void
    {
        $results = [
            BootstrapperHealthResult::pass('A'),
            BootstrapperHealthResult::pass('B'),
        ];

        $report = TenantHealthReport::fromResults('acme', $results);

        $this->assertSame(HealthStatus::Pass, $report->status);
    }

    public function testFromResultsWithFailYieldsFail(): void
    {
        $results = [
            BootstrapperHealthResult::pass('A'),
            BootstrapperHealthResult::fail('B', 'db down'),
        ];

        $report = TenantHealthReport::fromResults('acme', $results);

        $this->assertSame(HealthStatus::Fail, $report->status);
    }

    public function testFromResultsWithWarnYieldsWarn(): void
    {
        $results = [
            BootstrapperHealthResult::pass('A'),
            new BootstrapperHealthResult('B', HealthStatus::Warn),
        ];

        $report = TenantHealthReport::fromResults('acme', $results);

        $this->assertSame(HealthStatus::Warn, $report->status);
    }

    public function testFromResultsWithWarnAndFailYieldsFail(): void
    {
        $results = [
            new BootstrapperHealthResult('A', HealthStatus::Warn),
            BootstrapperHealthResult::fail('B', 'db down'),
        ];

        $report = TenantHealthReport::fromResults('acme', $results);

        $this->assertSame(HealthStatus::Fail, $report->status);
    }

    public function testFromResultsEmptyYieldsPass(): void
    {
        $report = TenantHealthReport::fromResults('acme', []);

        $this->assertSame(HealthStatus::Pass, $report->status);
    }

    public function testFromResultsPreservesSlug(): void
    {
        $report = TenantHealthReport::fromResults('my-tenant', []);

        $this->assertSame('my-tenant', $report->slug);
    }

    public function testFromResultsPreservesResults(): void
    {
        $passResult = BootstrapperHealthResult::pass('A');
        $failResult = BootstrapperHealthResult::fail('B', 'err');

        $report = TenantHealthReport::fromResults('acme', [$passResult, $failResult]);

        $this->assertCount(2, $report->results);
        $this->assertSame($passResult, $report->results[0]);
        $this->assertSame($failResult, $report->results[1]);
    }

    // fromException() — wraps in a single Fail result

    public function testFromExceptionYieldsFailStatus(): void
    {
        $e = new \RuntimeException('probe error');

        $report = TenantHealthReport::fromException('acme', $e);

        $this->assertSame(HealthStatus::Fail, $report->status);
    }

    public function testFromExceptionPreservesSlug(): void
    {
        $e = new \RuntimeException('err');

        $report = TenantHealthReport::fromException('acme', $e);

        $this->assertSame('acme', $report->slug);
    }

    public function testFromExceptionResultsContainsOneFailResult(): void
    {
        $e = new \RuntimeException('probe error');

        $report = TenantHealthReport::fromException('acme', $e);

        $this->assertCount(1, $report->results);
        $this->assertSame(HealthStatus::Fail, $report->results[0]->status);
    }

    public function testFromExceptionResultCarriesException(): void
    {
        $e = new \RuntimeException('probe error');

        $report = TenantHealthReport::fromException('acme', $e);

        $this->assertSame($e, $report->results[0]->exception);
    }
}
