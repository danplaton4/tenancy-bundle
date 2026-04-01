<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Unit\Command;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Process\Process;
use Tenancy\Bundle\Command\TenantRunCommand;
use Tenancy\Bundle\Exception\TenantNotFoundException;
use Tenancy\Bundle\Provider\TenantProviderInterface;
use Tenancy\Bundle\TenantInterface;

final class TenantRunCommandTest extends TestCase
{
    /** @var TenantProviderInterface&MockObject */
    private TenantProviderInterface $tenantProvider;

    protected function setUp(): void
    {
        $this->tenantProvider = $this->createMock(TenantProviderInterface::class);
    }

    public function testValidTenantSpawnsProcess(): void
    {
        $tenant = $this->createMock(TenantInterface::class);
        $this->tenantProvider
            ->expects($this->once())
            ->method('findBySlug')
            ->with('acme')
            ->willReturn($tenant);

        $capturedCommand = null;

        /** @var Process&MockObject $processMock */
        $processMock = $this->createMock(Process::class);
        $processMock->method('run')->willReturn(0);
        $processMock->method('getExitCode')->willReturn(0);

        $processFactory = function (string $commandLine) use ($processMock, &$capturedCommand): Process {
            $capturedCommand = $commandLine;

            return $processMock;
        };

        $command = new TenantRunCommand($this->tenantProvider, '/app', $processFactory);
        $tester = new CommandTester($command);
        $tester->execute(['tenant' => 'acme', 'command_string' => 'app:some-command']);

        $this->assertNotNull($capturedCommand);
        $this->assertStringContainsString('--tenant=acme', $capturedCommand);
        $this->assertStringContainsString('app:some-command', $capturedCommand);
        $this->assertStringContainsString('/app/bin/console', $capturedCommand);
    }

    public function testChildExitCodePropagated(): void
    {
        $tenant = $this->createMock(TenantInterface::class);
        $this->tenantProvider
            ->method('findBySlug')
            ->willReturn($tenant);

        /** @var Process&MockObject $processMock */
        $processMock = $this->createMock(Process::class);
        $processMock->method('run')->willReturn(42);
        $processMock->method('getExitCode')->willReturn(42);

        $processFactory = fn (string $commandLine): Process => $processMock;

        $command = new TenantRunCommand($this->tenantProvider, '/app', $processFactory);
        $tester = new CommandTester($command);
        $exitCode = $tester->execute(['tenant' => 'acme', 'command_string' => 'app:some-command']);

        $this->assertSame(42, $exitCode);
    }

    public function testOutputForwarded(): void
    {
        $tenant = $this->createMock(TenantInterface::class);
        $this->tenantProvider
            ->method('findBySlug')
            ->willReturn($tenant);

        /** @var Process&MockObject $processMock */
        $processMock = $this->createMock(Process::class);
        $processMock
            ->method('run')
            ->willReturnCallback(function (callable $callback): int {
                $callback(Process::OUT, 'hello world');

                return 0;
            });
        $processMock->method('getExitCode')->willReturn(0);

        $processFactory = fn (string $commandLine): Process => $processMock;

        $command = new TenantRunCommand($this->tenantProvider, '/app', $processFactory);
        $tester = new CommandTester($command);
        $tester->execute(['tenant' => 'acme', 'command_string' => 'app:some-command']);

        $this->assertStringContainsString('hello world', $tester->getDisplay());
    }

    public function testNonexistentTenantThrows(): void
    {
        $this->tenantProvider
            ->method('findBySlug')
            ->willThrowException(new TenantNotFoundException('Tenant "unknown" not found.'));

        $processFactory = fn (string $commandLine): Process => $this->createMock(Process::class);

        $command = new TenantRunCommand($this->tenantProvider, '/app', $processFactory);
        $tester = new CommandTester($command);

        $this->expectException(TenantNotFoundException::class);
        $tester->execute(['tenant' => 'unknown', 'command_string' => 'app:some-command']);
    }
}
