<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Integration\Profiler;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Tenancy\Bundle\Context\TenantContext;
use Tenancy\Bundle\Event\TenantBootstrapped;
use Tenancy\Bundle\Event\TenantResolved;
use Tenancy\Bundle\Exception\TenantNotFoundException;
use Tenancy\Bundle\Profiler\TenantDataCollector;
use Tenancy\Bundle\Profiler\TenantProfilerStash;
use Tenancy\Bundle\TenantInterface;
use Tenancy\Bundle\Tests\Integration\Profiler\Support\ProfilerTestKernel;

/**
 * End-to-end functional test for the Tenancy Profiler tab.
 *
 * Boots a real Symfony kernel with FrameworkBundle + TwigBundle + WebProfilerBundle +
 * TenancyBundle (debug=true, profiler enabled) and exercises the full pipeline:
 *
 *   dispatcher.dispatch(TenantResolved)        →   stash captures resolver FQCN
 *   dispatcher.dispatch(TenantBootstrapped)    →   stash captures bootstrappers
 *   dispatcher.dispatch(ExceptionEvent)        →   stash captures tenancy exception
 *   TenantContext::setTenant()                 →   collector reads getTenant()
 *   collector.collect(Request, Response)       →   produces the 8-key data array
 *   twig.render(@Tenancy/Collector/...)        →   panel/toolbar blocks render HTML
 *
 * For all 3 render states (resolved / null / error) we assert both:
 *   (1) The 8-key data shape exposed by TenantDataCollector::getData()
 *   (2) Substrings present in the rendered Twig output (toolbar badge + panel body)
 *
 * Notes on the rendering strategy:
 *   - The collector template extends @WebProfiler/Profiler/layout.html.twig, but rendering
 *     that layout end-to-end requires a fully wired profiler context (router, the `kernel`
 *     service inside the profiler css template, etc.) which is overkill for a unit-level
 *     verification of the panel contract. Instead, we render the individual blocks
 *     ('toolbar', 'panel', 'menu') directly via TemplateWrapper::renderBlock() — this is
 *     the same code path WebProfilerBundle uses internally when assembling the toolbar/page.
 *   - Variables required by the toolbar's include of @WebProfiler/Profiler/toolbar_item.html.twig
 *     (name, token, link) are supplied as block-render context.
 *
 * Phase 19 — DX-02 acceptance: WDT badge + panel render correctly for all 3 states.
 */
final class TenantDataCollectorWdtTest extends TestCase
{
    private static ProfilerTestKernel $kernel;

    public static function setUpBeforeClass(): void
    {
        self::$kernel = new ProfilerTestKernel('test', true);
        self::$kernel->boot();
    }

    public static function tearDownAfterClass(): void
    {
        self::$kernel->shutdown();
    }

    protected function setUp(): void
    {
        // Reset stash + TenantContext between tests so each method starts from a clean slate
        // (long-running runtime semantics — kernel.reset on the stash).
        $container = $this->container();

        /** @var TenantProfilerStash $stash */
        $stash = $container->get(TenantProfilerStash::class);
        $stash->reset();

        /** @var TenantContext $tenantContext */
        $tenantContext = $container->get('tenancy.context');
        $tenantContext->clear();
    }

    public function testProfilerServiceGraphIsRegisteredInDebugKernel(): void
    {
        $container = $this->container();

        self::assertTrue($container->has(TenantDataCollector::class), 'TenantDataCollector must be registered in debug kernel');
        self::assertTrue($container->has(TenantProfilerStash::class), 'TenantProfilerStash must be registered in debug kernel');
        self::assertTrue($container->has('profiler'), 'Symfony Profiler service must be registered (proves WebProfilerBundle wiring)');
        self::assertTrue($container->has('twig'), 'Twig must be registered (required for template rendering)');
        self::assertTrue($container->has('event_dispatcher'), 'Event dispatcher must be accessible (required to drive stash listeners)');
        self::assertTrue($container->has('tenancy.context'), 'TenantContext alias must be registered');

        /** @var TenantDataCollector $collector */
        $collector = $container->get(TenantDataCollector::class);
        self::assertSame('tenancy', $collector->getName(), 'Collector name must be "tenancy" to match data_collector tag id');
        self::assertSame('@Tenancy/Collector/tenant.html.twig', TenantDataCollector::getTemplate(), 'Template path must match data_collector tag template attribute');
    }

    public function testResolvedTenantStateProducesCorrectDataAndRendersSlug(): void
    {
        $container = $this->container();

        /** @var TenantContext $tenantContext */
        $tenantContext = $container->get('tenancy.context');
        /** @var EventDispatcherInterface $dispatcher */
        $dispatcher = $container->get('event_dispatcher');
        /** @var TenantDataCollector $collector */
        $collector = $container->get(TenantDataCollector::class);

        $tenant = $this->makeTenantMock('acme', 'Acme Corp');
        $tenantContext->setTenant($tenant);

        // Dispatch events so the stash captures resolver FQCN + bootstrapper FQCN list.
        // The stash is registered as a listener via #[AsEventListener] attributes on Plan 01 — these
        // dispatches go through the live event dispatcher exactly as in production.
        $dispatcher->dispatch(new TenantResolved($tenant, Request::create('/'), 'Tenancy\\Bundle\\Resolver\\HostResolver'));
        $dispatcher->dispatch(new TenantBootstrapped($tenant, [
            'Tenancy\\Bundle\\Bootstrapper\\Foo',
            'Tenancy\\Bundle\\Bootstrapper\\Bar',
        ]));

        $collector->collect(Request::create('/'), new Response());
        $data = $collector->getData();

        // 8-key data shape assertions
        self::assertSame('resolved', $data['state']);
        self::assertSame('acme', $data['slug']);
        self::assertSame('Acme Corp', $data['tenant_label']);
        self::assertSame('Tenancy\\Bundle\\Resolver\\HostResolver', $data['resolved_by']);
        self::assertSame(['Tenancy\\Bundle\\Bootstrapper\\Foo', 'Tenancy\\Bundle\\Bootstrapper\\Bar'], $data['bootstrappers']);
        self::assertNull($data['error']);
        // driver defaults to 'database_per_tenant' (TenancyBundle config default) → connection_name='tenant'
        self::assertSame('database_per_tenant', $data['driver']);
        self::assertSame('tenant', $data['connection_name']);
        // D-09 invariant: connection_name is a label, never a DSN.
        self::assertStringNotContainsString(':', $data['connection_name']);
        self::assertStringNotContainsString('@', $data['connection_name']);

        // Rendered HTML assertions (panel + toolbar blocks)
        $html = $this->renderPanelAndToolbar($collector);

        self::assertStringContainsString('acme', $html, 'Rendered output must show tenant slug');
        self::assertStringContainsString('Acme Corp', $html, 'Rendered panel must show tenant label');
        self::assertStringContainsString('HostResolver', $html, 'Rendered output must show resolver class (basename or FQCN)');
        self::assertStringContainsString('Tenancy\\Bundle\\Bootstrapper\\Foo', $html, 'Rendered panel must list first bootstrapper FQCN');
        self::assertStringContainsString('Tenancy\\Bundle\\Bootstrapper\\Bar', $html, 'Rendered panel must list second bootstrapper FQCN');
        self::assertStringContainsString('Bootstrappers (2)', $html, 'Rendered panel must show bootstrapper count');
        self::assertStringContainsString('sf-toolbar-status-green', $html, 'Resolved-state toolbar must be green');
    }

    public function testNullResolutionStateRendersEmDashBadgeAndNoTenantPanelCopy(): void
    {
        $container = $this->container();
        /** @var TenantDataCollector $collector */
        $collector = $container->get(TenantDataCollector::class);

        // No tenant set, no events dispatched — collector falls through to state='null'.
        $collector->collect(Request::create('/'), new Response());
        $data = $collector->getData();

        // 8-key data shape assertions
        self::assertSame('null', $data['state']);
        self::assertNull($data['slug']);
        self::assertNull($data['tenant_label']);
        self::assertNull($data['resolved_by']);
        self::assertSame([], $data['bootstrappers']);
        self::assertNull($data['error']);

        // Rendered HTML assertions
        $html = $this->renderPanelAndToolbar($collector);
        self::assertStringContainsString('—', $html, 'Null-state toolbar badge must contain em-dash (U+2014)');
        self::assertStringContainsString('No tenant resolved', $html, 'Null-state panel must explain the state');
        self::assertStringContainsString('sf-toolbar-status-yellow', $html, 'Null-state toolbar must be yellow');
    }

    public function testErrorStateRendersWarningGlyphBadgeAndEscapedExceptionMessage(): void
    {
        $container = $this->container();
        /** @var EventDispatcherInterface $dispatcher */
        $dispatcher = $container->get('event_dispatcher');
        /** @var TenantDataCollector $collector */
        $collector = $container->get(TenantDataCollector::class);

        // Dispatch a kernel.exception event carrying a tenancy exception — the stash captures it.
        $event = new ExceptionEvent(
            self::$kernel,
            Request::create('/'),
            HttpKernelInterface::MAIN_REQUEST,
            new TenantNotFoundException('tenant "ghost" not found'),
        );
        $dispatcher->dispatch($event, KernelEvents::EXCEPTION);

        $collector->collect(Request::create('/'), new Response());
        $data = $collector->getData();

        // 8-key data shape assertions
        self::assertSame('error', $data['state']);
        self::assertIsArray($data['error']);
        self::assertSame(TenantNotFoundException::class, $data['error']['class']);
        self::assertSame('tenant "ghost" not found', $data['error']['message']);

        // Rendered HTML assertions
        $html = $this->renderPanelAndToolbar($collector);
        self::assertStringContainsString('⚠', $html, 'Error-state toolbar badge must contain warning glyph (U+26A0)');
        self::assertStringContainsString(TenantNotFoundException::class, $html, 'Error-state panel must display exception class FQCN');
        // T-19-08 mitigation: the user-controlled exception message is HTML-escaped by Twig auto-escape.
        self::assertStringContainsString('tenant &quot;ghost&quot; not found', $html, 'Exception message must be HTML-escaped in rendered output (XSS mitigation)');
        self::assertStringContainsString('sf-toolbar-status-red', $html, 'Error-state toolbar must be red');
    }

    private function makeTenantMock(string $slug, string $name): TenantInterface
    {
        /** @var TenantInterface&MockObject $tenant */
        $tenant = $this->createMock(TenantInterface::class);
        $tenant->method('getSlug')->willReturn($slug);
        $tenant->method('getName')->willReturn($name);

        return $tenant;
    }

    /**
     * Returns the test-mode container that exposes private services
     * (framework.test=true wires up test.service_container alias).
     */
    private function container(): ContainerInterface
    {
        $container = self::$kernel->getContainer();
        if ($container->has('test.service_container')) {
            /** @var ContainerInterface $testContainer */
            $testContainer = $container->get('test.service_container');

            return $testContainer;
        }

        return $container;
    }

    /**
     * Renders both the `panel` and `toolbar` blocks of the Tenancy collector template
     * and returns the concatenated HTML for substring-based assertions.
     *
     * Renders blocks directly (TemplateWrapper::renderBlock()) rather than the full
     * template — the parent @WebProfiler/Profiler/layout.html.twig pulls in profiler.css.twig
     * which requires a fully wired profiler runtime context. Block-level rendering covers
     * the contract surface this test needs: toolbar badge value/color and panel body content.
     *
     * - `profiler_url` is set to `false` so the toolbar item template does not emit an `<a>`
     *   wrapper (avoids url('_profiler', ...) which requires a registered profiler route).
     * - `name` and `token` are passed because @WebProfiler/Profiler/toolbar_item.html.twig
     *   reads them from the render context.
     */
    private function renderPanelAndToolbar(TenantDataCollector $collector): string
    {
        $container = $this->container();
        /** @var \Twig\Environment $twig */
        $twig = $container->get('twig');

        $template = $twig->load('@Tenancy/Collector/tenant.html.twig');

        $context = [
            'collector' => $collector,
            'profiler_url' => false,
            'token' => 'test-token',
            'name' => 'tenancy',
        ];

        return $template->renderBlock('toolbar', $context).$template->renderBlock('panel', $context);
    }
}
