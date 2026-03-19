<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Unit\EventListener;

use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Tenancy\Bundle\Event\TenantContextCleared;
use Tenancy\Bundle\EventListener\EntityManagerResetListener;

final class EntityManagerResetListenerTest extends TestCase
{
    private ManagerRegistry&MockObject $managerRegistry;
    private EntityManagerResetListener $listener;

    protected function setUp(): void
    {
        $this->managerRegistry = $this->createMock(ManagerRegistry::class);
        $this->listener = new EntityManagerResetListener($this->managerRegistry);
    }

    public function testInvokeResetsDefaultEntityManager(): void
    {
        $this->managerRegistry
            ->expects($this->once())
            ->method('resetManager')
            ->with(null);

        ($this->listener)(new TenantContextCleared());
    }

    public function testDoesNotResetLandlordManager(): void
    {
        // Expect resetManager called exactly once with null (default EM) — never with 'landlord' or 'tenant'.
        $this->managerRegistry
            ->expects($this->once())
            ->method('resetManager')
            ->with(null);

        ($this->listener)(new TenantContextCleared());
    }

    public function testHasAsEventListenerAttribute(): void
    {
        $reflection = new ReflectionClass(EntityManagerResetListener::class);
        $attributes = $reflection->getAttributes(AsEventListener::class);

        $this->assertNotEmpty($attributes, 'EntityManagerResetListener must have #[AsEventListener] attribute');

        $attribute = $attributes[0]->newInstance();
        $this->assertSame(
            TenantContextCleared::class,
            $attribute->event,
            'AsEventListener must specify event: TenantContextCleared::class',
        );
    }
}
