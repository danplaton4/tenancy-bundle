<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Unit\Resolver;

use PHPUnit\Framework\TestCase;

/**
 * WR-02 source-order invariant test.
 *
 * Pins the ordering invariant that the null-tenantProvider early-return guard
 * in `ConsoleResolver::onConsoleCommand()` MUST precede the
 * `$appDefinition->addOption(...)` Application-definition mutation. If a
 * future refactor moves the guard below the mutation, every console command
 * in zero-config mode (no provider bound) gets a stale `--tenant` flag added
 * to its global Application options — visible in `--help`/`--list` output —
 * even though the resolver itself does nothing in that mode.
 *
 * The check is intentionally a SOURCE-LEVEL scan (file_get_contents +
 * line-number search), not a runtime exercise. Runtime behaviour is already
 * covered by ConsoleResolverTest; this test exists solely to make the
 * ordering invariant load-bearing.
 */
final class ConsoleResolverGuardOrderingTest extends TestCase
{
    private const SOURCE_RELATIVE_PATH = '/src/Resolver/ConsoleResolver.php';

    public function testGuardPrecedesApplicationMutation(): void
    {
        $sourcePath = $this->resolveSourcePath();
        $source = file_get_contents($sourcePath);
        self::assertNotFalse($source, 'Failed to read ConsoleResolver.php');

        $lines = explode("\n", $source);

        $guardLine = $this->findFirstLineContaining($lines, 'null === $this->tenantProvider');
        $mutationLine = $this->findFirstLineContaining($lines, 'addOption(');

        self::assertNotNull(
            $guardLine,
            'Could not locate the null-tenantProvider guard in ConsoleResolver.php — has the guard been removed?',
        );
        self::assertNotNull(
            $mutationLine,
            'Could not locate the addOption() Application-definition mutation in ConsoleResolver.php',
        );

        self::assertLessThan(
            $mutationLine,
            $guardLine,
            sprintf(
                'WR-02 invariant violated — the null-tenantProvider guard (line %d) MUST precede '
                .'the Application::addOption mutation (line %d). Moving the guard below the mutation '
                .'pollutes every console command\'s --help/--list output with a stale --tenant flag in '
                .'zero-config mode. See WR-02 / .planning/phases/23-tech-debt-closure/23-CONTEXT.md.',
                $guardLine,
                $mutationLine,
            ),
        );
    }

    public function testGuardOrderingCommentBlockExists(): void
    {
        $sourcePath = $this->resolveSourcePath();
        $source = file_get_contents($sourcePath);
        self::assertNotFalse($source, 'Failed to read ConsoleResolver.php');

        // The presence of these literal tokens is the tripwire: a refactor
        // that strips the comment is the first signal someone is about to
        // move the guard. Keeping both tokens load-bearing makes the
        // intent-preserving rename non-trivial.
        self::assertStringContainsString(
            'GUARD ORDERING',
            $source,
            'The WR-02 "GUARD ORDERING" comment marker is missing from ConsoleResolver.php — '
            .'this comment pins the guard-precedes-mutation invariant for future maintainers.',
        );
        self::assertStringContainsString(
            'MUST',
            $source,
            'The WR-02 guard-ordering comment must include the literal token "MUST" to make '
            .'the invariant a stop-the-build signal in code review.',
        );
    }

    private function resolveSourcePath(): string
    {
        // tests/Unit/Resolver -> dirname(__DIR__, 3) -> project root
        return \dirname(__DIR__, 3).self::SOURCE_RELATIVE_PATH;
    }

    /**
     * @param list<string> $lines
     */
    private function findFirstLineContaining(array $lines, string $needle): ?int
    {
        foreach ($lines as $index => $line) {
            if (str_contains($line, $needle)) {
                // 1-based line numbers for human-readable failure messages.
                return $index + 1;
            }
        }

        return null;
    }
}
