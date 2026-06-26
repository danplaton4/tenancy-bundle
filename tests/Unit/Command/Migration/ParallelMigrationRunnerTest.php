<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Unit\Command\Migration;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Process\Process;
use Tenancy\Bundle\Command\Migration\ParallelMigrationRunner;
use Tenancy\Bundle\TenantInterface;

/**
 * Unit tests for ParallelMigrationRunner.
 *
 * Covers the ISOL-07 quality gates:
 *  SC2 — at-most-N concurrent processes (testAtMostNConcurrent)
 *  SC3 — null exit = failure + output atomicity (testNullExitCodeCountsAsFailure, testAtomicOutputNoInterleaving)
 *  SC5 — JSON-ready result shape (testResultExposesJsonShapeKeys)
 * Plus: argv shape, dry-run forwarding, exit-code semantics.
 */
final class ParallelMigrationRunnerTest extends TestCase
{
    /**
     * Build a TenantInterface mock with getSlug() stubbed.
     * Pattern copied from TenantMigrateCommandTest::makeTenant().
     *
     * @return TenantInterface&MockObject
     */
    private function makeTenant(string $slug): TenantInterface
    {
        $tenant = $this->createMock(TenantInterface::class);
        $tenant->method('getSlug')->willReturn($slug);

        return $tenant;
    }

    /**
     * Build a Process mock configured for the non-blocking poll flow:
     * - start() accepts (and optionally fires) the streaming callback
     * - isRunning() returns true on first call, false thereafter (one poll cycle to reap)
     * - getExitCode() returns the provided exit code.
     *
     * @return Process&MockObject
     */
    private function makeProcessMock(
        ?int $exitCode,
        string $output = '',
        bool $isRunningFirstCall = true,
    ): Process {
        /** @var Process&MockObject $process */
        $process = $this->createMock(Process::class);

        // start() fires the callback immediately with the provided output, simulating streamed stdout.
        $process->method('start')->willReturnCallback(
            static function (callable $callback) use ($output): void {
                if ('' !== $output) {
                    $callback(Process::OUT, $output);
                }
            }
        );

        // isRunning(): true on first call (still running), false on second (done).
        $process->method('isRunning')->willReturnOnConsecutiveCalls($isRunningFirstCall, false);

        $process->method('getExitCode')->willReturn($exitCode);

        return $process;
    }

    // -------------------------------------------------------------------------
    // SC2 — at-most-N concurrency (Pitfall 13 / ISOL-07/08)
    // -------------------------------------------------------------------------

    public function testAtMostNConcurrent(): void
    {
        $concurrency = 2;
        $tenants = [
            $this->makeTenant('t1'),
            $this->makeTenant('t2'),
            $this->makeTenant('t3'),
            $this->makeTenant('t4'),
            $this->makeTenant('t5'),
        ];

        $live = 0;
        $observedMax = 0;

        /**
         * Counting factory: each Process mock increments $live on start() and
         * decrements it when isRunning() → false (simulating the reap transition).
         */
        $factory = function (array $argv) use (&$live, &$observedMax): Process {
            /** @var Process&MockObject $process */
            $process = $this->createMock(Process::class);

            $process->method('start')->willReturnCallback(
                static function () use (&$live, &$observedMax): void {
                    ++$live;
                    $observedMax = max($observedMax, $live);
                }
            );

            // First isRunning() call → true (still alive); second → false (done / decrements live).
            $calls = 0;
            $process->method('isRunning')->willReturnCallback(
                static function () use (&$calls, &$live): bool {
                    ++$calls;
                    if (1 === $calls) {
                        return true;
                    }
                    --$live;

                    return false;
                }
            );

            $process->method('getExitCode')->willReturn(0);

            return $process;
        };

        $runner = new ParallelMigrationRunner('/app', $factory);
        $result = $runner->run($tenants, $concurrency, false, new BufferedOutput());

        $this->assertSame(5, $result->total());
        $this->assertLessThanOrEqual(
            $concurrency,
            $observedMax,
            "Runner must never exceed the concurrency cap; observed {$observedMax} simultaneous processes."
        );
    }

    // -------------------------------------------------------------------------
    // argv shape (T-31-01 / ISOL-07)
    // -------------------------------------------------------------------------

    public function testFactoryCalledOncePerTenantWithCorrectArgv(): void
    {
        $tenants = [
            $this->makeTenant('acme'),
            $this->makeTenant('beta'),
        ];

        /** @var list<list<string>> $capturedArgvs */
        $capturedArgvs = [];

        $factory = function (array $argv) use (&$capturedArgvs): Process {
            $capturedArgvs[] = $argv;

            return $this->makeProcessMock(0);
        };

        $runner = new ParallelMigrationRunner('/app', $factory);
        $runner->run($tenants, 4, false, new BufferedOutput());

        $this->assertCount(2, $capturedArgvs, 'Factory must be called exactly once per tenant.');

        // Flatten all argv arrays to check common tokens.
        $allArgvs = array_merge(...$capturedArgvs);

        $this->assertContains('/app/bin/console', $allArgvs);
        $this->assertContains('tenancy:migrate', $allArgvs);

        // Each tenant slug must appear in exactly one argv.
        $slugsFound = [];
        foreach ($capturedArgvs as $argv) {
            foreach ($argv as $token) {
                if (str_starts_with($token, '--tenant=')) {
                    $slugsFound[] = substr($token, strlen('--tenant='));
                }
            }
        }
        sort($slugsFound);
        $this->assertSame(['acme', 'beta'], $slugsFound);

        // --format must NEVER be forwarded to a child (D-04).
        $this->assertNotContains('--format', $allArgvs);
        foreach ($capturedArgvs as $argv) {
            foreach ($argv as $token) {
                $this->assertStringStartsNotWith('--format', $token, '--format must never be forwarded to a child.');
            }
        }
    }

    // -------------------------------------------------------------------------
    // SC3 — null exit = failure (Pitfall 15 / D-07)
    // -------------------------------------------------------------------------

    public function testNullExitCodeCountsAsFailure(): void
    {
        $tenants = [$this->makeTenant('orphan')];

        $factory = function (array $argv): Process {
            return $this->makeProcessMock(null); // null = killed / crashed child
        };

        $runner = new ParallelMigrationRunner('/app', $factory);
        $result = $runner->run($tenants, 1, false, new BufferedOutput());

        $this->assertSame(1, $result->failed(), 'A null exit code must be recorded as failure.');
        $this->assertSame(0, $result->succeeded());

        $tenantRows = $result->tenants();
        $this->assertCount(1, $tenantRows);
        $this->assertSame('failed', $tenantRows[0]['status']);
    }

    public function testNonZeroExitCodeCountsAsFailure(): void
    {
        $tenants = [$this->makeTenant('broken')];

        $factory = function (array $argv): Process {
            return $this->makeProcessMock(1);
        };

        $runner = new ParallelMigrationRunner('/app', $factory);
        $result = $runner->run($tenants, 1, false, new BufferedOutput());

        $this->assertSame(1, $result->failed());
        $this->assertSame('failed', $result->tenants()[0]['status']);
    }

    public function testZeroExitCodeSucceeds(): void
    {
        $tenants = [$this->makeTenant('ok')];

        $factory = function (array $argv): Process {
            return $this->makeProcessMock(0);
        };

        $runner = new ParallelMigrationRunner('/app', $factory);
        $result = $runner->run($tenants, 1, false, new BufferedOutput());

        $this->assertSame(1, $result->succeeded());
        $this->assertSame(0, $result->failed());
        $this->assertSame('succeeded', $result->tenants()[0]['status']);
    }

    // -------------------------------------------------------------------------
    // ISOL-10 — --dry-run forwarding
    // -------------------------------------------------------------------------

    public function testDryRunForwardsFlag(): void
    {
        $tenants = [$this->makeTenant('acme'), $this->makeTenant('beta')];

        /** @var list<list<string>> $dryArgvs */
        $dryArgvs = [];
        /** @var list<list<string>> $liveArgvs */
        $liveArgvs = [];

        $dryFactory = function (array $argv) use (&$dryArgvs): Process {
            $dryArgvs[] = $argv;

            return $this->makeProcessMock(0);
        };

        $liveFactory = function (array $argv) use (&$liveArgvs): Process {
            $liveArgvs[] = $argv;

            return $this->makeProcessMock(0);
        };

        $runner1 = new ParallelMigrationRunner('/app', $dryFactory);
        $runner1->run($tenants, 4, true, new BufferedOutput());

        $runner2 = new ParallelMigrationRunner('/app', $liveFactory);
        $runner2->run($tenants, 4, false, new BufferedOutput());

        // Every dry-run argv must contain '--dry-run'.
        foreach ($dryArgvs as $argv) {
            $this->assertContains('--dry-run', $argv, '--dry-run must be forwarded to every child when dryRun=true.');
        }

        // No live-run argv must contain '--dry-run'.
        foreach ($liveArgvs as $argv) {
            $this->assertNotContains('--dry-run', $argv, '--dry-run must not appear in live-run argv.');
        }
    }

    // -------------------------------------------------------------------------
    // SC3 — atomic output, no interleaving (Pitfall 14 / ISOL-09)
    // -------------------------------------------------------------------------

    public function testAtomicOutputNoInterleaving(): void
    {
        $tenants = [
            $this->makeTenant('alpha'),
            $this->makeTenant('gamma'),
        ];

        $factory = function (array $argv): Process {
            // Determine which tenant this process belongs to from the argv.
            $slug = '';
            foreach ($argv as $token) {
                if (str_starts_with($token, '--tenant=')) {
                    $slug = substr($token, strlen('--tenant='));
                    break;
                }
            }

            $tenantOutput = "line1-{$slug}\nline2-{$slug}\nline3-{$slug}\n";

            return $this->makeProcessMock(0, $tenantOutput);
        };

        $bufferedOutput = new BufferedOutput();
        $runner = new ParallelMigrationRunner('/app', $factory);
        $runner->run($tenants, 4, false, $bufferedOutput);

        $content = $bufferedOutput->fetch();

        // For each tenant, find the header line and ensure all its body lines appear
        // contiguously before the next tenant's header line.
        // The header for alpha must appear before any alpha body lines, and all alpha
        // body lines must appear before the gamma header (or vice versa — completion order
        // may vary, but each block must be contiguous).
        foreach (['alpha', 'gamma'] as $slug) {
            $otherSlug = 'alpha' === $slug ? 'gamma' : 'alpha';

            $headerPos = strpos($content, $slug);
            $this->assertNotFalse($headerPos, "Header for {$slug} must be present in output.");

            // Each body line for this tenant must come after the header AND before the other tenant's
            // header line (if the other tenant was rendered after this one).
            $otherHeaderPos = strpos($content, $otherSlug);
            $this->assertNotFalse($otherHeaderPos, "Header for {$otherSlug} must be present in output.");

            $firstBlock = min($headerPos, $otherHeaderPos);
            $secondBlockStart = max($headerPos, $otherHeaderPos);
            $firstSlug = $headerPos < $otherHeaderPos ? $slug : $otherSlug;
            $secondSlug = $headerPos < $otherHeaderPos ? $otherSlug : $slug;

            // Ensure all lines for the first block appear before the second block starts.
            $firstBlockContent = substr($content, $firstBlock, $secondBlockStart - $firstBlock);
            $this->assertStringContainsString("line1-{$firstSlug}", $firstBlockContent, "All lines for {$firstSlug} must be in its own contiguous block.");
            $this->assertStringContainsString("line2-{$firstSlug}", $firstBlockContent, "All lines for {$firstSlug} must be in its own contiguous block.");
            $this->assertStringContainsString("line3-{$firstSlug}", $firstBlockContent, "All lines for {$firstSlug} must be in its own contiguous block.");

            // And the second slug's body lines must not leak into the first block.
            $this->assertStringNotContainsString("line1-{$secondSlug}", $firstBlockContent, "Lines from {$secondSlug} must not appear in {$firstSlug}'s block.");
        }
    }

    // -------------------------------------------------------------------------
    // SC5 — JSON-ready result shape (ISOL-12 / D-03)
    // -------------------------------------------------------------------------

    public function testResultExposesJsonShapeKeys(): void
    {
        $tenants = [
            $this->makeTenant('a1'),
            $this->makeTenant('a2'),
        ];

        $callIndex = 0;
        $factory = function (array $argv) use (&$callIndex): Process {
            ++$callIndex;

            return $this->makeProcessMock(0 === $callIndex % 2 ? 0 : 1);
        };

        $runner = new ParallelMigrationRunner('/app', $factory);
        $result = $runner->run($tenants, 4, false, new BufferedOutput());

        // --- per-tenant shape ---
        $rows = $result->tenants();
        $this->assertCount(2, $rows);

        foreach ($rows as $row) {
            $this->assertArrayHasKey('slug', $row, "Per-tenant row must carry 'slug'.");
            $this->assertArrayHasKey('status', $row, "Per-tenant row must carry 'status'.");
            $this->assertArrayHasKey('migrationsApplied', $row, "Per-tenant row must carry 'migrationsApplied'.");
            $this->assertArrayHasKey('durationMs', $row, "Per-tenant row must carry 'durationMs'.");
            $this->assertArrayHasKey('error', $row, "Per-tenant row must carry 'error'.");

            $this->assertIsString($row['slug']);
            $this->assertContains($row['status'], ['succeeded', 'failed']);
            $this->assertIsInt($row['migrationsApplied']);
            $this->assertIsInt($row['durationMs']);
            $this->assertTrue(null === $row['error'] || \is_string($row['error']));
        }

        // --- summary shape (values are meaningful, not just typed) ---
        $this->assertGreaterThanOrEqual(0, $result->succeeded(), "'succeeded()' must be non-negative.");
        $this->assertGreaterThanOrEqual(0, $result->failed(), "'failed()' must be non-negative.");
        $this->assertGreaterThanOrEqual(0, $result->wallClockMs(), "'wallClockMs()' must be non-negative.");

        $this->assertSame($result->total(), $result->succeeded() + $result->failed(), 'succeeded + failed must equal total.');
        $this->assertSame(2, $result->total());

        // --- JSON serializability (D-03 shape roundtrip) ---
        $document = [
            'tenants' => $result->tenants(),
            'summary' => [
                'succeeded' => $result->succeeded(),
                'failed' => $result->failed(),
                'total' => $result->total(),
                'wallClockMs' => $result->wallClockMs(),
            ],
        ];
        $json = json_encode($document);
        $this->assertIsString($json, 'Result must be JSON-serializable.');
        $decoded = json_decode($json, true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('tenants', $decoded);
        $this->assertArrayHasKey('summary', $decoded);
    }
}
