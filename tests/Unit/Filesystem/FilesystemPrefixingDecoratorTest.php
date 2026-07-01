<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Unit\Filesystem;

use League\Flysystem\Config;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;
use League\Flysystem\StorageAttributes;
use PHPUnit\Framework\TestCase;
use Tenancy\Bundle\Context\TenantContext;
use Tenancy\Bundle\Filesystem\FilesystemPrefixingDecorator;
use Tenancy\Bundle\TenantInterface;

/**
 * Behaviour tests for FilesystemPrefixingDecorator.
 *
 * Covers BOOT-03 (prefix-mode decorator) per the plan 24-05 behaviour spec.
 *
 * Uses InMemoryFilesystemAdapter as the real inner adapter so assertions
 * against actual storage state prove prefix routing end-to-end.
 *
 * Key invariants tested:
 *  - All write-path methods prepend the tenant prefix before delegating
 *  - move() and copy() prefix both source AND destination
 *  - listContents() strips the prefix from returned entry paths (Q1)
 *  - publicUrl/temporaryUrl/checksum pass the prefixed path through (Q2)
 *  - No-tenant passthrough (empty prefix)
 *  - Custom prefix template support
 *  - Cross-tenant context-switch behavioural pin (live-read invariant)
 *  - Reflection: all instance properties are readonly (no-mutable-state pin)
 */
final class FilesystemPrefixingDecoratorTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Helpers / fixtures
    // -------------------------------------------------------------------------

    private function makeTenantStub(string $slug): TenantInterface
    {
        return new class($slug) implements TenantInterface {
            public function __construct(private string $slug)
            {
            }

            public function getSlug(): string
            {
                return $this->slug;
            }

            public function getDomain(): ?string
            {
                return null;
            }

            /** @return array<string, mixed> */
            public function getConnectionConfig(): array
            {
                return [];
            }

            public function getName(): string
            {
                return $this->slug;
            }

            public function isActive(): bool
            {
                return true;
            }

            public function isInMaintenance(): bool
            {
                return false;
            }

            public function getMailerDsn(): ?string
            {
                return null;
            }

            public function getMailerFrom(): ?string
            {
                return null;
            }

            public function getMailerReplyTo(): ?string
            {
                return null;
            }
        };
    }

    /**
     * Returns a fresh [decorator, inner (Filesystem), adapter, context] tuple.
     * The context already has the 'acme' tenant set.
     *
     * @return array{FilesystemPrefixingDecorator, FilesystemOperator, InMemoryFilesystemAdapter, TenantContext}
     */
    private function fixture(string $prefixTemplate = 'tenant_{slug}/'): array
    {
        $adapter = new InMemoryFilesystemAdapter();
        $inner = new Filesystem($adapter);
        $context = new TenantContext();
        $context->setTenant($this->makeTenantStub('acme'));
        $decorator = new FilesystemPrefixingDecorator($inner, $context, $prefixTemplate);

        return [$decorator, $inner, $adapter, $context];
    }

    // -------------------------------------------------------------------------
    // Core write-path prefix routing
    // -------------------------------------------------------------------------

    public function testWritePrependsTenantPrefix(): void
    {
        [$decorator, , $adapter] = $this->fixture();

        $decorator->write('reports/2026.csv', 'data');

        $this->assertTrue($adapter->fileExists('tenant_acme/reports/2026.csv'));
        $this->assertFalse($adapter->fileExists('reports/2026.csv'));
    }

    public function testReadPrependsTenantPrefix(): void
    {
        [$decorator] = $this->fixture();

        $decorator->write('hello.txt', 'world');

        $this->assertSame('world', $decorator->read('hello.txt'));
    }

    public function testFileExistsPrependsTenantPrefix(): void
    {
        [$decorator] = $this->fixture();

        $decorator->write('exists.txt', 'x');

        $this->assertTrue($decorator->fileExists('exists.txt'));
        $this->assertFalse($decorator->fileExists('does-not-exist.txt'));
    }

    public function testDeletePrependsTenantPrefix(): void
    {
        [$decorator, , $adapter] = $this->fixture();

        $adapter->write('tenant_acme/del.txt', 'x', new Config());

        $decorator->delete('del.txt');

        $this->assertFalse($adapter->fileExists('tenant_acme/del.txt'));
    }

    // -------------------------------------------------------------------------
    // move() and copy() — BOTH paths prefixed
    // -------------------------------------------------------------------------

    public function testMovePrefixesBothSourceAndDestination(): void
    {
        [$decorator, , $adapter] = $this->fixture();

        $decorator->write('a.txt', 'hello');
        $decorator->move('a.txt', 'b.txt');

        $this->assertFalse($adapter->fileExists('tenant_acme/a.txt'), 'source must be gone after move');
        $this->assertTrue($adapter->fileExists('tenant_acme/b.txt'), 'destination must exist after move');
    }

    public function testCopyPrefixesBothSourceAndDestination(): void
    {
        [$decorator, , $adapter] = $this->fixture();

        $decorator->write('b.txt', 'hello');
        $decorator->copy('b.txt', 'c.txt');

        $this->assertTrue($adapter->fileExists('tenant_acme/b.txt'), 'source must remain after copy');
        $this->assertTrue($adapter->fileExists('tenant_acme/c.txt'), 'destination must exist after copy');
    }

    // -------------------------------------------------------------------------
    // listContents — Q1: strip prefix from returned paths
    // -------------------------------------------------------------------------

    public function testListContentsReturnsRelativePaths(): void
    {
        [$decorator] = $this->fixture();

        $decorator->write('b.txt', 'hello');

        $listing = $decorator->listContents('');
        $paths = array_map(static fn (StorageAttributes $e): string => $e->path(), iterator_to_array($listing));

        $this->assertContains('b.txt', $paths, 'listing must return tenant-relative path');
        $this->assertNotContains('tenant_acme/b.txt', $paths, 'listing must NOT return raw prefixed path');
    }

    public function testListContentsDeepReturnsRelativePaths(): void
    {
        [$decorator] = $this->fixture();

        $decorator->write('nested/deep/file.txt', 'x');

        $listing = $decorator->listContents('', true);
        $paths = array_map(static fn (StorageAttributes $e): string => $e->path(), iterator_to_array($listing));

        foreach ($paths as $p) {
            $this->assertStringNotContainsString('tenant_acme/', $p, "Path '$p' must not contain raw tenant prefix");
        }
        $this->assertContains('nested/deep/file.txt', $paths);
    }

    // -------------------------------------------------------------------------
    // No-tenant passthrough
    // -------------------------------------------------------------------------

    public function testNoTenantPassesPathThroughUnmodified(): void
    {
        [$decorator, , $adapter, $context] = $this->fixture();

        $context->clear();

        $decorator->write('raw.txt', 'x');

        $this->assertTrue($adapter->fileExists('raw.txt'), 'with no tenant, path must not be prefixed');
        $this->assertFalse($adapter->fileExists('tenant_/raw.txt'), 'must not add empty-slug prefix');
    }

    // -------------------------------------------------------------------------
    // Custom prefix template
    // -------------------------------------------------------------------------

    public function testCustomPrefixTemplateApplied(): void
    {
        [$decorator, , $adapter] = $this->fixture('custom_{slug}_uploads/');

        $decorator->write('y.txt', 'x');

        $this->assertTrue($adapter->fileExists('custom_acme_uploads/y.txt'));
    }

    public function testDoubleSlugSubstitutionInTemplate(): void
    {
        [$decorator, , $adapter] = $this->fixture('tenant_{slug}/{slug}_data/');

        $decorator->write('doc.pdf', 'data');

        $this->assertTrue($adapter->fileExists('tenant_acme/acme_data/doc.pdf'));
    }

    /**
     * WR-01: a prefix_template WITHOUT a trailing slash must still produce
     * tenant-relative paths from listContents() (no leading '/').
     *
     * Without the trailing-slash normalisation in prefixer(), stripPrefix()
     * strips e.g. "tenant_acme" from "tenant_acme/reports.txt" leaving
     * "/reports.txt" — the leading slash breaks any round-trip into read().
     */
    public function testNoTrailingSlashTemplateProducesRelativeListPaths(): void
    {
        // No trailing slash in the template — prefixer() must normalise it.
        [$decorator] = $this->fixture('tenant_{slug}');

        $decorator->write('reports.txt', 'data');

        $listing = $decorator->listContents('');
        $paths = array_map(static fn (StorageAttributes $e): string => $e->path(), iterator_to_array($listing));

        $this->assertContains('reports.txt', $paths, 'listContents() must return "reports.txt", not "/reports.txt"');
        $this->assertNotContains('/reports.txt', $paths, 'listContents() must not produce a leading-slash path');
    }

    // -------------------------------------------------------------------------
    // writeStream passes through prefixed path
    // -------------------------------------------------------------------------

    public function testWriteStreamPrependsTenantPrefix(): void
    {
        [$decorator, , $adapter] = $this->fixture();

        $stream = fopen('php://memory', 'r+');
        $this->assertIsResource($stream);
        fwrite($stream, 'stream-data');
        rewind($stream);

        $decorator->writeStream('stream.bin', $stream);
        fclose($stream);

        $this->assertTrue($adapter->fileExists('tenant_acme/stream.bin'));
    }

    // -------------------------------------------------------------------------
    // directoryExists, createDirectory, deleteDirectory
    // -------------------------------------------------------------------------

    public function testCreateDirectoryPrefixesPath(): void
    {
        [$decorator] = $this->fixture();

        $decorator->createDirectory('mydir');

        $this->assertTrue($decorator->directoryExists('mydir'));
    }

    public function testDeleteDirectoryPrefixesPath(): void
    {
        [$decorator, , $adapter] = $this->fixture();

        $decorator->write('subdir/file.txt', 'x');
        $decorator->deleteDirectory('subdir');

        $this->assertFalse($adapter->fileExists('tenant_acme/subdir/file.txt'));
    }

    // -------------------------------------------------------------------------
    // publicUrl / temporaryUrl / checksum — Q2: prefixed path forwarded
    // -------------------------------------------------------------------------

    public function testPublicUrlReceivesPrefixedPath(): void
    {
        /** @var Filesystem&\PHPUnit\Framework\MockObject\MockObject $inner */
        $inner = $this->createMock(Filesystem::class);
        $context = new TenantContext();
        $context->setTenant($this->makeTenantStub('acme'));

        $decorator = new FilesystemPrefixingDecorator($inner, $context);

        $inner->expects($this->once())
            ->method('publicUrl')
            ->with('tenant_acme/avatar.png', [])
            ->willReturn('https://cdn.example.com/tenant_acme/avatar.png');

        $result = $decorator->publicUrl('avatar.png');

        $this->assertStringContainsString('tenant_acme/avatar.png', $result);
    }

    public function testTemporaryUrlReceivesPrefixedPath(): void
    {
        /** @var Filesystem&\PHPUnit\Framework\MockObject\MockObject $inner */
        $inner = $this->createMock(Filesystem::class);
        $context = new TenantContext();
        $context->setTenant($this->makeTenantStub('acme'));

        $decorator = new FilesystemPrefixingDecorator($inner, $context);
        $expiresAt = new \DateTimeImmutable('+1 hour');

        $inner->expects($this->once())
            ->method('temporaryUrl')
            ->with('tenant_acme/avatar.png', $expiresAt, [])
            ->willReturn('https://cdn.example.com/tenant_acme/avatar.png?sig=xxx');

        $result = $decorator->temporaryUrl('avatar.png', $expiresAt);

        $this->assertStringContainsString('tenant_acme/avatar.png', $result);
    }

    public function testChecksumReceivesPrefixedPath(): void
    {
        /** @var Filesystem&\PHPUnit\Framework\MockObject\MockObject $inner */
        $inner = $this->createMock(Filesystem::class);
        $context = new TenantContext();
        $context->setTenant($this->makeTenantStub('acme'));

        $decorator = new FilesystemPrefixingDecorator($inner, $context);

        $inner->expects($this->once())
            ->method('checksum')
            ->with('tenant_acme/a.txt', [])
            ->willReturn('abc123');

        $result = $decorator->checksum('a.txt');

        $this->assertSame('abc123', $result);
    }

    // -------------------------------------------------------------------------
    // Cross-tenant context-switch — live-read invariant (Stranger-Things test)
    // -------------------------------------------------------------------------

    public function testLiveReadInvariantCrossTenantContextSwitch(): void
    {
        $adapter = new InMemoryFilesystemAdapter();
        $inner = new Filesystem($adapter);
        $context = new TenantContext();
        $decorator = new FilesystemPrefixingDecorator($inner, $context);

        // First write under tenant 'acme'
        $context->setTenant($this->makeTenantStub('acme'));
        $decorator->write('doc.txt', 'acme-content');

        // Switch tenant to 'globex' — SAME decorator instance
        $context->setTenant($this->makeTenantStub('globex'));
        $decorator->write('doc.txt', 'globex-content');

        // Each tenant's file is stored under its own prefix
        $this->assertTrue($adapter->fileExists('tenant_acme/doc.txt'), 'acme file must be at acme prefix');
        $this->assertTrue($adapter->fileExists('tenant_globex/doc.txt'), 'globex file must be at globex prefix');

        // The content belongs to the correct tenant
        $this->assertSame('acme-content', $inner->read('tenant_acme/doc.txt'));
        $this->assertSame('globex-content', $inner->read('tenant_globex/doc.txt'));
    }

    // -------------------------------------------------------------------------
    // Reflection — no-mutable-state pin
    // -------------------------------------------------------------------------

    public function testHasNoMutableInstanceState(): void
    {
        $reflection = new \ReflectionClass(FilesystemPrefixingDecorator::class);

        foreach ($reflection->getProperties() as $property) {
            $this->assertTrue(
                $property->isReadOnly() || $property->isStatic(),
                sprintf(
                    'Property "%s" must be readonly or static — mutable instance state would leak across tenants in workers',
                    $property->getName(),
                ),
            );
        }
    }

    public function testClassIsFinal(): void
    {
        $reflection = new \ReflectionClass(FilesystemPrefixingDecorator::class);
        $this->assertTrue($reflection->isFinal(), 'FilesystemPrefixingDecorator must be final');
    }

    public function testImplementsFilesystemOperator(): void
    {
        $reflection = new \ReflectionClass(FilesystemPrefixingDecorator::class);
        $this->assertTrue($reflection->implementsInterface(FilesystemOperator::class));
    }
}
