<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Command\Migration;

use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;
use Tenancy\Bundle\TenantInterface;

/**
 * Bounded subprocess worker pool that runs one `bin/console tenancy:migrate --tenant=<slug>`
 * child per tenant concurrently, up to a configurable limit.
 *
 * Design decisions (from 31-CONTEXT.md):
 * - D-01: per-tenant output is flushed as one atomic block (header + log) on completion — no interleaving.
 * - D-07: continue-on-failure; a null exit code (killed/crashed) is FAILURE, never success.
 * - D-04: JSON mode is controlled by the $emitBlocks param — caller passes false to suppress live blocks.
 * - Discretion: no per-subprocess timeout (`setTimeout(null)` — never kill a migration mid-flight).
 * - Pitfall 17: output captured via start() streaming callback into per-child PHP buffer; never getOutput() post-exit.
 * - Pitfall 18: SIGTERM/SIGINT forwarding to all live children under pcntl (no-op on no-pcntl runtimes).
 */
final class ParallelMigrationRunner
{
    /**
     * @param string                               $projectDir     kernel project directory; used to build the child argv
     *                                                             (`$projectDir/bin/console`)
     * @param \Closure(list<string>): Process|null $processFactory
     *                                                             Optional test seam; receives the fully-tokenized command
     *                                                             argv list (NO shell semantics) and returns a Process
     *                                                             instance. Real callers leave this null and the runner
     *                                                             spawns a Process with array argv.
     */
    public function __construct(
        private readonly string $projectDir,
        private readonly ?\Closure $processFactory = null,
    ) {
    }

    /**
     * Run `tenancy:migrate --tenant=<slug>` for every tenant in a bounded subprocess pool.
     *
     * @param list<TenantInterface> $tenants     tenants to migrate
     * @param int                   $concurrency maximum simultaneous subprocesses (pre-clamped to [1,32] by caller)
     * @param bool                  $dryRun      when true, `--dry-run` is appended to each child's argv
     * @param OutputInterface       $output      console output; used for atomic per-tenant blocks
     * @param bool                  $emitBlocks  when false (JSON mode), human-readable blocks are not written
     *                                           to $output; the aggregate result still carries all data
     */
    public function run(
        array $tenants,
        int $concurrency,
        bool $dryRun,
        OutputInterface $output,
        bool $emitBlocks = true,
    ): ParallelMigrationResult {
        $wallStart = microtime(true);

        /** @var array<string, array{process: Process, start: float}> $running */
        $running = [];

        /**
         * Per-slug output buffers, stored as references so the start() streaming callback
         * can write into the same buffer that the reap phase reads. Storing inside the
         * $running array as a plain string value would capture the empty string at push-time
         * (PHP copies strings on assignment) — this separate map avoids that pitfall.
         *
         * @var array<string, string> $buffers
         */
        $buffers = [];

        /** @var list<TenantInterface> $queue */
        $queue = array_values($tenants);

        /** @var list<array{slug: string, status: 'succeeded'|'failed', migrationsApplied: int, durationMs: int, error: string|null}> $results */
        $results = [];

        // SIGTERM/SIGINT forwarding — guard with extension_loaded('pcntl') so this
        // is inert on Windows / containers without the pcntl extension (Pitfall 18).
        if (extension_loaded('pcntl')) {
            pcntl_async_signals(true);

            /** @var \Closure(int): never $signalHandler */
            $signalHandler = function (int $signo) use (&$running): never {
                foreach ($running as $entry) {
                    $entry['process']->stop(0);
                }
                exit(1);
            };

            pcntl_signal(\SIGTERM, $signalHandler);
            pcntl_signal(\SIGINT, $signalHandler);
        }

        // Sliding-window pool (STACK.md lines 204-229, 50ms poll cadence).
        while ([] !== $queue || [] !== $running) {
            // Fill the pool up to $concurrency.
            while (count($running) < $concurrency && [] !== $queue) {
                $tenant = array_shift($queue);
                $slug = $tenant->getSlug();

                $argv = array_merge(
                    [\PHP_BINARY, $this->projectDir.'/bin/console', 'tenancy:migrate', '--tenant='.$slug],
                    $dryRun ? ['--dry-run'] : [],
                );

                $process = (null !== $this->processFactory)
                    ? ($this->processFactory)($argv)
                    : new Process($argv);

                $process->setTimeout(null);

                // Initialise the buffer entry so the reference in the closure is valid.
                $buffers[$slug] = '';

                // Accumulate streamed output in PHP memory via the start() callback (Pitfall 17).
                // The closure captures &$buffers[$slug] — a reference into the $buffers array —
                // so every chunk written by the child is appended to the same string that the
                // reap phase reads via $buffers[$slug].  Using a separate $buffers map (rather
                // than storing the buffer as a value inside $running) avoids the PHP string-copy
                // pitfall where `$running[$slug]['buffer'] = $buffer` freezes the empty string
                // at push-time and never sees subsequent callback writes.
                $process->start(function (string $type, string $chunk) use ($slug, &$buffers): void {
                    $buffers[$slug] .= $chunk;
                });

                $running[$slug] = [
                    'process' => $process,
                    'start' => microtime(true),
                ];
            }

            // Reap any finished children.
            foreach ($running as $slug => $entry) {
                $process = $entry['process'];

                if ($process->isRunning()) {
                    continue;
                }

                $buffer = $buffers[$slug] ?? '';
                $durationMs = (int) round((microtime(true) - $entry['start']) * 1000);

                // Exit-code rule (CRITICAL — Pitfall 15 / D-07): null exit = FAILURE, never success.
                // DO NOT write `?? 0` — that is the TenantRunCommand anti-pattern.
                $exitCode = $process->getExitCode();
                $failed = (null === $exitCode || 0 !== $exitCode);

                $migrationsApplied = self::parseMigrationsApplied($buffer);
                $errorMessage = $failed ? self::extractError($buffer, $exitCode) : null;
                $status = $failed ? 'failed' : 'succeeded';

                $results[] = [
                    'slug' => $slug,
                    'status' => $status,
                    'migrationsApplied' => $migrationsApplied,
                    'durationMs' => $durationMs,
                    'error' => $errorMessage,
                ];

                // D-01: flush the atomic block — header line + full captured buffer.
                // Because the entire block is written at once (only after child completion),
                // no two tenants' output can interleave (Pitfall 14).
                if ($emitBlocks) {
                    if ($failed) {
                        $output->writeln(sprintf(' <error>✗</error> %s', $slug));
                    } else {
                        $output->writeln(sprintf(' <info>✓</info> %s', $slug));
                    }
                    if ('' !== $buffer) {
                        $output->write($buffer);
                    }
                }

                unset($running[$slug], $buffers[$slug]);
            }

            // Poll at 50ms to keep CPU usage low (STACK.md line 228).
            if ([] !== $running) {
                usleep(50_000);
            }
        }

        $wallClockMs = (int) round((microtime(true) - $wallStart) * 1000);

        return new ParallelMigrationResult($results, $wallClockMs);
    }

    /**
     * Best-effort parse of migrations-applied count from a child's captured output.
     *
     * The authoritative pass/fail signal is the exit code; this count is informational
     * (for D-02's summary table). Returns 0 when no migration lines are detected.
     */
    private static function parseMigrationsApplied(string $buffer): int
    {
        // Doctrine Migrations 3.x prints lines like:
        //   ++ migrating 20240101000001
        //   ++ reverting 20240101000001
        // Count "++ migrating" occurrences as a best-effort applied count.
        return substr_count($buffer, '++ migrating');
    }

    /**
     * Extract a short error message from the child's output or generate one from the exit code.
     */
    private static function extractError(string $buffer, ?int $exitCode): string
    {
        if ('' !== $buffer) {
            // Take the last non-empty line as the error context.
            $lines = array_filter(
                explode("\n", trim($buffer)),
                static fn (string $line): bool => '' !== trim($line),
            );
            if ([] !== $lines) {
                $line = trim(strip_tags((string) end($lines)));
                // Scrub to valid UTF-8 (PHP 8.2-compatible; mb_scrub() requires PHP 8.3+).
                if (extension_loaded('mbstring')) {
                    $line = mb_convert_encoding($line, 'UTF-8', 'UTF-8');
                }

                return $line;
            }
        }

        return null === $exitCode
            ? 'Process killed or crashed (null exit code)'
            : sprintf('Process exited with code %d', $exitCode);
    }
}

/**
 * Aggregate result returned by ParallelMigrationRunner::run().
 *
 * Per-tenant entries carry the D-03 JSON key shape exactly:
 *   slug · status · migrationsApplied · durationMs · error
 *
 * The summary methods expose succeeded/failed/total/wallClockMs for D-02's table.
 *
 * @phpstan-type TenantRow array{slug: string, status: 'succeeded'|'failed', migrationsApplied: int, durationMs: int, error: string|null}
 */
final class ParallelMigrationResult
{
    /**
     * @param list<TenantRow> $tenantRows
     */
    public function __construct(
        private readonly array $tenantRows,
        private readonly int $wallClockMs,
    ) {
    }

    /**
     * @return list<TenantRow>
     */
    public function tenants(): array
    {
        return $this->tenantRows;
    }

    public function succeeded(): int
    {
        return count(array_filter(
            $this->tenantRows,
            static fn (array $row): bool => 'succeeded' === $row['status'],
        ));
    }

    public function failed(): int
    {
        return count(array_filter(
            $this->tenantRows,
            static fn (array $row): bool => 'failed' === $row['status'],
        ));
    }

    public function total(): int
    {
        return count($this->tenantRows);
    }

    public function wallClockMs(): int
    {
        return $this->wallClockMs;
    }
}
