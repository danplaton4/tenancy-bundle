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
use Tenancy\Bundle\Tests\Integration\Messenger\Support\StubTenant;
use Tenancy\Bundle\Tests\Unit\Mailer\Fixture\StoppableSpyTransport;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Loader\ChainLoader;
use Twig\Loader\FilesystemLoader;

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

    private function makeTenant(string $slug = 'acme'): StubTenant
    {
        return new StubTenant($slug);
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
        self::assertIsArray($data['mailer']);
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
        self::assertIsArray($data['mailer']);
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
            self::assertIsArray($data['mailer']);
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

        $mailer = $collector->getData()['mailer'];
        self::assertIsArray($mailer);
        foreach ($mailer as $key => $value) {
            self::assertTrue(
                null === $value || is_scalar($value),
                sprintf('mailer[%s] must be scalar or null, got %s', $key, get_debug_type($value))
            );
        }
    }

    /**
     * Phase 23 INT-01 — Test 8: rendered-HTML assertion for the null state.
     *
     * Proves that with NO tenant resolved but the LruTransportCache wired, the
     * Twig template renders the `<h3>Mailer</h3>` subsection along with the
     * "Transport cache" label and the `x_transport` strategy string.
     *
     * Before the Phase 23 hoist, the mailer block lived inside
     * `{% if collector.data.state == 'resolved' %}` and this assertion would
     * have failed — the block was suppressed on the null state.
     */
    public function testMailerBlockRendersWhenNoTenantButCacheWired(): void
    {
        if (!class_exists(Environment::class)) {
            self::markTestSkipped('twig/twig not installed — rendered-HTML assertion requires Twig.');
        }

        $cache = new LruTransportCache(32);
        $collector = $this->makeCollector($cache, 'auto');
        $this->collect($collector);

        self::assertSame('null', $collector->getData()['state'], 'Pre-condition: state must be null for this test');

        $html = $this->renderPanelBlock($collector);

        self::assertStringContainsString('<h3>Mailer</h3>', $html, 'Null-state panel must render the Mailer heading when cache is wired');
        self::assertStringContainsString('Transport cache', $html, 'Null-state panel must render the Transport cache metric label');
        self::assertStringContainsString('x_transport', $html, 'Null-state panel must render the x_transport strategy value');
        // The empty-panel copy from the null branch must STILL render — the hoist preserves the state branching.
        self::assertStringContainsString('No tenant resolved', $html, 'Null-state empty-panel copy must coexist with the mailer subsection');
    }

    /**
     * Phase 23 INT-01 — Test 9: rendered-HTML assertion for the error state.
     *
     * The TenantProfilerStash::onKernelException() listener is the only public
     * route to set `capturedException`; from a unit test we don't have a
     * kernel.exception event so we mutate `$collector->data['state'] = 'error'`
     * via Reflection AFTER the collect() call. The collector's state-machine is
     * already covered by Phase 19's TenantDataCollectorTest; this test's goal is
     * to exercise the Twig render path for the error state, not the collector's
     * state derivation.
     */
    public function testMailerBlockRendersOnErrorStateWithCacheWired(): void
    {
        if (!class_exists(Environment::class)) {
            self::markTestSkipped('twig/twig not installed — rendered-HTML assertion requires Twig.');
        }

        $cache = new LruTransportCache(32);
        $collector = $this->makeCollector($cache, 'auto');
        $this->collect($collector);

        // Force state=='error' with a synthetic error payload — see method docblock.
        $this->forceState($collector, 'error', [
            'class' => 'Tenancy\\Bundle\\Exception\\TenantNotFoundException',
            'message' => 'tenant "ghost" not found',
        ]);

        $html = $this->renderPanelBlock($collector);

        self::assertStringContainsString('<h3>Mailer</h3>', $html, 'Error-state panel must render the Mailer heading when cache is wired');
        self::assertStringContainsString('Transport cache', $html, 'Error-state panel must render the Transport cache metric label');
        self::assertStringContainsString('x_transport', $html, 'Error-state panel must render the x_transport strategy value');
        // The error-branch markup must STILL render — the hoist preserves the state branching.
        self::assertStringContainsString('Resolution error', $html, 'Error-state heading must coexist with the mailer subsection');
        self::assertStringContainsString('TenantNotFoundException', $html, 'Error-state exception class must coexist with the mailer subsection');
    }

    /**
     * Phase 23 INT-01 — Test 10: rendered-HTML regression guard for the resolved
     * state. After the hoist, the existing resolved-state markup (slug, tenant
     * label, bootstrappers heading) MUST still render alongside the mailer block.
     */
    public function testMailerBlockRendersOnResolvedStateWithCacheWired(): void
    {
        if (!class_exists(Environment::class)) {
            self::markTestSkipped('twig/twig not installed — rendered-HTML assertion requires Twig.');
        }

        $cache = new LruTransportCache(32);
        $tenant = $this->makeTenant('acme');
        $tenant->setMailerDsn('smtp://u:p@h:25');
        $tenant->setMailerFrom('a@b.c');
        $this->tenantContext->setTenant($tenant);

        $collector = $this->makeCollector($cache, 'auto');
        $this->collect($collector);

        self::assertSame('resolved', $collector->getData()['state'], 'Pre-condition: state must be resolved for this test');

        $html = $this->renderPanelBlock($collector);

        // Mailer block must still render (regression guard for Task 1).
        self::assertStringContainsString('<h3>Mailer</h3>', $html, 'Resolved-state panel must continue to render the Mailer heading (regression guard)');
        self::assertStringContainsString('Transport cache', $html, 'Resolved-state panel must continue to render the Transport cache metric');
        self::assertStringContainsString('x_transport', $html, 'Resolved-state panel must continue to render the strategy value');
        // Resolved-state identity markup must still render — the hoist did not break the resolved branch.
        self::assertStringContainsString('acme', $html, 'Resolved-state panel must continue to render the tenant slug');
        self::assertStringContainsString('Bootstrappers', $html, 'Resolved-state panel must continue to render the Bootstrappers heading');
    }

    /**
     * Build a minimal Twig Environment and render only the `panel` block of
     * `@Tenancy/Collector/tenant.html.twig`.
     *
     * The collector template extends `@WebProfiler/Profiler/layout.html.twig`,
     * which is not available in this minimal env — we stub it via an in-memory
     * ArrayLoader template that declares empty `toolbar`, `menu`, and `panel`
     * blocks (so the child's `extends` directive resolves). `renderBlock('panel')`
     * then evaluates only the child's `panel` block, which is exactly the
     * surface this test asserts on.
     */
    private function renderPanelBlock(TenantDataCollector $collector): string
    {
        $bundleViewsPath = realpath(__DIR__.'/../../../src/Resources/views');
        self::assertNotFalse($bundleViewsPath, 'Bundle views directory must exist under src/Resources/views/');

        $filesystemLoader = new FilesystemLoader();
        $filesystemLoader->addPath($bundleViewsPath, 'Tenancy');

        $arrayLoader = new ArrayLoader([
            '@WebProfiler/Profiler/layout.html.twig' => "{% block toolbar %}{% endblock %}\n{% block menu %}{% endblock %}\n{% block panel %}{% endblock %}\n",
            '@WebProfiler/Profiler/toolbar_item.html.twig' => '',
        ]);

        $twig = new Environment(new ChainLoader([$filesystemLoader, $arrayLoader]), [
            'strict_variables' => false,
            'autoescape' => 'html',
            'cache' => false,
        ]);

        $template = $twig->load('@Tenancy/Collector/tenant.html.twig');

        return $template->renderBlock('panel', [
            'collector' => $collector,
            'profiler_url' => false,
            'token' => 'test-token',
            'name' => 'tenancy',
        ]);
    }

    /**
     * Mutate `$collector->data['state']` (and optionally `$collector->data['error']`)
     * via Reflection. Used by the error-state rendered-HTML test to side-step the
     * stash's event-listener-only public API — the collector state-machine itself
     * is already covered by Phase 19's TenantDataCollectorTest.
     *
     * @param array{class: string, message: string}|null $error
     */
    private function forceState(TenantDataCollector $collector, string $state, ?array $error = null): void
    {
        $refClass = new \ReflectionClass($collector);
        // AbstractDataCollector declares the `$data` property, so target the parent class for the property handle.
        $parent = $refClass->getParentClass();
        self::assertNotFalse($parent, 'TenantDataCollector must extend AbstractDataCollector');
        $dataProp = $parent->getProperty('data');

        /** @var array<string, mixed> $current */
        $current = $dataProp->getValue($collector);
        $current['state'] = $state;
        if (null !== $error) {
            $current['error'] = $error;
        }
        $dataProp->setValue($collector, $current);
    }
}
