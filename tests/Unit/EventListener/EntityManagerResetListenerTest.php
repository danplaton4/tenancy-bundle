<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Unit\EventListener;

use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
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

    public function testInvokeResetsAllEntityManagers(): void
    {
        // Default constructor: resets default EM (null)
        $this->managerRegistry
            ->expects($this->once())
            ->method('resetManager')
            ->with(null);

        // getManagerNames should NOT be called (we iterate managersToReset, not all managers)
        $this->managerRegistry
            ->expects($this->never())
            ->method('getManagerNames');

        ($this->listener)(new TenantContextCleared());
    }

    public function testInvokeResetsOnlyConfiguredManagers(): void
    {
        // Simulate database_per_tenant mode: only 'tenant' EM should be reset
        $listener = new EntityManagerResetListener($this->managerRegistry, ['tenant']);

        $this->managerRegistry
            ->expects($this->once())
            ->method('resetManager')
            ->with('tenant')
            ->willReturn($this->createMock(\Doctrine\Persistence\ObjectManager::class));

        $listener(new TenantContextCleared());
    }

    public function testInvokeResetsDefaultEmWhenNoManagersConfigured(): void
    {
        // Default: managersToReset = [null] -> resetManager(null) = default EM
        $listener = new EntityManagerResetListener($this->managerRegistry);

        $this->managerRegistry
            ->expects($this->once())
            ->method('resetManager')
            ->with(null)
            ->willReturn($this->createMock(\Doctrine\Persistence\ObjectManager::class));

        $listener(new TenantContextCleared());
    }

    public function testHasAsEventListenerAttribute(): void
    {
        $reflection = new \ReflectionClass(EntityManagerResetListener::class);
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
