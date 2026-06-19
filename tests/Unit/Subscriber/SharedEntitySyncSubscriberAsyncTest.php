<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Unit\Subscriber;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Tenancy\Bundle\Context\TenantContext;
use Tenancy\Bundle\Message\SharedEntityChangedMessage;
use Tenancy\Bundle\Provider\TenantProviderInterface;
use Tenancy\Bundle\Shared\SharedEntityCopier;
use Tenancy\Bundle\Shared\TenantEmSwitcher;
use Tenancy\Bundle\Subscriber\SharedEntitySyncSubscriber;
use Tenancy\Bundle\TenantInterface;
use Tenancy\Bundle\Tests\Integration\SharedEntity\Support\Entity\TestPlan;

/**
 * Spy on MessageBusInterface to capture dispatched messages.
 */
final class RecordingMessageBus implements MessageBusInterface
{
    /** @var list<object> */
    public array $dispatched = [];

    public function dispatch(object $message, array $stamps = []): Envelope
    {
        $this->dispatched[] = $message;

        return new Envelope($message);
    }
}

/**
 * Unit tests for the async dispatch branch of SharedEntitySyncSubscriber (SHARE-03).
 *
 * Covers:
 *  - SHARE-03-a: async enabled (bus injected) → postFlush dispatches SharedEntityChangedMessage per change,
 *    no synchronous fan-out (findAll never called)
 *  - SHARE-03-b: async disabled (bus=null) → postFlush calls the sync fan-out (findAll IS called)
 *  - D-01 stamp-clearing: active tenant context is cleared before dispatch and restored in finally
 *  - D-04/D-05: delete-type dispatch uses pre-captured ids, not getIdentifierValues()
 */
final class SharedEntitySyncSubscriberAsyncTest extends TestCase
{
    /**
     * Build a minimal SharedEntitySyncSubscriber with the given bus (null = sync mode).
     */
    private function buildSubscriber(
        TenantContext $tenantContext,
        TenantProviderInterface $provider,
        ?MessageBusInterface $bus = null,
    ): SharedEntitySyncSubscriber {
        /** @var ManagerRegistry&MockObject $registry */
        $registry = $this->createMock(ManagerRegistry::class);
        $copier = new SharedEntityCopier(new NullLogger());
        $switcher = new TenantEmSwitcher($tenantContext, $registry);

        return new SharedEntitySyncSubscriber(
            $tenantContext,
            $provider,
            $registry,
            new NullLogger(),
            'database_per_tenant',
            $copier,
            $switcher,
            $bus,
        );
    }

    /**
     * Build a minimal PostFlushEventArgs with an EM that returns $metadata for all getClassMetadata() calls.
     *
     * @param array<string, mixed> $identifierValues what getIdentifierValues() returns
     */
    private function buildPostFlushArgs(array $identifierValues): PostFlushEventArgs
    {
        /** @var ClassMetadata<object>&MockObject $metadata */
        $metadata = $this->createMock(ClassMetadata::class);
        $metadata->method('getIdentifierValues')->willReturn($identifierValues);

        /** @var EntityManagerInterface&MockObject $em */
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getClassMetadata')->willReturn($metadata);

        return new PostFlushEventArgs($em);
    }

    /**
     * SHARE-03-a: when a bus is injected, postFlush dispatches one SharedEntityChangedMessage
     * per changed entity and never calls findAll() (no sync fan-out).
     */
    public function testPostFlushDispatchesWhenAsyncEnabled(): void
    {
        $tenantContext = new TenantContext();
        $bus = new RecordingMessageBus();

        /** @var TenantProviderInterface&MockObject $provider */
        $provider = $this->createMock(TenantProviderInterface::class);
        $provider->expects($this->never())->method('findAll');

        $subscriber = $this->buildSubscriber($tenantContext, $provider, $bus);

        // Seed pendingChanges by reflecting or using onFlush. Here we use the onFlush method.
        $plan = new TestPlan('Async Plan', 1000);
        $identifierValues = ['id' => 42];

        $postFlushArgs = $this->buildPostFlushArgs($identifierValues);

        // Use reflection to seed pendingChanges directly (avoids full UoW mock complexity)
        $refl = new \ReflectionProperty(SharedEntitySyncSubscriber::class, 'pendingChanges');
        $refl->setValue($subscriber, [
            spl_object_id($plan) => ['entity' => $plan, 'type' => 'insert'],
        ]);

        $subscriber->postFlush($postFlushArgs);

        $this->assertCount(1, $bus->dispatched, 'Exactly one message should be dispatched');

        $msg = $bus->dispatched[0];
        $this->assertInstanceOf(SharedEntityChangedMessage::class, $msg);
        $this->assertSame(TestPlan::class, $msg->entityClass);
        $this->assertSame('insert', $msg->changeType);
        $this->assertSame($identifierValues, $msg->identifier);
    }

    /**
     * SHARE-03-b: when bus=null, postFlush calls the sync fan-out (findAll IS called).
     */
    public function testPostFlushUsesSyncFanOutWhenAsyncDisabled(): void
    {
        $tenantContext = new TenantContext();

        /** @var TenantProviderInterface&MockObject $provider */
        $provider = $this->createMock(TenantProviderInterface::class);
        // findAll is called to materialize tenants for the sync loop — must be called
        $provider->expects($this->once())->method('findAll')->willReturn([]);

        $subscriber = $this->buildSubscriber($tenantContext, $provider, null);

        $plan = new TestPlan('Sync Plan', 500);

        $postFlushArgs = $this->buildPostFlushArgs(['id' => 7]);

        $refl = new \ReflectionProperty(SharedEntitySyncSubscriber::class, 'pendingChanges');
        $refl->setValue($subscriber, [
            spl_object_id($plan) => ['entity' => $plan, 'type' => 'update'],
        ]);

        $subscriber->postFlush($postFlushArgs);
    }

    /**
     * Pitfall 1 / D-01: when a tenant is active at postFlush time and async bus is present,
     * TenantContext::clear() is called before dispatch and the previous tenant is restored in a finally.
     */
    public function testDispatchClearsTenantContextToAvoidStamp(): void
    {
        $tenantContext = new TenantContext();

        // Set an active tenant
        /** @var TenantInterface&MockObject $tenant */
        $tenant = $this->createMock(TenantInterface::class);
        $tenant->method('getSlug')->willReturn('acme');
        $tenantContext->setTenant($tenant);

        // Use a recording bus that checks tenant context state at dispatch time
        $bus = new class($tenantContext) implements MessageBusInterface {
            public bool $contextClearedAtDispatch = false;

            public function __construct(
                private readonly TenantContext $ctx,
            ) {
            }

            public function dispatch(object $message, array $stamps = []): Envelope
            {
                // At dispatch time, the tenant context MUST be cleared (no active tenant)
                $this->contextClearedAtDispatch = !$this->ctx->hasTenant();

                return new Envelope($message);
            }
        };

        /** @var TenantProviderInterface&MockObject $provider */
        $provider = $this->createMock(TenantProviderInterface::class);
        $provider->method('findAll')->willReturn([]);

        $subscriber = $this->buildSubscriber($tenantContext, $provider, $bus);

        $plan = new TestPlan('Stamp Test Plan', 200);
        $postFlushArgs = $this->buildPostFlushArgs(['id' => 1]);

        $refl = new \ReflectionProperty(SharedEntitySyncSubscriber::class, 'pendingChanges');
        $refl->setValue($subscriber, [
            spl_object_id($plan) => ['entity' => $plan, 'type' => 'insert'],
        ]);

        $subscriber->postFlush($postFlushArgs);

        // At dispatch time, context must have been cleared (Pitfall 1 / D-01)
        $this->assertTrue(
            $bus->contextClearedAtDispatch,
            'TenantContext must be cleared before dispatch so TenantSendingMiddleware does not stamp the message',
        );

        // After postFlush, the previous tenant must be restored (finally block)
        $this->assertTrue(
            $tenantContext->hasTenant(),
            'Previous tenant must be restored after postFlush completes',
        );
        $this->assertSame(
            $tenant,
            $tenantContext->getTenant(),
            'Restored tenant must be the same object that was active before postFlush',
        );
    }

    /**
     * D-04/D-05: delete-type dispatch uses pre-captured ids ($change['ids']), NOT getIdentifierValues().
     *
     * Doctrine zeroes entity identifier fields before postFlush on deletes, so getIdentifierValues()
     * returns [] by postFlush time. The async branch must use the pre-captured ids instead.
     */
    public function testDeleteDispatchUsesPreCapturedIds(): void
    {
        $tenantContext = new TenantContext();
        $bus = new RecordingMessageBus();

        /** @var TenantProviderInterface&MockObject $provider */
        $provider = $this->createMock(TenantProviderInterface::class);
        $provider->expects($this->never())->method('findAll');

        $subscriber = $this->buildSubscriber($tenantContext, $provider, $bus);

        $plan = new TestPlan('Deleted Plan', 100);
        // Pre-captured ids (set during onFlush before Doctrine zeroed the entity ID)
        $capturedIds = ['id' => 99];

        // getIdentifierValues() returns [] for deletes by postFlush time
        $postFlushArgs = $this->buildPostFlushArgs([]);

        $refl = new \ReflectionProperty(SharedEntitySyncSubscriber::class, 'pendingChanges');
        $refl->setValue($subscriber, [
            spl_object_id($plan) => ['entity' => $plan, 'type' => 'delete', 'ids' => $capturedIds],
        ]);

        $subscriber->postFlush($postFlushArgs);

        $this->assertCount(1, $bus->dispatched);

        /** @var SharedEntityChangedMessage $msg */
        $msg = $bus->dispatched[0];
        $this->assertSame('delete', $msg->changeType);
        // CRITICAL: must use pre-captured ids, NOT the zeroed getIdentifierValues() result
        $this->assertSame($capturedIds, $msg->identifier, 'Delete dispatch must use pre-captured ids from onFlush');
        $this->assertNotSame([], $msg->identifier, 'Must not use Doctrine-zeroed empty identifier');
    }
}
