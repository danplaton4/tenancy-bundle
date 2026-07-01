<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Unit\Command;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Tenancy\Bundle\Command\TenantMaintenanceStatusCommand;
use Tenancy\Bundle\Provider\TenantProviderInterface;
use Tenancy\Bundle\TenantInterface;

/**
 * Unit tests for TenantMaintenanceStatusCommand.
 *
 * Covers: MAINT-09 (status lists only in-maintenance tenants, table + --format=json).
 *
 * No kernel boot — all dependencies are mocks. CommandTester drives execute().
 * findAll() is used (bypasses PSR cache) and filtered for isInMaintenance() === true.
 */
final class TenantMaintenanceStatusCommandTest extends TestCase
{
    private TenantProviderInterface&MockObject $tenantProvider;

    protected function setUp(): void
    {
        $this->tenantProvider = $this->createMock(TenantProviderInterface::class);
    }

    private function makeCommand(): TenantMaintenanceStatusCommand
    {
        return new TenantMaintenanceStatusCommand($this->tenantProvider);
    }

    private function makeTenant(string $slug, string $name, bool $inMaintenance): TenantInterface&MockObject
    {
        $tenant = $this->createMock(TenantInterface::class);
        $tenant->method('getSlug')->willReturn($slug);
        $tenant->method('getName')->willReturn($name);
        $tenant->method('isInMaintenance')->willReturn($inMaintenance);

        return $tenant;
    }

    // -----------------------------------------------------------------------
    // Table output: only in-maintenance tenants appear
    // -----------------------------------------------------------------------

    public function testStatusListsOnlyInMaintenanceTenants(): void
    {
        $tenantUp = $this->makeTenant('beta', 'Beta Corp', false);
        $tenantDown = $this->makeTenant('acme', 'Acme Inc', true);
        $anotherUp = $this->makeTenant('gamma', 'Gamma LLC', false);

        $this->tenantProvider
            ->method('findAll')
            ->willReturn([$tenantUp, $tenantDown, $anotherUp]);

        $command = $this->makeCommand();
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $exitCode);

        $output = $tester->getDisplay();

        // Only 'acme' (in maintenance) should appear
        $this->assertStringContainsString('acme', $output);

        // 'beta' and 'gamma' (not in maintenance) must NOT appear
        $this->assertStringNotContainsString('beta', $output);
        $this->assertStringNotContainsString('gamma', $output);
    }

    public function testStatusWithNoInMaintenanceTenants(): void
    {
        $tenantUp = $this->makeTenant('beta', 'Beta Corp', false);

        $this->tenantProvider
            ->method('findAll')
            ->willReturn([$tenantUp]);

        $command = $this->makeCommand();
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringNotContainsString('beta', $tester->getDisplay());
    }

    public function testStatusWithEmptyTenantList(): void
    {
        $this->tenantProvider
            ->method('findAll')
            ->willReturn([]);

        $command = $this->makeCommand();
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $exitCode);
    }

    // -----------------------------------------------------------------------
    // JSON output: --format=json aggregate object with correct total (MAINT-09 / D-10)
    // -----------------------------------------------------------------------

    public function testStatusJsonFormatContainsOnlyInMaintenanceTenants(): void
    {
        $tenantUp = $this->makeTenant('beta', 'Beta Corp', false);
        $tenantDown1 = $this->makeTenant('acme', 'Acme Inc', true);
        $tenantDown2 = $this->makeTenant('delta', 'Delta Ltd', true);

        $this->tenantProvider
            ->method('findAll')
            ->willReturn([$tenantUp, $tenantDown1, $tenantDown2]);

        $command = $this->makeCommand();
        $tester = new CommandTester($command);
        $exitCode = $tester->execute(['--format' => 'json']);

        $this->assertSame(Command::SUCCESS, $exitCode);

        $json = $tester->getDisplay();

        /** @var array{tenants: list<array{slug: string, inMaintenance: bool}>, total: int}|false $decoded */
        $decoded = json_decode(trim($json), true);

        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('tenants', $decoded);
        $this->assertArrayHasKey('total', $decoded);

        // total must equal number of in-maintenance tenants (D-10)
        $this->assertSame(2, $decoded['total']);

        // Only in-maintenance tenants appear in the list
        $slugs = array_column($decoded['tenants'], 'slug');
        $this->assertContains('acme', $slugs);
        $this->assertContains('delta', $slugs);
        $this->assertNotContains('beta', $slugs);

        // Each tenant in the list has inMaintenance: true
        foreach ($decoded['tenants'] as $row) {
            $this->assertTrue($row['inMaintenance'], 'All listed tenants must have inMaintenance: true');
        }
    }

    public function testStatusJsonFormatTotalIsZeroWhenNoneInMaintenance(): void
    {
        $this->tenantProvider
            ->method('findAll')
            ->willReturn([$this->makeTenant('beta', 'Beta Corp', false)]);

        $command = $this->makeCommand();
        $tester = new CommandTester($command);
        $exitCode = $tester->execute(['--format' => 'json']);

        $this->assertSame(Command::SUCCESS, $exitCode);

        /** @var array{tenants: list<mixed>, total: int}|false $decoded */
        $decoded = json_decode(trim($tester->getDisplay()), true);

        $this->assertIsArray($decoded);
        $this->assertSame(0, $decoded['total']);
        $this->assertSame([], $decoded['tenants']);
    }

    // -----------------------------------------------------------------------
    // findAll() is used (not findBySlug) — structural safety
    // -----------------------------------------------------------------------

    public function testStatusUsesFindAllNotFindBySlug(): void
    {
        // findAll() must be called; findBySlug() must never be called
        $this->tenantProvider
            ->expects($this->once())
            ->method('findAll')
            ->willReturn([]);

        $this->tenantProvider
            ->expects($this->never())
            ->method('findBySlug');

        $command = $this->makeCommand();
        $tester = new CommandTester($command);
        $tester->execute([]);
    }
}
