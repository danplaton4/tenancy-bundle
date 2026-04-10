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

    public function testInvokeResetsAllEntityManagers(): void
    {
        $this->managerRegistry
            ->method('getManagerNames')
            ->willReturn(['default' => 'doctrine.orm.default_entity_manager']);

        $this->managerRegistry
            ->expects($this->once())
            ->method('resetManager')
            ->with('default');

        ($this->listener)(new TenantContextCleared());
    }

    public function testInvokeResetsMultipleEntityManagers(): void
    {
        $this->managerRegistry
            ->method('getManagerNames')
            ->willReturn([
                'landlord' => 'doctrine.orm.landlord_entity_manager',
                'tenant' => 'doctrine.orm.tenant_entity_manager',
            ]);

        $calls = [];
        $this->managerRegistry
            ->expects($this->exactly(2))
            ->method('resetManager')
            ->willReturnCallback(function (string $name) use (&$calls) {
                $calls[] = $name;
                return $this->createMock(\Doctrine\Persistence\ObjectManager::class);
            });

        ($this->listener)(new TenantContextCleared());

        $this->assertSame(['landlord', 'tenant'], $calls);
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
