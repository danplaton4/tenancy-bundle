<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Unit\Profiler;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Tenancy\Bundle\Context\TenantContext;
use Tenancy\Bundle\Mailer\LruTransportCache;
use Tenancy\Bundle\Profiler\TenantDataCollector;
use Tenancy\Bundle\Profiler\TenantProfilerStash;
use Tenancy\Bundle\TenantInterface;
use Tenancy\Bundle\Tests\Integration\Support\StubTenantMailerExtension;
use Tenancy\Bundle\Tests\Unit\Mailer\Fixture\StoppableSpyTransport;

/**
 * Phase 20 Plan 07 — Mailer subsection of the TenantDataCollector.
 *
 * Adds a 10-key `mailer` sub-array to `$this->data` ONLY when the
 * LruTransportCache dependency is present (mailer interface installed).
 * DSN redaction uses DsnSanitizer::redact — single source of truth shared
 * with SanitizingMailerDecorator (Plan 02) so the WDT panel and exception
 * messages can never drift.
 *
 * @see .planning/phases/20-mailer-bootstrapper/20-07-PLAN.md
 */
final class TenantDataCollectorMailerSectionTest extends TestCase
{
    private TenantProfilerStash $stash;
    private TenantContext $tenantContext;

    protected function setUp(): void
    {
        if (!interface_exists(MailerInterface::class)) {
            self::markTestSkipped('symfony/mailer not installed — mailer subsection only ships when the dep is present.');
        }

        $this->stash = new TenantProfilerStash();
        $this->tenantContext = new TenantContext();
    }

    private function makeTenant(string $slug = 'acme'): TenantInterface
    {
        return new class($slug) implements TenantInterface {
            use StubTenantMailerExtension;

            public function __construct(private readonly string $slug)
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
        };
    }

    private function makeCollector(?LruTransportCache $cache, ?string $async = null): TenantDataCollector
    {
        return new TenantDataCollector(
            $this->stash,
            $this->tenantContext,
            'database_per_tenant',
            'default',
            $cache,
            $async,
        );
    }

    private function collect(TenantDataCollector $collector): void
    {
        $collector->collect(Request::create('/'), new Response());
    }

    /**
     * Test 1 — when LruTransportCache is null (mailer dep absent), the
     * 'mailer' key MUST NOT appear in $this->data. The Twig template gates
     * the subsection on `{% if collector.data.mailer is defined %}`.
     */
    public function testMailerKeyAbsentWhenCacheDepIsNull(): void
    {
        $collector = $this->makeCollector(null, null);
        $this->collect($collector);

        self::assertFalse(
            array_key_exists('mailer', $collector->getData()),
            'When LruTransportCache is null, the mailer key MUST NOT be present in $this->data (degrades gracefully).'
        );
    }

    /**
     * Test 2 — when no tenant is active (null state) but cache is wired,
     * the mailer subsection STILL renders with cache metrics + strategy,
     * per-tenant fields are null, badge defaults to 'OK' ("nothing wrong,
     * just no tenant active").
     */
    public function testMailerKeyPresentWhenNoTenantButCacheWired(): void
    {
        $cache = new LruTransportCache(32);
        $collector = $this->makeCollector($cache, 'auto');
        $this->collect($collector);

        $data = $collector->getData();
        self::assertArrayHasKey('mailer', $data);
        self::assertNull($data['mailer']['from']);
        self::assertNull($data['mailer']['reply_to']);
        self::assertNull($data['mailer']['dsn_redacted']);
        self::assertSame(0, $data['mailer']['cache_size']);
        self::assertSame(32, $data['mailer']['cache_max']);
        self::assertSame('x_transport', $data['mailer']['strategy']);
        self::assertSame('auto', $data['mailer']['async_detected']);
        self::assertSame('OK', $data['mailer']['badge']);
    }

    /**
     * Test 3 — fully resolved tenant with mailerDsn, mailerFrom set,
     * cache populated and exercised. Exact 10-key shape returned.
     */
    public function testMailerKeyProducesTenKeysWithRedactedDsn(): void
    {
        // Drive the cache to size=3 with one hit recorded.
        $cache = new LruTransportCache(32);
        $cache->set('acme', new StoppableSpyTransport('a'));
        $cache->set('beta', new StoppableSpyTransport('b'));
        $cache->set('gamma', new StoppableSpyTransport('c'));
        $cache->get('acme'); // +1 hit

        $tenant = $this->makeTenant('acme');
        $tenant->setMailerDsn('smtp://user:secret@host:25');
        $tenant->setMailerFrom('x@y.com');
        $tenant->setMailerReplyTo(null);
        $this->tenantContext->setTenant($tenant);

        $collector = $this->makeCollector($cache, 'auto');
        $this->collect($collector);

        $data = $collector->getData();
        self::assertArrayHasKey('mailer', $data);
        self::assertSame(
            [
                'from' => 'x@y.com',
                'reply_to' => null,
                'dsn_redacted' => 'smtp://user:***@host:25',
                'cache_size' => 3,
                'cache_max' => 32,
                'cache_hits' => 1,
                'cache_evictions' => 0,
                'strategy' => 'x_transport',
                'async_detected' => 'auto',
                'badge' => 'OK',
            ],
            $data['mailer']
        );
        self::assertCount(10, $data['mailer']);
    }

    /**
     * Test 4 — resolved tenant WITHOUT mailerDsn → badge === 'MISSING'.
     */
    public function testBadgeIsMissingWhenResolvedTenantHasNoMailerDsn(): void
    {
        $cache = new LruTransportCache(32);
        $tenant = $this->makeTenant('lonely');
        // mailerDsn left null
        $tenant->setMailerFrom('only@from.com');
        $this->tenantContext->setTenant($tenant);

        $collector = $this->makeCollector($cache, 'false');
        $this->collect($collector);

        $data = $collector->getData();
        self::assertSame('MISSING', $data['mailer']['badge']);
        self::assertNull($data['mailer']['dsn_redacted']);
        self::assertSame('only@from.com', $data['mailer']['from']);
        self::assertSame('false', $data['mailer']['async_detected']);
    }

    /**
     * Test 5 — `async_detected` passes through the user-configured string
     * verbatim ('auto' | 'true' | 'false' | null).
     */
    public function testAsyncDetectedPassesThroughVerbatim(): void
    {
        foreach (['auto', 'true', 'false', null] as $async) {
            $cache = new LruTransportCache(32);
            $collector = $this->makeCollector($cache, $async);
            $this->collect($collector);
            $data = $collector->getData();
            self::assertSame($async, $data['mailer']['async_detected'], sprintf('async=%s should pass through unchanged', var_export($async, true)));
        }
    }

    /**
     * Test 6 — credential leak guard. With a tenant whose DSN password is
     * 'hunter2', NO value anywhere in $this->data['mailer'] (concatenated
     * via json_encode) may contain the literal 'hunter2'.
     */
    public function testNoRawPasswordEverAppearsInMailerData(): void
    {
        $cache = new LruTransportCache(32);
        $tenant = $this->makeTenant('vault');
        $tenant->setMailerDsn('smtp://admin:hunter2@mailtrap.io:25');
        $tenant->setMailerFrom('vault@example.com');
        $this->tenantContext->setTenant($tenant);

        $collector = $this->makeCollector($cache, 'auto');
        $this->collect($collector);

        $blob = json_encode($collector->getData()['mailer'], \JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('hunter2', $blob, 'Raw DSN password leaked into $this->data["mailer"]');
        self::assertStringContainsString('***', $blob, 'Redacted DSN star block missing from mailer data');
    }

    /**
     * Test 7 — every value in $this->data['mailer'] is a scalar (int /
     * string / bool / null) — same invariant as Phase 19's
     * testDataContainsOnlyScalarsAndStringArrays.
     */
    public function testMailerSubArrayContainsOnlyScalars(): void
    {
        $cache = new LruTransportCache(32);
        $cache->set('a', new StoppableSpyTransport('a'));
        $tenant = $this->makeTenant('acme');
        $tenant->setMailerDsn('smtp://u:p@h:25');
        $tenant->setMailerFrom('a@b.c');
        $tenant->setMailerReplyTo('r@b.c');
        $this->tenantContext->setTenant($tenant);

        $collector = $this->makeCollector($cache, 'auto');
        $this->collect($collector);

        foreach ($collector->getData()['mailer'] as $key => $value) {
            self::assertTrue(
                null === $value || is_scalar($value),
                sprintf('mailer[%s] must be scalar or null, got %s', $key, get_debug_type($value))
            );
        }
    }
}
