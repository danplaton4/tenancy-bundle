<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Command\Install;

use PhpParser\Node;
use PhpParser\Node\ArrayItem;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Stmt\Return_;
use PhpParser\ParserFactory;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Detects, classifies, and mutates a project's `config/bundles.php` to register
 * {@see \Tenancy\Bundle\TenancyBundle}.
 *
 * Detection rules per CONTEXT.md D-02 (exhaustive). Write path per D-04 (string-
 * template at AST byte offset), D-05 (atomic dumpFile), D-06 (timestamped .bak),
 * D-07 (php -l + restore on failure). Refusal of non-standard shapes is a clean
 * exit (security-by-default per D-14) — the command prints a manual snippet and
 * exits 0, NOT a tool failure.
 *
 * Threats mitigated:
 *   T-INSTALL-01 — string-template insertion produces malformed bundles.php
 *     -> php -l post-write + automatic restore from .bak.
 *   T-INSTALL-02 — .bak lost during restore path
 *     -> restore uses Filesystem::copy() (NOT rename); .bak survives every path.
 *   T-INSTALL-04 — non-standard bundles.php silently rewritten
 *     -> detect() refuses any shape that deviates from the Flex-canonical
 *        single-`Return_`-of-`Array_`-of-`ClassConstFetch::class`-keys form.
 */
final class BundlesPhpInstaller
{
    public const TENANCY_BUNDLE_FQCN = 'Tenancy\\Bundle\\TenancyBundle';

    /** @var \Closure(string, string): array{passed: bool, error: string} */
    private \Closure $lintRunner;

    /**
     * @param (\Closure(string, string): array{passed: bool, error: string})|null $lintRunner optional; tests can inject a forced-failure runner
     */
    public function __construct(
        private readonly Filesystem $filesystem = new Filesystem(),
        ?\Closure $lintRunner = null,
    ) {
        $this->lintRunner = $lintRunner ?? self::defaultLintRunner();
    }

    public function install(string $bundlesPhpPath, bool $dryRun = false): InstallResult
    {
        if (!class_exists(ParserFactory::class)) {
            return InstallResult::devDependencyMissing();
        }

        if (!$this->filesystem->exists($bundlesPhpPath)) {
            return InstallResult::refusedNonStandard('config/bundles.php not found at '.$bundlesPhpPath);
        }

        $detection = $this->detect($bundlesPhpPath);

        if ('non_standard' === $detection->status) {
            return InstallResult::refusedNonStandard($detection->reason ?? 'non-standard shape');
        }

        if ('missing' === $detection->status) {
            return InstallResult::refusedNonStandard($detection->reason ?? 'file missing');
        }

        if (in_array(self::TENANCY_BUNDLE_FQCN, $detection->registeredFqcns, true)) {
            return InstallResult::alreadyRegistered();
        }

        // ----- write path -----
        $source = (string) file_get_contents($bundlesPhpPath);
        \assert(null !== $detection->endPos, 'detect() returned standard without endPos');
        $newSource = $this->buildMutatedSource($source, $detection->endPos);
        $diff = $this->buildDiff($bundlesPhpPath);

        if ($dryRun) {
            return InstallResult::dryRun($diff);
        }

        $bakPath = $bundlesPhpPath.'.bak.'.gmdate('Ymd-His');
        $this->filesystem->copy($bundlesPhpPath, $bakPath);
        $this->filesystem->dumpFile($bundlesPhpPath, $newSource);

        $php = (new PhpExecutableFinder())->find();
        if (false === $php) {
            $this->filesystem->copy($bakPath, $bundlesPhpPath); // restore — copy, not rename

            return InstallResult::lintFailedRestored($bakPath, 'PHP binary not found by PhpExecutableFinder');
        }

        $lint = ($this->lintRunner)($php, $bundlesPhpPath);
        if (!$lint['passed']) {
            $this->filesystem->copy($bakPath, $bundlesPhpPath); // restore — copy, not rename

            return InstallResult::lintFailedRestored($bakPath, $lint['error']);
        }

        return InstallResult::wrote($bakPath);
    }

    /**
     * Parse the file and classify its shape per the rules in CONTEXT.md D-02.
     *
     * Returns DetectionResult::standard() with FQCN list and insertion offset, OR
     * DetectionResult::nonStandard() with a reason, OR DetectionResult::missing() if
     * the file cannot be read.
     */
    public function detect(string $bundlesPhpPath): DetectionResult
    {
        if (!class_exists(ParserFactory::class)) {
            return DetectionResult::nonStandard('nikic/php-parser is not installed; cannot detect');
        }

        $source = @file_get_contents($bundlesPhpPath);
        if (false === $source) {
            return DetectionResult::missing();
        }

        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        try {
            $stmts = $parser->parse($source);
        } catch (\Throwable) {
            return DetectionResult::nonStandard('parser threw on unrecoverable error');
        }

        if (null === $stmts) {
            return DetectionResult::nonStandard('parser returned null (unrecoverable parse error)');
        }
        if (1 !== count($stmts)) {
            return DetectionResult::nonStandard(
                'expected exactly one top-level statement, found '.count($stmts)
            );
        }

        $return = $stmts[0];
        if (!$return instanceof Return_) {
            return DetectionResult::nonStandard(
                'top-level statement is not `return [...]` (saw '.get_class($return).')'
            );
        }
        if (!$return->expr instanceof Array_) {
            return DetectionResult::nonStandard('return expression is not a literal array');
        }

        $array = $return->expr;
        $fqcns = $this->extractFqcns($array);
        if (null === $fqcns) {
            return DetectionResult::nonStandard('found a non-`::class`-keyed array item');
        }

        $endPos = $array->getEndFilePos();
        // getEndFilePos returns -1 if attributes are disabled, but ParserFactory enables them by default.
        if ($endPos < 0) {
            return DetectionResult::nonStandard('AST position attributes are missing');
        }

        return DetectionResult::standard($fqcns, $endPos);
    }

    /**
     * Extract FQCN strings from the bundles array, or null if any entry is non-conforming.
     *
     * @return list<string>|null
     */
    public function extractFqcns(Array_ $array): ?array
    {
        /** @var list<string> $fqcns */
        $fqcns = [];
        foreach ($array->items as $item) {
            if (!$item instanceof ArrayItem) {
                return null;
            }
            if (!$item->key instanceof ClassConstFetch) {
                return null;
            }
            if (!$item->key->class instanceof Node\Name) {
                return null;
            }
            if (!$item->key->name instanceof Node\Identifier || 'class' !== $item->key->name->toString()) {
                return null;
            }
            $fqcns[] = $item->key->class->toString();
        }

        return $fqcns;
    }

    private function buildMutatedSource(string $source, int $endPos): string
    {
        // Find the last non-whitespace character before the closing ] to determine if a comma is needed.
        $prevNonSpace = $endPos - 1;
        while ($prevNonSpace >= 0 && ctype_space($source[$prevNonSpace])) {
            --$prevNonSpace;
        }
        $prevChar = $prevNonSpace >= 0 ? $source[$prevNonSpace] : '';

        $lineEnding = str_contains(substr($source, 0, 4096), "\r\n") ? "\r\n" : "\n";
        $entry = '    '.self::TENANCY_BUNDLE_FQCN."::class => ['all' => true],";

        // If the previous existing entry ends with `,` no leading comma is needed.
        // If the array is empty (previous non-whitespace char is `[`), also no leading comma.
        // Otherwise (e.g., previous entry has no trailing comma), prepend `,`.
        if (',' === $prevChar || '[' === $prevChar) {
            $prefix = '';
        } else {
            $prefix = ','.$lineEnding;
        }

        // Insert at endPos (the `]` character). The `\n` already present before `]` in the source
        // is preserved, producing: ...last_entry,\n{entry}\n];\n  plus a normalized trailing \n.
        return substr($source, 0, $endPos).$prefix.$entry.$lineEnding.substr($source, $endPos).$lineEnding;
    }

    private function buildDiff(string $bundlesPhpPath): string
    {
        return sprintf(
            "--- %s (current)\n+++ %s (proposed)\n@@ insertion before closing ']' @@\n+    %s::class => ['all' => true],\n",
            $bundlesPhpPath,
            $bundlesPhpPath,
            self::TENANCY_BUNDLE_FQCN,
        );
    }

    /**
     * @return \Closure(string, string): array{passed: bool, error: string}
     */
    private static function defaultLintRunner(): \Closure
    {
        return static function (string $php, string $path): array {
            $process = new Process([$php, '-l', $path]);
            $process->setTimeout(10.0);
            $process->run();

            return [
                'passed' => $process->isSuccessful(),
                'error' => $process->getErrorOutput() ?: $process->getOutput(),
            ];
        };
    }
}
