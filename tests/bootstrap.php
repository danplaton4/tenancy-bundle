<?php

declare(strict_types=1);

$loader = require dirname(__DIR__).'/vendor/autoload.php';

// Register the worktree's own src/ and tests/ directories for PSR-4 autoloading.
// This is needed when running tests from within a git worktree whose src/ and tests/
// directories differ from the main repo's directories (which the shared vendor maps to).
$loader->addPsr4('Tenancy\\Bundle\\', dirname(__DIR__).'/src');
$loader->addPsr4('Tenancy\\Bundle\\Tests\\', __DIR__);
