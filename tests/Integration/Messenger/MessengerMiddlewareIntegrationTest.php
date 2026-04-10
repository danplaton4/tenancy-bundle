<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Integration\Messenger;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackMiddleware;
use Tenancy\Bundle\Context\TenantContext;
use Tenancy\Bundle\Messenger\TenantSendingMiddleware;
use Tenancy\Bundle\Messenger\TenantStamp;
use Tenancy\Bundle\Messenger\TenantWorkerMiddleware;
use Tenancy\Bundle\Tests\Integration\Messenger\Support\StubTenant;
use Tenancy\Bundle\Tests\Integration\Messenger\Support\StubTenantProvider;

/**
 * End-to-end integration tests for Messenger middleware through a real Symfony kernel and bus.
 *
 * Verifies:
 *   - Both middleware services are registered in the DI container
 *   - Dispatching an envelope with an active tenant attaches TenantStamp
 *   - Dispatching with no active tenant attaches no TenantStamp
 *   - Worker middleware boots and tears down TenantContext correctly
 *   - Two sequential messages with different tenant stamps isolate context correctly
 */
final class MessengerMiddlewareIntegrationTest extends TestCase
{
    private static MessengerTestKernel $kernel;

    public static function setUpBeforeClass(): void
    {
        // Clear cached container from prior runs
        $cacheDir = sys_get_temp_dir().'/tenancy_messenger_test';
        if (is_dir($cacheDir)) {
            self::removeDir($cacheDir);
        }

        static::$kernel = new MessengerTestKernel('messenger_test', false);
        static::$kernel->boot();

        // Populate the StubTenantProvider with test tenants
        $container = static::$kernel->getContainer();
        /** @var StubTenantProvider $provider */
        $provider = $container->get('tenancy.provider');
        $provider->addTenant(new StubTenant('acme'));
        $provider->addTenant(new StubTenant('beta'));
    }

    public static function tearDownAfterClass(): void
    {
        static::$kernel->shutdown();

        $cacheDir = sys_get_temp_dir().'/tenancy_messenger_test';
        if (is_dir($cacheDir)) {
            self::removeDir($cacheDir);
        }
    }

    protected function setUp(): void
    {
        // Ensure context is clean between tests
        $container = static::$kernel->getContainer();
        /** @var TenantContext $context */
        $context = $container->get('tenancy.context');
        $context->clear();
    }

    public function testMiddlewaresAreRegisteredInContainer(): void
    {
        $container = static::$kernel->getContainer();

        $sendingMiddleware = $container->get('tenancy.messenger.sending_middleware');
        $this->assertInstanceOf(TenantSendingMiddleware::class, $sendingMiddleware);

        $workerMiddleware = $container->get('tenancy.messenger.worker_middleware');
        $this->assertInstanceOf(TenantWorkerMiddleware::class, $workerMiddleware);
    }

    public function testDispatchAttachesStampWhenTenantActive(): void
    {
        $container = static::$kernel->getContainer();

        /** @var TenantContext $context */
        $context = $container->get('tenancy.context');
        $context->setTenant(new StubTenant('acme'));

        /** @var MessageBusInterface $bus */
        $bus = $container->get('messenger.bus.default');
        $envelope = $bus->dispatch(new \stdClass());

        $stamp = $envelope->last(TenantStamp::class);
        $this->assertNotNull($stamp, 'TenantStamp must be attached when a tenant is active');
        $this->assertSame('acme', $stamp->getTenantSlug());
    }

    public function testDispatchNoStampWhenNoTenant(): void
    {
        $container = static::$kernel->getContainer();

        // Context is already clear from setUp()
        /** @var MessageBusInterface $bus */
        $bus = $container->get('messenger.bus.default');
        $envelope = $bus->dispatch(new \stdClass());

        $stamp = $envelope->last(TenantStamp::class);
        $this->assertNull($stamp, 'No TenantStamp should be attached when no tenant is active');
    }

    public function testWorkerMiddlewareBootsAndTearsDownContext(): void
    {
        $container = static::$kernel->getContainer();

        /** @var TenantContext $context */
        $context = $container->get('tenancy.context');
        /** @var TenantWorkerMiddleware $workerMiddleware */
        $workerMiddleware = $container->get('tenancy.messenger.worker_middleware');

        $envelope = new Envelope(new \stdClass(), [new TenantStamp('acme')]);

        // Use an array (mutable object via reference) to capture context state during handling
        $captured = ['slug' => null];

        $innerMiddleware = new class($context, $captured) implements MiddlewareInterface {
            public function __construct(
                private readonly TenantContext $ctx,
                private array &$captured,
            ) {
            }

            public function handle(Envelope $envelope, \Symfony\Component\Messenger\Middleware\StackInterface $stack): Envelope
            {
                $this->captured['slug'] = $this->ctx->hasTenant()
                    ? $this->ctx->getTenant()->getSlug()
                    : null;

                return $envelope;
            }
        };

        // Pass MiddlewareInterface directly (not array) — StackMiddleware stores it in stack[0] directly
        $stack = new StackMiddleware($innerMiddleware);
        $workerMiddleware->handle($envelope, $stack);

        $this->assertSame('acme', $captured['slug'], 'Tenant context must be set during message handling');
        $this->assertFalse($context->hasTenant(), 'Tenant context must be cleared after handle() completes');
    }

    public function testTwoSequentialMessagesIsolateContext(): void
    {
        $container = static::$kernel->getContainer();

        /** @var TenantContext $context */
        $context = $container->get('tenancy.context');
        /** @var TenantWorkerMiddleware $workerMiddleware */
        $workerMiddleware = $container->get('tenancy.messenger.worker_middleware');

        $capturedSlugs = [];

        $makeMiddleware = static function (TenantContext $ctx, array &$captured): MiddlewareInterface {
            return new class($ctx, $captured) implements MiddlewareInterface {
                public function __construct(
                    private readonly TenantContext $ctx,
                    private array &$captured,
                ) {
                }

                public function handle(Envelope $envelope, \Symfony\Component\Messenger\Middleware\StackInterface $stack): Envelope
                {
                    $this->captured[] = $this->ctx->hasTenant()
                        ? $this->ctx->getTenant()->getSlug()
                        : null;

                    return $envelope;
                }
            };
        };

        // First message: acme
        // Pass MiddlewareInterface directly (not array) — StackMiddleware stores it in stack[0] directly
        $envelopeAcme = new Envelope(new \stdClass(), [new TenantStamp('acme')]);
        $stackAcme = new StackMiddleware($makeMiddleware($context, $capturedSlugs));
        $workerMiddleware->handle($envelopeAcme, $stackAcme);
        $this->assertFalse($context->hasTenant(), 'Context must be cleared after first message');

        // Second message: beta
        $envelopeBeta = new Envelope(new \stdClass(), [new TenantStamp('beta')]);
        $stackBeta = new StackMiddleware($makeMiddleware($context, $capturedSlugs));
        $workerMiddleware->handle($envelopeBeta, $stackBeta);
        $this->assertFalse($context->hasTenant(), 'Context must be cleared after second message');

        $this->assertSame(['acme', 'beta'], $capturedSlugs, 'Each message must load the correct tenant');
    }

    private static function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        foreach ($items as $item) {
            if ('.' === $item || '..' === $item) {
                continue;
            }
            $path = $dir.'/'.$item;
            if (is_dir($path)) {
                self::removeDir($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }
}
