<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;
use Tenancy\Bundle\Provider\TenantProviderInterface;

#[AsCommand(name: 'tenancy:run', description: 'Run a Symfony console command scoped to a specific tenant')]
final class TenantRunCommand extends Command
{
    public function __construct(
        private readonly TenantProviderInterface $tenantProvider,
        private readonly string $projectDir,
        private readonly ?\Closure $processFactory = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('tenant', InputArgument::REQUIRED, 'Tenant slug')
            ->addArgument('command_string', InputArgument::REQUIRED, 'The console command to run (e.g. "app:some-command arg1 arg2")');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var string $tenantSlug */
        $tenantSlug = $input->getArgument('tenant');

        /** @var string $commandString */
        $commandString = $input->getArgument('command_string');

        // Validate tenant exists — let TenantNotFoundException / TenantInactiveException bubble
        $this->tenantProvider->findBySlug($tenantSlug);

        $consolePath = $this->projectDir.'/bin/console';

        $commandLine = sprintf(
            '%s %s %s --tenant=%s',
            escapeshellarg(\PHP_BINARY),
            escapeshellarg($consolePath),
            $commandString,
            escapeshellarg($tenantSlug),
        );

        $process = (null !== $this->processFactory)
            ? ($this->processFactory)($commandLine)
            : Process::fromShellCommandline($commandLine);

        $process->setTimeout(null);

        $process->run(function (string $type, string $buffer) use ($output): void {
            $output->write($buffer);
        });

        return $process->getExitCode() ?? 0;
    }
}
