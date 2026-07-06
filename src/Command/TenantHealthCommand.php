<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Tenancy\Bundle\Exception\TenantInactiveException;
use Tenancy\Bundle\Exception\TenantNotFoundException;
use Tenancy\Bundle\Health\HealthResponseSanitizer;
use Tenancy\Bundle\Health\HealthStatus;
use Tenancy\Bundle\Health\TenantHealthCheckerInterface;
use Tenancy\Bundle\Provider\TenantProviderInterface;
use Tenancy\Bundle\TenantInterface;

/**
 * CLI command for probing tenant health connections and bootstrappers.
 *
 * Mirrors TenantMigrateCommand in structure (per-tenant streaming output, exit-code aggregation,
 * --format=json aggregate mode) with one key difference: the command delegates the entire
 * set→probe→clear lifecycle to TenantHealthChecker::checkOne(). It takes NO TenantContext
 * dependency; the checker's finally block guarantees the clear (T-33-CLI-CTX mitigation).
 *
 * --all is intentionally UNBOUNDED (D-09 / T-33-CLI-BOUND accepted) — an explicit operator
 * action, not an auto-fired probe. Bounding is the HTTP endpoint's concern.
 *
 * All output strings are run through HealthResponseSanitizer before printing (T-33-04, HEALTH-04).
 *
 * DI wiring (console.command tag) is handled in plan 33-05.
 */
#[AsCommand(
    name: 'tenancy:health',
    description: 'Check health of tenant connections and bootstrappers',
)]
final class TenantHealthCommand extends Command
{
    public function __construct(
        private readonly ?TenantProviderInterface $tenantProvider,
        private readonly TenantHealthCheckerInterface $checker,
        private readonly HealthResponseSanitizer $sanitizer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'tenant',
                null,
                InputOption::VALUE_OPTIONAL,
                'Check health for a single tenant by slug',
            )
            ->addOption(
                'all',
                null,
                InputOption::VALUE_NONE,
                'Check health for all tenants sequentially (unbounded — operator action)',
            )
            ->addOption(
                'format',
                null,
                InputOption::VALUE_REQUIRED,
                'Output format: txt (default) or json',
                'txt',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Guard: no-Doctrine / no-provider lane (mirrors TenantMaintenanceStatusCommand).
        if (null === $this->tenantProvider) {
            $io->error('Tenant provider is not available. Ensure doctrine/orm is installed and configured.');

            return Command::FAILURE;
        }

        // Validate --format (mirrors TenantMaintenanceStatusCommand lines 65-69).
        $formatRaw = $input->getOption('format');
        $format = \is_string($formatRaw) ? $formatRaw : 'txt';

        if (!\in_array($format, ['txt', 'json'], true)) {
            $io->error(sprintf('Unknown format "%s". Use "txt" or "json".', $format));

            return Command::FAILURE;
        }

        // Resolve tenant scope: --tenant=<slug> | --all | neither (error).
        $tenantSlug = $input->getOption('tenant');
        $all = (bool) $input->getOption('all');

        if (null !== $tenantSlug && \is_string($tenantSlug)) {
            // Single-tenant mode: findBySlug throws on unknown/inactive.
            try {
                $tenants = [$this->tenantProvider->findBySlug($tenantSlug)];
            } catch (TenantNotFoundException|TenantInactiveException $e) {
                $io->error($e->getMessage());

                return Command::FAILURE;
            }
        } elseif ($all) {
            // Fleet mode: unbounded by design (D-09 / T-33-CLI-BOUND accepted).
            $tenants = $this->tenantProvider->findAll();
        } else {
            // No scope given — print usage guidance.
            $io->error('Specify a scope: --tenant=<slug> for a single tenant, or --all for the full fleet.');

            return Command::FAILURE;
        }

        if ([] === $tenants) {
            $io->writeln('No tenants found.');

            return Command::SUCCESS;
        }

        return 'json' === $format
            ? $this->executeJson($tenants, $output)
            : $this->executeTxt($tenants, $io);
    }

    /**
     * Txt mode: stream one line per tenant, then a summary. Exit non-zero if any Fail.
     *
     * @param TenantInterface[] $tenants
     */
    private function executeTxt(array $tenants, SymfonyStyle $io): int
    {
        $passTally = 0;
        $warnTally = 0;
        $failTally = 0;

        foreach ($tenants as $tenant) {
            // checkOne() owns set→probe→clear-in-finally — command MUST NOT call clear().
            $report = $this->checker->checkOne($tenant);

            if (HealthStatus::Fail === $report->status) {
                ++$failTally;
                $io->writeln(sprintf(' <error>✗</error> %s [%s]', $report->slug, $report->status->value));

                // Print sanitized per-component output on failure.
                foreach ($report->results as $result) {
                    if (null !== $result->output) {
                        $io->writeln(sprintf(
                            '   %s: %s',
                            $result->componentClass,
                            $this->sanitizer->sanitize($result->output),
                        ));
                    }
                }
            } elseif (HealthStatus::Warn === $report->status) {
                ++$warnTally;
                $io->writeln(sprintf(' <comment>✓</comment> %s [%s]', $report->slug, $report->status->value));

                foreach ($report->results as $result) {
                    if (null !== $result->output) {
                        $io->writeln(sprintf(
                            '   %s: %s',
                            $result->componentClass,
                            $this->sanitizer->sanitize($result->output),
                        ));
                    }
                }
            } else {
                ++$passTally;
                $io->writeln(sprintf(' <info>✓</info> %s [%s]', $report->slug, $report->status->value));
            }
        }

        $total = $passTally + $warnTally + $failTally;
        $io->writeln(sprintf(
            'Completed: %d passed, %d warned, %d failed (total: %d)',
            $passTally,
            $warnTally,
            $failTally,
            $total,
        ));

        return $failTally > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * JSON mode: build a single aggregate object; write to raw $output (NOT SymfonyStyle).
     * stdout carries ONLY the JSON document (mirrors TenantMigrateCommand JSON path).
     *
     * Shape: {tenants:[{slug,status,output?}], summary:{pass,warn,fail,total}}
     * RESEARCH.md Open-Question 3: mirror the migrate aggregate, NOT the IETF HTTP shape.
     *
     * @param TenantInterface[] $tenants
     */
    private function executeJson(array $tenants, OutputInterface $output): int
    {
        $passTally = 0;
        $warnTally = 0;
        $failTally = 0;
        $rows = [];

        foreach ($tenants as $tenant) {
            // checkOne() owns set→probe→clear-in-finally — command MUST NOT call clear().
            $report = $this->checker->checkOne($tenant);

            $entry = [
                'slug' => $report->slug,
                'status' => $report->status->value,
            ];

            // Include sanitized output only for non-pass statuses (mirrors migrate "error?" semantics).
            if (HealthStatus::Pass !== $report->status) {
                // Collect and sanitize all non-null component outputs into a single string.
                $outputs = [];
                foreach ($report->results as $result) {
                    if (null !== $result->output) {
                        $outputs[] = $this->sanitizer->sanitize($result->output);
                    }
                }
                if ([] !== $outputs) {
                    $entry['output'] = implode('; ', $outputs);
                }
            }

            $rows[] = $entry;

            match ($report->status) {
                HealthStatus::Pass => ++$passTally,
                HealthStatus::Warn => ++$warnTally,
                HealthStatus::Fail => ++$failTally,
            };
        }

        $aggregate = [
            'tenants' => $rows,
            'summary' => [
                'pass' => $passTally,
                'warn' => $warnTally,
                'fail' => $failTally,
                'total' => $passTally + $warnTally + $failTally,
            ],
        ];

        $json = json_encode(
            $aggregate,
            \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_THROW_ON_ERROR,
        );

        // Write to raw $output (NOT SymfonyStyle) — stdout carries only the JSON document.
        $output->writeln($json);

        return $failTally > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
