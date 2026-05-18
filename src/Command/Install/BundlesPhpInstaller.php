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

/**
 * Detects, classifies, and mutates a project's `config/bundles.php` file to register
 * {@see \Tenancy\Bundle\TenancyBundle}. Backs the `tenancy:install` console command.
 *
 * The detection algorithm refuses to mutate any shape that deviates from the Symfony
 * Flex standard (single top-level `return [Class::class => ['env' => true], ...]`).
 * Refusal is a clean exit — the command prints a manual snippet and exits 0 — NOT
 * a tool failure (security-by-default per D-14).
 *
 * Write logic (string-template insertion, atomic dumpFile, .bak, php -l, restore) is
 * implemented in Plan 18-04. This class is constructed in two halves to keep diffs
 * surgically reviewable.
 *
 * @see https://github.com/nikic/PHP-Parser/blob/master/doc/component/Walking_the_AST.markdown
 */
final class BundlesPhpInstaller
{
    public const TENANCY_BUNDLE_FQCN = 'Tenancy\\Bundle\\TenancyBundle';

    public function __construct(
        private readonly Filesystem $filesystem = new Filesystem(),
    ) {
    }

    /**
     * Top-level entry point.
     *
     * Plan 03 scope: the WROTE branch throws LogicException (write logic shipped in Plan 04).
     * The three terminal branches (devDependencyMissing, alreadyRegistered,
     * refusedNonStandard) are fully implemented.
     */
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

        if (in_array(self::TENANCY_BUNDLE_FQCN, $detection->registeredFqcns, true)) {
            return InstallResult::alreadyRegistered();
        }

        // The write branch is implemented in Plan 18-04. This is INTENTIONAL —
        // see the plan annotation. The LogicException is the contract between
        // this plan and the next.
        throw new \LogicException('BundlesPhpInstaller write branch not yet implemented (scheduled for plan 18-04). Use `detect()` directly to exercise the AST classification path in tests.');
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
}
