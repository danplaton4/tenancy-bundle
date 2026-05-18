<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Command\Install;

/**
 * Outcome status for a single {@see BundlesPhpInstaller::install()} call.
 *
 * Backed by string so the value is stable across serialization (e.g., debug logs).
 */
enum InstallStatus: string
{
    /** bundles.php was mutated; entry inserted; .bak written; php -l passed. */
    case WROTE = 'wrote';

    /** Tenancy\Bundle\TenancyBundle was already registered; no write, no .bak, no error. */
    case ALREADY_REGISTERED = 'already_registered';

    /** bundles.php has a non-standard shape; refused to mutate; printed manual snippet (D-03). */
    case REFUSED_NON_STANDARD = 'refused_non_standard';

    /** php -l failed post-write; .bak copied back; .bak preserved on disk per D-13. */
    case LINT_FAILED_RESTORED = 'lint_failed_restored';

    /** nikic/php-parser is not autoloadable (require-dev missing). Command should exit FAILURE with install instructions. */
    case DEV_DEPENDENCY_MISSING = 'dev_dependency_missing';
}
