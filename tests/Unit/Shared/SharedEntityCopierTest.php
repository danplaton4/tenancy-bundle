<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Unit\Shared;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Tenancy\Bundle\Attribute\Shared;
use Tenancy\Bundle\Shared\SharedEntityCopier;

/**
 * Unit tests for SharedEntityCopier (SHARE-02-a + classify correctness).
 */
final class SharedEntityCopierTest extends TestCase
{
    private SharedEntityCopier $copier;

    protected function setUp(): void
    {
        $this->copier = new SharedEntityCopier(new NullLogger());
    }

    /**
     * SHARE-02-a: #[Shared] classes enumerated via landlord metadata (D-07, reflClass proxy-safe).
     */
    public function testEnumeratesSharedClassesViaLandlordMetadata(): void
    {
        // Build two ClassMetadata mocks: one with #[Shared], one without
        $sharedRefl = new \ReflectionClass(SharedTestEntity::class);
        $nonSharedRefl = new \ReflectionClass(NonSharedTestEntity::class);

        /** @var ClassMetadata&MockObject $sharedMeta */
        $sharedMeta = $this->createMock(ClassMetadata::class);
        $sharedMeta->reflClass = $sharedRefl;
        $sharedMeta->method('getName')->willReturn(SharedTestEntity::class);

        /** @var ClassMetadata&MockObject $nonSharedMeta */
        $nonSharedMeta = $this->createMock(ClassMetadata::class);
        $nonSharedMeta->reflClass = $nonSharedRefl;
        $nonSharedMeta->method('getName')->willReturn(NonSharedTestEntity::class);

        $metadataFactory = $this->createMock(\Doctrine\ORM\Mapping\ClassMetadataFactory::class);
        $metadataFactory->method('getAllMetadata')->willReturn([$sharedMeta, $nonSharedMeta]);

        /** @var EntityManagerInterface&MockObject $landlordEm */
        $landlordEm = $this->createMock(EntityManagerInterface::class);
        $landlordEm->method('getMetadataFactory')->willReturn($metadataFactory);

        $sharedClasses = $this->copier->findSharedClasses($landlordEm);

        $this->assertSame([SharedTestEntity::class], $sharedClasses);
    }

    /**
     * classifyRow() returns 'insert' when the tenant copy does not exist (find() returns null).
     */
    public function testClassifyRowReturnsInsertWhenAbsent(): void
    {
        $entity = new NonSharedTestEntity();

        /** @var ClassMetadata&MockObject $landlordMeta */
        $landlordMeta = $this->createMock(ClassMetadata::class);
        $landlordMeta->method('getIdentifierValues')->with($entity)->willReturn(['id' => 42]);

        /** @var EntityManagerInterface&MockObject $landlordEm */
        $landlordEm = $this->createMock(EntityManagerInterface::class);
        $landlordEm->method('getClassMetadata')->with($entity::class)->willReturn($landlordMeta);

        /** @var EntityManagerInterface&MockObject $tenantEm */
        $tenantEm = $this->createMock(EntityManagerInterface::class);
        // find() returns null — tenant copy does not exist
        $tenantEm->method('find')->with($entity::class, ['id' => 42])->willReturn(null);
        // Crucial: no persist or flush on classify-only
        $tenantEm->expects($this->never())->method('persist');
        $tenantEm->expects($this->never())->method('flush');

        $result = $this->copier->classifyRow($landlordEm, $tenantEm, $entity);

        $this->assertSame('insert', $result);
    }

    /**
     * classifyRow() returns 'update' when a scalar field differs between landlord and tenant copy.
     */
    public function testClassifyRowReturnsUpdateOnScalarDrift(): void
    {
        $landlordEntity = new NonSharedTestEntity();
        $tenantCopy = new NonSharedTestEntity();

        /** @var ClassMetadata&MockObject $landlordMeta */
        $landlordMeta = $this->createMock(ClassMetadata::class);
        $landlordMeta->method('getIdentifierValues')->with($landlordEntity)->willReturn(['id' => 1]);
        $landlordMeta->method('getFieldNames')->willReturn(['id', 'name']);
        // landlord has name = 'NewName', tenant has name = 'OldName'
        $landlordMeta->method('getFieldValue')->willReturnCallback(
            static function (object $entity, string $field): mixed {
                return match ($field) {
                    'id' => 1,
                    'name' => 'NewName',
                    default => null,
                };
            }
        );

        /** @var ClassMetadata&MockObject $tenantMeta */
        $tenantMeta = $this->createMock(ClassMetadata::class);
        $tenantMeta->method('getFieldValue')->willReturnCallback(
            static function (object $entity, string $field): mixed {
                return match ($field) {
                    'id' => 1,
                    'name' => 'OldName',
                    default => null,
                };
            }
        );

        /** @var EntityManagerInterface&MockObject $landlordEm */
        $landlordEm = $this->createMock(EntityManagerInterface::class);
        $landlordEm->method('getClassMetadata')->with($landlordEntity::class)->willReturn($landlordMeta);

        /** @var EntityManagerInterface&MockObject $tenantEm */
        $tenantEm = $this->createMock(EntityManagerInterface::class);
        $tenantEm->method('find')->with($landlordEntity::class, ['id' => 1])->willReturn($tenantCopy);
        $tenantEm->method('getClassMetadata')->with($landlordEntity::class)->willReturn($tenantMeta);
        $tenantEm->expects($this->never())->method('persist');
        $tenantEm->expects($this->never())->method('flush');

        $result = $this->copier->classifyRow($landlordEm, $tenantEm, $landlordEntity);

        $this->assertSame('update', $result);
    }

    /**
     * classifyRow() returns 'in-sync' when all scalar fields match.
     */
    public function testClassifyRowReturnsInSyncWhenIdentical(): void
    {
        $landlordEntity = new NonSharedTestEntity();
        $tenantCopy = new NonSharedTestEntity();

        /** @var ClassMetadata&MockObject $landlordMeta */
        $landlordMeta = $this->createMock(ClassMetadata::class);
        $landlordMeta->method('getIdentifierValues')->with($landlordEntity)->willReturn(['id' => 5]);
        $landlordMeta->method('getFieldNames')->willReturn(['id', 'name']);
        $landlordMeta->method('getFieldValue')->willReturnCallback(
            static function (object $entity, string $field): mixed {
                return match ($field) {
                    'id' => 5,
                    'name' => 'SameName',
                    default => null,
                };
            }
        );

        /** @var ClassMetadata&MockObject $tenantMeta */
        $tenantMeta = $this->createMock(ClassMetadata::class);
        $tenantMeta->method('getFieldValue')->willReturnCallback(
            static function (object $entity, string $field): mixed {
                return match ($field) {
                    'id' => 5,
                    'name' => 'SameName',
                    default => null,
                };
            }
        );

        /** @var EntityManagerInterface&MockObject $landlordEm */
        $landlordEm = $this->createMock(EntityManagerInterface::class);
        $landlordEm->method('getClassMetadata')->with($landlordEntity::class)->willReturn($landlordMeta);

        /** @var EntityManagerInterface&MockObject $tenantEm */
        $tenantEm = $this->createMock(EntityManagerInterface::class);
        $tenantEm->method('find')->with($landlordEntity::class, ['id' => 5])->willReturn($tenantCopy);
        $tenantEm->method('getClassMetadata')->with($landlordEntity::class)->willReturn($tenantMeta);
        $tenantEm->expects($this->never())->method('persist');
        $tenantEm->expects($this->never())->method('flush');

        $result = $this->copier->classifyRow($landlordEm, $tenantEm, $landlordEntity);

        $this->assertSame('in-sync', $result);
    }

    /**
     * applyRow() sets syncInProgress=true before flush and false after (re-entrancy guard).
     */
    public function testApplyRowSetsAndResetsSyncInProgressAroundFlush(): void
    {
        $entity = new NonSharedTestEntity();

        /** @var ClassMetadata&MockObject $landlordMeta */
        $landlordMeta = $this->createMock(ClassMetadata::class);
        $landlordMeta->method('getIdentifierValues')->with($entity)->willReturn(['id' => 7]);
        $landlordMeta->method('getFieldNames')->willReturn(['id', 'name']);
        $landlordMeta->method('getFieldValue')->willReturn('value');

        /** @var ClassMetadata&MockObject $tenantMeta */
        $tenantMeta = $this->createMock(ClassMetadata::class);
        $tenantMeta->method('newInstance')->willReturn(new NonSharedTestEntity());
        $tenantMeta->method('isIdGeneratorIdentity')->willReturn(false);
        $tenantMeta->method('setFieldValue')->willReturnSelf();

        /** @var EntityManagerInterface&MockObject $landlordEm */
        $landlordEm = $this->createMock(EntityManagerInterface::class);
        $landlordEm->method('getClassMetadata')->with($entity::class)->willReturn($landlordMeta);

        $copier = $this->copier;
        $flagDuringFlush = false;

        /** @var EntityManagerInterface&MockObject $tenantEm */
        $tenantEm = $this->createMock(EntityManagerInterface::class);
        $tenantEm->method('find')->with($entity::class, ['id' => 7])->willReturn(null);
        $tenantEm->method('getClassMetadata')->with($entity::class)->willReturn($tenantMeta);
        $tenantEm->method('persist')->willReturnSelf();
        // Capture flag state during flush
        $tenantEm->method('flush')->willReturnCallback(
            static function () use ($copier, &$flagDuringFlush): void {
                $flagDuringFlush = $copier->isSyncInProgress();
            }
        );

        // Before applyRow — flag must be false
        $this->assertFalse($copier->isSyncInProgress(), 'Flag must be false before applyRow()');

        $copier->applyRow($landlordEm, $tenantEm, $entity, 'insert');

        // During flush — flag must have been true
        $this->assertTrue($flagDuringFlush, 'Flag must be true during flush() inside applyRow()');

        // After applyRow — flag must be false again
        $this->assertFalse($copier->isSyncInProgress(), 'Flag must be false after applyRow()');
    }

    /**
     * isSyncInProgress() returns false when called outside of applyRow().
     */
    public function testIsSyncInProgressFalseOutsideApply(): void
    {
        $copier = new SharedEntityCopier(new NullLogger());
        $this->assertFalse($copier->isSyncInProgress());
    }

    /**
     * isShared() uses reflClass proxy-safe check (WR-01).
     */
    public function testIsSharedUsesReflClassProxySafe(): void
    {
        $sharedEntity = new SharedTestEntity();
        $nonSharedEntity = new NonSharedTestEntity();

        // Build ClassMetadata with reflClass pointing to the real mapped class
        $sharedRefl = new \ReflectionClass(SharedTestEntity::class);
        $nonSharedRefl = new \ReflectionClass(NonSharedTestEntity::class);

        /** @var ClassMetadata&MockObject $sharedMeta */
        $sharedMeta = $this->createMock(ClassMetadata::class);
        $sharedMeta->reflClass = $sharedRefl;

        /** @var ClassMetadata&MockObject $nonSharedMeta */
        $nonSharedMeta = $this->createMock(ClassMetadata::class);
        $nonSharedMeta->reflClass = $nonSharedRefl;

        /** @var EntityManagerInterface&MockObject $em */
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getClassMetadata')->willReturnCallback(
            static function (string $class) use ($sharedMeta, $nonSharedMeta): ClassMetadata {
                return SharedTestEntity::class === $class ? $sharedMeta : $nonSharedMeta;
            }
        );

        $this->assertTrue($this->copier->isShared($sharedEntity, $em), 'SharedTestEntity must be detected as shared');
        $this->assertFalse($this->copier->isShared($nonSharedEntity, $em), 'NonSharedTestEntity must not be detected as shared');
    }
}

/**
 * Helper test entity carrying #[Shared] attribute.
 */
#[Shared]
final class SharedTestEntity
{
}

/**
 * Helper test entity WITHOUT #[Shared] attribute.
 */
final class NonSharedTestEntity
{
}
