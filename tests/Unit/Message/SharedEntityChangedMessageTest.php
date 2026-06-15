<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Unit\Message;

use PHPUnit\Framework\TestCase;
use Tenancy\Bundle\Entity\Tenant;
use Tenancy\Bundle\Message\SharedEntityChangedMessage;

/**
 * Unit tests for SharedEntityChangedMessage — covers SHARE-03-c scalar discipline.
 *
 * Verifies:
 *  - Construction with (class-string, identifier array, changeType) works
 *  - Each readonly property holds the value passed (no mutation)
 *  - No property holds an object (scalar discipline — SHARE-03-c)
 *  - The message survives PHP serialize/unserialize round-trip (Messenger transport compatibility)
 */
final class SharedEntityChangedMessageTest extends TestCase
{
    public function testCarriesOnlyScalars(): void
    {
        $entityClass = Tenant::class;
        $identifier = ['id' => 42];
        $changeType = 'insert';

        $msg = new SharedEntityChangedMessage($entityClass, $identifier, $changeType);

        self::assertSame(Tenant::class, $msg->entityClass);
        self::assertSame($identifier, $msg->identifier);
        self::assertSame($changeType, $msg->changeType);

        // Scalar discipline: no property must hold an object (SHARE-03-c).
        self::assertFalse(is_object($msg->entityClass), 'entityClass must not be an object');
        self::assertFalse(is_object($msg->identifier), 'identifier must not be an object');
        self::assertFalse(is_object($msg->changeType), 'changeType must not be an object');
    }

    public function testSurvivesSerializeRoundTrip(): void
    {
        $msg = new SharedEntityChangedMessage(
            Tenant::class,
            ['id' => 1, 'slug' => 'widget-a'],
            'update',
        );

        /** @var SharedEntityChangedMessage $restored */
        $restored = unserialize(serialize($msg));

        self::assertInstanceOf(SharedEntityChangedMessage::class, $restored);
        self::assertSame($msg->entityClass, $restored->entityClass);
        self::assertSame($msg->identifier, $restored->identifier);
        self::assertSame($msg->changeType, $restored->changeType);
    }
}
