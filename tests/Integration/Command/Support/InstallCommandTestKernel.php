<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Integration\Command\Support;

/**
 * Test kernel rooted at a CALLER-PROVIDED $projectDir (typically a tmp dir
 * with a fresh bundles.php fixture).
 *
 * This is the subclass-the-kernel option from RESEARCH.md §1 Finding 7 —
 * preferred over per-test compiler-pass injection because it keeps the DI
 * wiring (kernel.project_dir → TenancyInstallCommand::$projectDir) under test.
 */
final class InstallCommandTestKernel extends CommandTestKernel
{
    private string $instanceSuffix;

    public function __construct(
        private readonly string $rootedProjectDir,
        string $environment = 'install_command_test',
        bool $debug = false,
    ) {
        $this->instanceSuffix = uniqid('install_', true);
        parent::__construct($environment, $debug);
    }

    public function getProjectDir(): string
    {
        return $this->rootedProjectDir;
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir().'/tenancy_install_kernel_'.$this->instanceSuffix.'/cache';
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir().'/tenancy_install_kernel_'.$this->instanceSuffix.'/logs';
    }
}
