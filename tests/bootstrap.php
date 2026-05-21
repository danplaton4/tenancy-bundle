<?php

declare(strict_types=1);

$loader = require dirname(__DIR__).'/vendor/autoload.php';

// Register the worktree's own src/ and tests/ directories for PSR-4 autoloading.
// This is needed when running tests from within a git worktree whose src/ and tests/
// directories differ from the main repo's directories (which the shared vendor maps to).
$loader->addPsr4('Tenancy\\Bundle\\', dirname(__DIR__).'/src', prepend: true);
$loader->addPsr4('Tenancy\\Bundle\\Tests\\', __DIR__, prepend: true);

// Worktree isolation: purge Symfony compiled container caches under sys_get_temp_dir()
// that reference a DIFFERENT project root than this worktree.
//
// Background: git worktrees share a vendor/ directory but have independent src/ and
// tests/ trees. Symfony compiled containers hard-code absolute entity mapping paths via
// Doctrine\ORM\Mapping\Driver\AttributeDriver(['/path/to/src/Entity']). A container
// compiled from the main repo checkout (e.g. by a previous `vendor/bin/phpunit` run at
// the repo root) lands in the same tmp dir that this worktree uses (same kernel class
// → same md5 hash → same cache path). When the worktree's tests boot the same kernel,
// they find the stale container which Doctrine instantiates pointing at the main-repo
// entity path. PHP then tries to load the entity file from that path — but the class was
// already loaded from the worktree path by the autoloader → "Cannot redeclare class".
//
// Fix: before running any tests, scan known Symfony test-kernel cache dirs. For each
// cache dir, check the innermost compiled container .php file for an AttributeDriver
// instantiation. If it references an absolute path that is NOT under our worktree root,
// the cache is stale — delete the whole cache dir so the kernel rebuilds it correctly.
(static function (string $worktreeRoot): void {
    $tmpDir = sys_get_temp_dir();
    // All tenancy test kernel cache dirs follow the naming pattern:
    //   tenancy_*test*/cache/
    // Collect both the /cache dirs and /cache/Container*/ sub-dirs.
    $patterns = [
        $tmpDir.'/tenancy_*/cache',
        $tmpDir.'/tenancy_bundle_*',
    ];
    $cacheDirs = [];
    foreach ($patterns as $pattern) {
        foreach (glob($pattern, \GLOB_ONLYDIR) ?: [] as $dir) {
            $cacheDirs[] = $dir;
        }
    }

    foreach ($cacheDirs as $dir) {
        // Probe: check any compiled container in this dir or its immediate sub-dirs
        // for any absolute path reference that does NOT start with our worktree root.
        // This catches both entity mapping paths and kernel.project_dir mismatches.
        $phpFiles = array_merge(
            glob($dir.'/*.php') ?: [],
            glob($dir.'/*/*.php') ?: []
        );
        $stale = false;
        foreach ($phpFiles as $phpFile) {
            $contents = (string) file_get_contents($phpFile);
            // Look for any absolute path that is anchored to a project root:
            // lines like: 'kernel.project_dir' => '/some/absolute/path'
            // or: new AttributeDriver(['/some/path/Entity'])
            // If the path reference does NOT contain our worktree root, it's stale.
            if (preg_match("#['\"](/[^'\"]+)['\"]#", $contents, $match)) {
                $referencedPath = $match[1];
                // Only flag if it looks like it refers to a PROJECT path
                // (contains 'symfony-multitenancy' or is a known project indicator).
                if (
                    str_contains($referencedPath, 'symfony-multitenancy')
                    && !str_starts_with($referencedPath, $worktreeRoot)
                ) {
                    $stale = true;
                    break;
                }
            }
        }
        if ($stale) {
            // Remove the top-level tmp dir (parent of /cache).
            $toRemove = str_ends_with($dir, '/cache') ? dirname($dir) : $dir;
            if (is_dir($toRemove)) {
                $ri = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($toRemove, FilesystemIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::CHILD_FIRST
                );
                foreach ($ri as $file) {
                    $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
                }
                @rmdir($toRemove);
            }
        }
    }
})(realpath(dirname(__DIR__)) ?: dirname(__DIR__));
