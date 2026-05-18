<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Command\Install;

/**
 * Typed result returned by {@see BundlesPhpInstaller::install()}.
 *
 * Each outcome carries different ancillary data; a pure enum cannot hold per-case
 * payload cleanly, so this class pairs the {@see InstallStatus} enum with optional
 * fields (backupPath, diff, errorMessage). Use the static named constructors —
 * the public constructor is the implementation detail.
 *
 * Bundle convention: `final readonly class` with promoted-readonly constructor
 * (mirrors {@see \Tenancy\Bundle\Cache\TenantAwareCacheAdapter} and similar).
 */
final readonly class InstallResult
{
    public function __construct(
        public InstallStatus $status,
        public ?string $backupPath = null,
        public ?string $diff = null,
        public ?string $errorMessage = null,
    ) {
    }

    public static function wrote(string $backupPath): self
    {
        return new self(InstallStatus::WROTE, backupPath: $backupPath);
    }

    public static function alreadyRegistered(): self
    {
        return new self(InstallStatus::ALREADY_REGISTERED);
    }

    public static function refusedNonStandard(string $reason): self
    {
        return new self(InstallStatus::REFUSED_NON_STANDARD, errorMessage: $reason);
    }

    public static function lintFailedRestored(string $backupPath, string $lintError): self
    {
        return new self(InstallStatus::LINT_FAILED_RESTORED, backupPath: $backupPath, errorMessage: $lintError);
    }

    public static function devDependencyMissing(): self
    {
        return new self(InstallStatus::DEV_DEPENDENCY_MISSING);
    }

    /**
     * Convenience: was this a write outcome (WROTE or ALREADY_REGISTERED)?
     * Used by the command to decide whether to delegate to tenancy:init.
     */
    public function isSuccessOutcome(): bool
    {
        return InstallStatus::WROTE === $this->status
            || InstallStatus::ALREADY_REGISTERED === $this->status
            || InstallStatus::REFUSED_NON_STANDARD === $this->status;
    }
}
