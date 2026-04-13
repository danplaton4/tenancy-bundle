<?php

declare(strict_types=1);

$loader = require dirname(__DIR__).'/vendor/autoload.php';

// In git worktrees the vendor/autoload.php maps src/ to the main repo's src/ directory.
// We prepend the worktree's src/ and tests/ so that classes added in this worktree
// (not yet merged to the main branch) are found first by the autoloader.
$worktreeRoot = dirname(__DIR__);
$loader->addPsr4('Tenancy\\Bundle\\', [$worktreeRoot.'/src'], true);
$loader->addPsr4('Tenancy\\Bundle\\Tests\\', [$worktreeRoot.'/tests'], true);
