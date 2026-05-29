<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;
use Tenancy\Bundle\Exception\MissingTenantProviderException;
use Tenancy\Bundle\Provider\TenantProviderInterface;

#[AsCommand(name: 'tenancy:run', description: 'Run a Symfony console command scoped to a specific tenant')]
final class TenantRunCommand extends Command
{
    /**
     * @param \Closure(list<string>): Process|null $processFactory
     *                                                             Optional test seam; receives the fully-tokenized command argv list
     *                                                             (NO shell semantics) and returns a Process instance. Real callers
     *                                                             leave this null and the command spawns a Process with array argv.
     */
    public function __construct(
        private readonly ?TenantProviderInterface $tenantProvider,
        private readonly string $projectDir,
        private readonly ?\Closure $processFactory = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('tenant', InputArgument::REQUIRED, 'Tenant slug')
            ->addArgument('command_string', InputArgument::REQUIRED, 'Whitespace-separated console command tokens (e.g. "app:some-command arg1 arg2"). NO shell interpretation: quotes, pipes, redirects, and command substitutions are passed through as literal characters in individual tokens.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (null === $this->tenantProvider) {
            throw new MissingTenantProviderException('The `tenancy:run` command');
        }

        /** @var string $tenantSlug */
        $tenantSlug = $input->getArgument('tenant');

        /** @var string $commandString */
        $commandString = $input->getArgument('command_string');

        // Validate tenant exists — let TenantNotFoundException / TenantInactiveException bubble
        $this->tenantProvider->findBySlug($tenantSlug);

        /**
         * @security Trust boundary (WR-04): the caller of `tenancy:run` is a developer
         * at the CLI. `$commandString` is read from `$input->getArgument('command_string')`
         * and is NOT escaped. The v0.3.0 fix switched from `Process::fromShellCommandline()`
         * (shell-quoted) to `new Process(array $argv)` (execve-direct), making shell
         * metacharacters inert in any single token. Do NOT expose this command via HTTP,
         * queued jobs, or any context where untrusted input can reach `$commandString` —
         * the array-argv defense covers shell metas, but a malicious token could still
         * invoke unintended bin/console commands.
         */
        // Tokenize on whitespace; NO shell interpretation. The argv array is
        // passed to Process directly, so each element becomes its own
        // execve() argument: shell metacharacters in any token are inert.
        // (Closes 18-REVIEW.md WR-04: the previous Process::fromShellCommandline
        // path interpolated $commandString into a shell command line and was a
        // shell-injection vector for any caller passing untrusted input.)
        $tokens = preg_split('/\s+/', trim($commandString), -1, \PREG_SPLIT_NO_EMPTY) ?: [];

        $command = array_merge(
            [\PHP_BINARY, $this->projectDir.'/bin/console'],
            $tokens,
            ['--tenant='.$tenantSlug],
        );

        $process = (null !== $this->processFactory)
            ? ($this->processFactory)($command)
            : new Process($command);

        $process->setTimeout(null);

        $process->run(function (string $type, string $buffer) use ($output): void {
            $output->write($buffer);
        });

        return $process->getExitCode() ?? 0;
    }
}
