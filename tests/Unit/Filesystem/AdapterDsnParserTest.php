<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Unit\Filesystem;

use League\Flysystem\FilesystemAdapter;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;
use League\Flysystem\Local\LocalFilesystemAdapter;
use PHPUnit\Framework\TestCase;
use Tenancy\Bundle\Filesystem\AdapterDsnParser;

/**
 * Behavioural coverage of the Phase 24 AdapterDsnParser (DSN → FilesystemAdapter factory).
 *
 * Covers three v0.4 schemes — local://, memory://, s3:// — plus the
 * unknown-scheme failure mode and the addScheme() extension point.
 *
 * Per .planning/phases/24-filesystem-bootstrapper/24-CONTEXT.md §DEC-FILE-MODE
 * (per_tenant_adapter mode requires a DSN parser; Flysystem 3 does not ship one).
 *
 * Per .planning/phases/24-filesystem-bootstrapper/24-RESEARCH.md §Open Questions Q3
 * (unknown schemes raise a LogicException-derived exception so Symfony Messenger's
 * default retry strategy excludes them; a misconfigured DSN is not a transient fault).
 *
 * NOTE: sibling Plan 24-02 owns the dedicated
 * `\Tenancy\Bundle\Exception\UnsupportedAdapterDsnSchemeException` class. Until
 * that plan lands, the parser falls back to throwing a plain `\LogicException`
 * with an identically shaped message. These tests assert on the LogicException
 * ancestry (the load-bearing Messenger no-retry contract) so they pass on both
 * sides of the 24-02 landing — when the dedicated class arrives, callers that
 * `catch (UnsupportedAdapterDsnSchemeException $e)` still match via class
 * specialisation, and these ancestry assertions still hold.
 *
 * The credential-leak negative assertions (testS3ExceptionMessageDoesNotLeakCredentials,
 * testUnknownSchemeExceptionDoesNotLeakDsnCredentials) are the regression gates for
 * threat T-24-04-01 (S3 access key/secret leakage via exception messages).
 */
final class AdapterDsnParserTest extends TestCase
{
    private AdapterDsnParser $parser;

    protected function setUp(): void
    {
        $this->parser = new AdapterDsnParser();
    }

    // ─── Positive: local:// ────────────────────────────────────────────────

    public function testParseLocalReturnsLocalAdapter(): void
    {
        // LocalFilesystemAdapter eagerly mkdir's the directory in its
        // constructor — use a writable tmp path so the assertion exercises
        // only the parser, not the host filesystem.
        $tmp = sys_get_temp_dir().'/tenancy_adapter_dsn_test_'.bin2hex(random_bytes(4));
        $adapter = $this->parser->parse('local://'.$tmp);
        self::assertInstanceOf(LocalFilesystemAdapter::class, $adapter);
        @rmdir($tmp);
    }

    public function testParseLocalWithWriteFlagsQueryString(): void
    {
        // The LOCK_EX-disabling caller path — query string is accepted; we
        // assert only that the adapter is constructed (the third constructor
        // arg is private state we cannot reflect on, so we verify the
        // happy-path returns the right class).
        $tmp = sys_get_temp_dir().'/tenancy_adapter_dsn_test_'.bin2hex(random_bytes(4));
        $adapter = $this->parser->parse('local://'.$tmp.'?write_flags=2');
        self::assertInstanceOf(LocalFilesystemAdapter::class, $adapter);
        @rmdir($tmp);
    }

    public function testParseLocalWithEmptyPathThrowsInvalidArgument(): void
    {
        // `local://` with no path is a programmer error — distinct from the
        // unknown-scheme path so it surfaces as InvalidArgumentException
        // (which still extends LogicException — Messenger no-retry holds).
        $this->expectException(\InvalidArgumentException::class);
        $this->parser->parse('local://');
    }

    // ─── Positive: memory:// ───────────────────────────────────────────────

    public function testParseMemoryReturnsInMemoryAdapter(): void
    {
        $adapter = $this->parser->parse('memory://');
        self::assertInstanceOf(InMemoryFilesystemAdapter::class, $adapter);
    }

    public function testParseMemoryWithVisibilityPrivate(): void
    {
        $adapter = $this->parser->parse('memory://?visibility=private');
        self::assertInstanceOf(InMemoryFilesystemAdapter::class, $adapter);
    }

    public function testParseMemoryWithAnyAuthorityWorks(): void
    {
        // memory:// has no real host/path semantics — any authority string
        // resolves to a fresh in-memory adapter.
        $adapter = $this->parser->parse('memory://anything');
        self::assertInstanceOf(InMemoryFilesystemAdapter::class, $adapter);
    }

    // ─── Negative: s3:// without AWS SDK ───────────────────────────────────

    public function testParseS3WithoutAwsSdkRaisesLogicException(): void
    {
        if (class_exists('Aws\\S3\\S3Client')) {
            self::markTestSkipped(
                'aws/aws-sdk-php is installed — s3:// negative path cannot be exercised in this environment.'
            );
        }

        $this->expectException(\LogicException::class);
        // Use a credential-bearing DSN so the same test fixture exercises both
        // the optional-dep guard AND the credential-leak guard.
        $this->parser->parse('s3:///my-bucket?region=eu-central-1&key=AKIA_X&secret=ABC');
    }

    public function testS3ExceptionIsLogicNotRuntime(): void
    {
        if (class_exists('Aws\\S3\\S3Client')) {
            self::markTestSkipped('aws/aws-sdk-php is installed — s3:// negative path not exercisable.');
        }

        try {
            $this->parser->parse('s3:///my-bucket');
            self::fail('Expected LogicException-derived exception.');
        } catch (\LogicException $e) {
            // Messenger no-retry invariant — load-bearing.
            self::assertNotInstanceOf(\RuntimeException::class, $e);
            self::assertStringContainsString('s3', $e->getMessage());
        }
    }

    public function testS3ExceptionMessageDoesNotLeakCredentials(): void
    {
        if (class_exists('Aws\\S3\\S3Client')) {
            self::markTestSkipped(
                'aws/aws-sdk-php is installed — s3:// negative path cannot be exercised in this environment.'
            );
        }

        try {
            $this->parser->parse('s3:///bucket?key=LEAK_AKIA_X&secret=LEAK_SECRET_Y');
            self::fail('Expected LogicException-derived exception.');
        } catch (\LogicException $e) {
            self::assertDoesNotMatchRegularExpression(
                '/LEAK_AKIA_X|LEAK_SECRET_Y/',
                $e->getMessage(),
                'S3 credentials must NOT appear in the exception message (T-24-04-01).'
            );
        }
    }

    public function testS3ExceptionMessageDoesNotContainQueryString(): void
    {
        if (class_exists('Aws\\S3\\S3Client')) {
            self::markTestSkipped('aws/aws-sdk-php is installed — s3:// negative path not exercisable.');
        }

        try {
            $this->parser->parse('s3:///bucket?key=A&secret=B');
            self::fail('Expected LogicException-derived exception.');
        } catch (\LogicException $e) {
            // Guard against the lazier failure mode: the exception echoes the
            // raw DSN. Even with placeholder values that wouldn't be flagged
            // by the LEAK_ check above, the literal `key=` / `secret=` markers
            // must not appear — the exception's input is the SCHEME, not the DSN.
            self::assertDoesNotMatchRegularExpression('/key=|secret=/i', $e->getMessage());
        }
    }

    // ─── Negative: unknown schemes ─────────────────────────────────────────

    public function testParseUnknownSchemeRaisesLogicException(): void
    {
        $this->expectException(\LogicException::class);
        $this->parser->parse('azure://account/container');
    }

    public function testParseMalformedStringRaisesLogicException(): void
    {
        // No scheme means the same failure mode as unknown scheme.
        $this->expectException(\LogicException::class);
        $this->parser->parse('not-a-dsn');
    }

    public function testParseEmptyStringRaisesLogicException(): void
    {
        $this->expectException(\LogicException::class);
        $this->parser->parse('');
    }

    public function testUnknownSchemeExceptionIsLogicNotRuntime(): void
    {
        try {
            $this->parser->parse('azure://account');
            self::fail('Expected LogicException-derived exception.');
        } catch (\LogicException $e) {
            // Messenger no-retry invariant — load-bearing.
            self::assertNotInstanceOf(\RuntimeException::class, $e);
        }
    }

    public function testUnknownSchemeExceptionDoesNotLeakDsnCredentials(): void
    {
        try {
            // ftp+credentials DSN that is NOT a registered scheme — the
            // exception MUST NOT echo the userinfo password.
            $this->parser->parse('ftp://user:LEAK_PWD_Z@host/path');
            self::fail('Expected LogicException-derived exception for ftp.');
        } catch (\LogicException $e) {
            self::assertDoesNotMatchRegularExpression(
                '/LEAK_PWD_Z/',
                $e->getMessage(),
                'Unknown-scheme exception must not echo DSN userinfo credentials.'
            );
        }
    }

    // ─── Registry / extension point ────────────────────────────────────────

    public function testSupportedSchemesContainsDefaultsInInsertionOrder(): void
    {
        self::assertSame(['local', 'memory', 's3'], $this->parser->supportedSchemes());
    }

    public function testAddSchemeRegistersAndResolves(): void
    {
        $marker = new InMemoryFilesystemAdapter();
        $this->parser->addScheme('azure', static fn (string $dsn): FilesystemAdapter => $marker);

        self::assertContains('azure', $this->parser->supportedSchemes());
        self::assertSame($marker, $this->parser->parse('azure://account/container'));
    }

    public function testAddSchemeLowercasesKey(): void
    {
        $marker = new InMemoryFilesystemAdapter();
        $this->parser->addScheme('CUSTOM', static fn (string $dsn): FilesystemAdapter => $marker);

        // The key is normalised — `custom` is the resolvable scheme.
        self::assertContains('custom', $this->parser->supportedSchemes());
        self::assertSame($marker, $this->parser->parse('custom://x'));
    }

    public function testAddSchemeOverwritesExisting(): void
    {
        $custom = new InMemoryFilesystemAdapter();
        $this->parser->addScheme('local', static fn (string $dsn): FilesystemAdapter => $custom);

        // Re-registering an existing scheme is last-write-wins (no throw —
        // matches the documented `addScheme` semantics).
        self::assertSame($custom, $this->parser->parse('local:///tmp/x'));
    }

    // ─── WR-06: array-style query values throw InvalidArgumentException ─────

    /**
     * WR-06: parse_str array-style query syntax (write_flags[]=2) must throw
     * an InvalidArgumentException naming the offending key, never the value
     * (credential-leak discipline T-24-04-01 — the value might be a secret).
     */
    public function testArrayStyleQueryValueThrowsInvalidArgumentException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        // 'write_flags[]' parses to an array via parse_str — must be rejected.
        $this->parser->parse('local:///srv/uploads?write_flags[]=2');
    }

    public function testArrayStyleQueryExceptionMessageNamesKey(): void
    {
        try {
            $this->parser->parse('local:///srv/uploads?write_flags[]=2');
            self::fail('Expected InvalidArgumentException.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('write_flags', $e->getMessage());
        }
    }

    public function testArrayStyleQueryExceptionMessageDoesNotEchoValue(): void
    {
        // Credential-leak discipline: the exception must not echo the value
        // (the value could be a secret in a real DSN).
        try {
            $this->parser->parse('memory://?secret_key[]=LEAK_SECRET_VAL');
            self::fail('Expected InvalidArgumentException.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringNotContainsString('LEAK_SECRET_VAL', $e->getMessage());
            // Key name must appear (operator needs to know what to fix).
            self::assertStringContainsString('secret_key', $e->getMessage());
        }
    }
}
