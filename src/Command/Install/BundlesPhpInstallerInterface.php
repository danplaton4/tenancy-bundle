<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Command\Install;

/**
 * Contract for the bundles.php installer collaborator.
 *
 * Extracted as an interface so that {@see TenancyInstallCommand} can be unit-tested
 * with a stub (the concrete {@see BundlesPhpInstaller} is `final` and cannot be mocked
 * or extended). Mirrors the {@see \Tenancy\Bundle\DBAL\TenantConnectionInterface} pattern
 * established in Phase 03 for the same reason.
 */
interface BundlesPhpInstallerInterface
{
    public function install(string $bundlesPhpPath, bool $dryRun = false): InstallResult;
}
