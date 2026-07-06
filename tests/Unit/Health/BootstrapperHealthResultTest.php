<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Unit\Health;

use PHPUnit\Framework\TestCase;
use Tenancy\Bundle\Health\BootstrapperHealthResult;
use Tenancy\Bundle\Health\HealthStatus;

final class BootstrapperHealthResultTest extends TestCase
{
    // HealthStatus enum value assertions

    public function testHealthStatusPassValue(): void
    {
        $this->assertSame('pass', HealthStatus::Pass->value);
    }

    public function testHealthStatusWarnValue(): void
    {
        $this->assertSame('warn', HealthStatus::Warn->value);
    }

    public function testHealthStatusFailValue(): void
    {
        $this->assertSame('fail', HealthStatus::Fail->value);
    }

    public function testHealthStatusFromPassString(): void
    {
        $this->assertSame(HealthStatus::Pass, HealthStatus::from('pass'));
    }

    public function testHealthStatusFromWarnString(): void
    {
        $this->assertSame(HealthStatus::Warn, HealthStatus::from('warn'));
    }

    public function testHealthStatusFromFailString(): void
    {
        $this->assertSame(HealthStatus::Fail, HealthStatus::from('fail'));
    }

    // BootstrapperHealthResult::pass() named constructor

    public function testPassNamedConstructorHasPassStatus(): void
    {
        $result = BootstrapperHealthResult::pass('SomeBootstrapper');

        $this->assertSame(HealthStatus::Pass, $result->status);
    }

    public function testPassNamedConstructorHasNullOutput(): void
    {
        $result = BootstrapperHealthResult::pass('SomeBootstrapper');

        $this->assertNull($result->output);
    }

    public function testPassNamedConstructorHasNullException(): void
    {
        $result = BootstrapperHealthResult::pass('SomeBootstrapper');

        $this->assertNull($result->exception);
    }

    public function testPassNamedConstructorPreservesComponentClass(): void
    {
        $result = BootstrapperHealthResult::pass('MyBootstrapper');

        $this->assertSame('MyBootstrapper', $result->componentClass);
    }

    // BootstrapperHealthResult::fail() named constructor

    public function testFailNamedConstructorHasFailStatus(): void
    {
        $result = BootstrapperHealthResult::fail('SomeBootstrapper', 'boom');

        $this->assertSame(HealthStatus::Fail, $result->status);
    }

    public function testFailNamedConstructorHasOutput(): void
    {
        $result = BootstrapperHealthResult::fail('SomeBootstrapper', 'boom');

        $this->assertSame('boom', $result->output);
    }

    public function testFailNamedConstructorHasNullExceptionByDefault(): void
    {
        $result = BootstrapperHealthResult::fail('SomeBootstrapper', 'boom');

        $this->assertNull($result->exception);
    }

    public function testFailNamedConstructorCarriesException(): void
    {
        $e = new \RuntimeException('connection refused');
        $result = BootstrapperHealthResult::fail('SomeBootstrapper', 'boom', $e);

        $this->assertSame($e, $result->exception);
    }

    // BootstrapperHealthResult::fromException() named constructor

    public function testFromExceptionHasFailStatus(): void
    {
        $e = new \RuntimeException('something went wrong');
        $result = BootstrapperHealthResult::fromException('SomeBootstrapper', $e);

        $this->assertSame(HealthStatus::Fail, $result->status);
    }

    public function testFromExceptionOutputIsExceptionMessage(): void
    {
        $e = new \RuntimeException('something went wrong');
        $result = BootstrapperHealthResult::fromException('SomeBootstrapper', $e);

        $this->assertSame('something went wrong', $result->output);
    }

    public function testFromExceptionCarriesException(): void
    {
        $e = new \RuntimeException('connection failed');
        $result = BootstrapperHealthResult::fromException('SomeBootstrapper', $e);

        $this->assertSame($e, $result->exception);
    }

    public function testFromExceptionPreservesComponentClass(): void
    {
        $e = new \RuntimeException('error');
        $result = BootstrapperHealthResult::fromException('MyBootstrapper', $e);

        $this->assertSame('MyBootstrapper', $result->componentClass);
    }
}
