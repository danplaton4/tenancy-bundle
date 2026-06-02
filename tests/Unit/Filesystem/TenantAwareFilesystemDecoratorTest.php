<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Unit\Filesystem;

use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;
use PHPUnit\Framework\TestCase;
use Tenancy\Bundle\Context\TenantContext;
use Tenancy\Bundle\Exception\MissingFilesystemConfigException;
use Tenancy\Bundle\Filesystem\AdapterDsnParser;
use Tenancy\Bundle\Filesystem\LruFilesystemCache;
use Tenancy\Bundle\Filesystem\TenantAwareFilesystemDecorator;
use Tenancy\Bundle\TenantInterface;

/**
 * Behavioural coverage for TenantAwareFilesystemDecorator.
 *
 * Per .planning/phases/24-filesystem-bootstrapper/24-CONTEXT.md §DEC-FILE-MODE
 * (per_tenant_adapter mode reads tenant filesystemConfig.adapter_dsn and
 * instantiates a per-tenant FilesystemOperator via AdapterDsnParser; cached
 * via LruFilesystemCache).
 *
 * Tests use REAL instances of LruFilesystemCache, AdapterDsnParser, and
 * InMemoryFilesystemAdapter — no mocks — so assertions against real Flysystem
 * behaviour prove end-to-end correctness.
 *
 * Covers every bullet in Plan 24-06 Task 1 <behavior>:
 *  1. Cache miss → build → cache hit (size/hits counters)
 *  2. Cross-tenant isolation: two tenants with memory:// get DIFFERENT instances
 *  3. MissingFilesystemConfigException on null getFilesystemConfig()
 *  4. MissingFilesystemConfigException on missing adapter_dsn key
 *  5. MissingFilesystemConfigException on entity without getFilesystemConfig()
 *  6. No-active-tenant passthrough to inner
 *  7. Cross-tenant context switch behavioural pin (live-read invariant)
 *  8. Spot-check of write/read/delete/move/copy routing
 *  9. Reflection: all properties readonly; class final
 */
final class TenantAwareFilesystemDecoratorTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Build a TenantInterface stub with an optional getFilesystemConfig() method.
     *
     * @param array{prefix?: string, adapter_dsn?: string, services?: array<string>}|null $cfg
     */
    private function makeTenant(string $slug, ?array $cfg = null, bool $withConfigMethod = true): TenantInterface
    {
        if ($withConfigMethod) {
            return new class($slug, $cfg) implements TenantInterface {
                /** @param array{prefix?: string, adapter_dsn?: string, services?: array<string>}|null $cfg */
                public function __construct(private string $slug, private ?array $cfg)
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

                /** @return array{prefix?: string, adapter_dsn?: string, services?: array<string>}|null */
                public function getFilesystemConfig(): ?array
                {
                    return $this->cfg;
                }
            };
        }

        // Entity WITHOUT getFilesystemConfig() — simulates custom tenant without
        // TenantFilesystemConfigTrait. The method_exists() probe in the decorator
        // must return false and treat it the same as null config.
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
     * Build the decorator SUT along with its real collaborators.
     *
     * @return array{TenantAwareFilesystemDecorator, LruFilesystemCache, TenantContext, Filesystem}
     */
    private function fixture(): array
    {
        $innerAdapter = new InMemoryFilesystemAdapter();
        $inner = new Filesystem($innerAdapter);
        $cache = new LruFilesystemCache();
        $parser = new AdapterDsnParser();
        $context = new TenantContext();
        $decorator = new TenantAwareFilesystemDecorator($inner, $context, $cache, $parser);

        return [$decorator, $cache, $context, $inner];
    }

    // -------------------------------------------------------------------------
    // Cache miss → build → cache hit
    // -------------------------------------------------------------------------

    public function testCacheMissBuildsFilesystemAndCachesIt(): void
    {
        [$decorator, $cache, $context] = $this->fixture();
        $context->setTenant($this->makeTenant('acme', ['adapter_dsn' => 'memory://']));

        // First call: cache miss — build + cache
        $decorator->write('hello.txt', 'world');
        self::assertSame(1, $cache->size(), 'cache should hold exactly one entry after first call');
        self::assertSame(0, $cache->hits(), 'no cache hit on first call');

        // Second call: cache hit
        $content = $decorator->read('hello.txt');
        self::assertSame('world', $content);
        self::assertSame(1, $cache->size(), 'cache size unchanged on hit');
        self::assertSame(1, $cache->hits(), 'cache hit counter incremented');
    }

    // -------------------------------------------------------------------------
    // Cross-tenant isolation
    // -------------------------------------------------------------------------

    public function testCrossTenantsGetDistinctFilesystemInstances(): void
    {
        [$decorator, $cache, $context] = $this->fixture();

        // Touch acme first
        $context->setTenant($this->makeTenant('acme', ['adapter_dsn' => 'memory://']));
        $decorator->write('acme-file.txt', 'acme-data');

        // Touch globex
        $context->setTenant($this->makeTenant('globex', ['adapter_dsn' => 'memory://']));
        $decorator->write('globex-file.txt', 'globex-data');

        // Both adapters cached
        self::assertSame(2, $cache->size(), 'both tenants should be in the cache');
        self::assertNotSame(
            $cache->get('acme'),
            $cache->get('globex'),
            'acme and globex must have DIFFERENT Filesystem instances',
        );
    }

    public function testTenantAWritesAreNotVisibleToTenantB(): void
    {
        [$decorator, , $context] = $this->fixture();

        // acme writes a file
        $context->setTenant($this->makeTenant('acme', ['adapter_dsn' => 'memory://']));
        $decorator->write('secret.txt', 'acme-secret');

        // globex checks for the same path — must not be visible
        $context->setTenant($this->makeTenant('globex', ['adapter_dsn' => 'memory://']));
        self::assertFalse(
            $decorator->fileExists('secret.txt'),
            "acme's write must not be visible in globex's adapter",
        );
    }

    // -------------------------------------------------------------------------
    // MissingFilesystemConfigException cases
    // -------------------------------------------------------------------------

    public function testNullConfigThrowsMissingFilesystemConfigException(): void
    {
        [$decorator, , $context] = $this->fixture();
        $context->setTenant($this->makeTenant('broken', null));

        $this->expectException(MissingFilesystemConfigException::class);
        $decorator->write('x', 'y');
    }

    public function testMissingAdapterDsnKeyThrowsMissingFilesystemConfigException(): void
    {
        [$decorator, , $context] = $this->fixture();
        // Has a prefix key but NO adapter_dsn key
        $context->setTenant($this->makeTenant('broken2', ['prefix' => 'broken2/']));

        $this->expectException(MissingFilesystemConfigException::class);
        $decorator->write('x', 'y');
    }

    public function testEntityWithoutGetFilesystemConfigMethodThrowsMissingFilesystemConfigException(): void
    {
        [$decorator, , $context] = $this->fixture();
        // Tenant stub that intentionally does NOT have getFilesystemConfig()
        $context->setTenant($this->makeTenant('notype', null, withConfigMethod: false));

        $this->expectException(MissingFilesystemConfigException::class);
        $decorator->write('x', 'y');
    }

    public function testMissingFilesystemConfigExceptionIsLogicException(): void
    {
        [$decorator, , $context] = $this->fixture();
        $context->setTenant($this->makeTenant('badconfig', null));

        try {
            $decorator->write('x', 'y');
            self::fail('Expected MissingFilesystemConfigException to be thrown');
        } catch (MissingFilesystemConfigException $e) {
            self::assertInstanceOf(
                \LogicException::class,
                $e,
                'MissingFilesystemConfigException must extend LogicException (Messenger no-retry invariant)',
            );
            self::assertNotInstanceOf(
                \RuntimeException::class,
                $e,
                'Must NOT be a RuntimeException (would trigger Messenger retry)',
            );
            self::assertStringContainsString('badconfig', $e->getMessage());
        }
    }

    // -------------------------------------------------------------------------
    // No-active-tenant passthrough
    // -------------------------------------------------------------------------

    public function testNoActiveTenantPassesThroughToInner(): void
    {
        [$decorator, $cache, $context, $inner] = $this->fixture();
        // No tenant set → context->getTenant() returns null
        // Write via decorator with no tenant — must land in the shared $inner
        $decorator->write('landlord.txt', 'landlord-data');

        // No per-tenant adapters were built
        self::assertSame(0, $cache->size(), 'no per-tenant instance should be created with no active tenant');

        // The file landed in the inner adapter
        self::assertTrue($inner->fileExists('landlord.txt'));
    }

    // -------------------------------------------------------------------------
    // Cross-tenant context switch behavioural pin (live-read invariant)
    // -------------------------------------------------------------------------

    public function testContextSwitchRoutesToCorrectAdapter(): void
    {
        [$decorator, $cache, $context] = $this->fixture();

        // Step 1: acme writes
        $context->setTenant($this->makeTenant('acme', ['adapter_dsn' => 'memory://']));
        $decorator->write('item.txt', 'value-acme');

        // Step 2: switch context to globex — live-read means the NEXT call goes to globex's adapter
        $context->setTenant($this->makeTenant('globex', ['adapter_dsn' => 'memory://']));
        $decorator->write('item.txt', 'value-globex');

        // 2 distinct adapters cached
        self::assertSame(2, $cache->size());

        // Verify isolation: acme's adapter still has "value-acme"
        $acmeFs = $cache->get('acme');
        self::assertNotNull($acmeFs);
        self::assertSame('value-acme', $acmeFs->read('item.txt'));

        // globex's adapter has "value-globex"
        $globexFs = $cache->get('globex');
        self::assertNotNull($globexFs);
        self::assertSame('value-globex', $globexFs->read('item.txt'));
    }

    // -------------------------------------------------------------------------
    // Routing of write/read/delete (spot-check)
    // -------------------------------------------------------------------------

    public function testWriteAndReadRouteToPerTenantAdapter(): void
    {
        [$decorator, $cache, $context] = $this->fixture();
        $context->setTenant($this->makeTenant('spot', ['adapter_dsn' => 'memory://']));

        $decorator->write('doc.txt', 'content');
        self::assertSame('content', $decorator->read('doc.txt'));
        self::assertTrue($decorator->fileExists('doc.txt'));
    }

    public function testDeleteRoutesToPerTenantAdapter(): void
    {
        [$decorator, , $context] = $this->fixture();
        $context->setTenant($this->makeTenant('deltest', ['adapter_dsn' => 'memory://']));

        $decorator->write('todelete.txt', 'bye');
        $decorator->delete('todelete.txt');
        self::assertFalse($decorator->fileExists('todelete.txt'));
    }

    public function testMoveRoutesToPerTenantAdapter(): void
    {
        [$decorator, , $context] = $this->fixture();
        $context->setTenant($this->makeTenant('movetest', ['adapter_dsn' => 'memory://']));

        $decorator->write('src.txt', 'data');
        $decorator->move('src.txt', 'dst.txt');
        self::assertFalse($decorator->fileExists('src.txt'));
        self::assertTrue($decorator->fileExists('dst.txt'));
    }

    // -------------------------------------------------------------------------
    // Reflection assertions
    // -------------------------------------------------------------------------

    public function testAllPropertiesAreReadonly(): void
    {
        $rc = new \ReflectionClass(TenantAwareFilesystemDecorator::class);

        foreach ($rc->getProperties() as $property) {
            if ($property->isStatic()) {
                continue;
            }

            self::assertTrue(
                $property->isReadOnly(),
                sprintf(
                    'Property $%s must be readonly (live-read invariant: no mutable instance state)',
                    $property->getName(),
                ),
            );
        }
    }

    public function testClassIsFinal(): void
    {
        $rc = new \ReflectionClass(TenantAwareFilesystemDecorator::class);
        self::assertTrue($rc->isFinal(), 'TenantAwareFilesystemDecorator must be declared final');
    }

    public function testDecoratorImplementsFilesystemOperator(): void
    {
        self::assertInstanceOf(
            FilesystemOperator::class,
            new TenantAwareFilesystemDecorator(
                new Filesystem(new InMemoryFilesystemAdapter()),
                new TenantContext(),
                new LruFilesystemCache(),
                new AdapterDsnParser(),
            ),
        );
    }
}
