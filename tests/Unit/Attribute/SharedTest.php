<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Unit\Attribute;

use PHPUnit\Framework\TestCase;

/**
 * Covers SHARE-01-a: #[Shared] is a bare class-target PHP attribute (no constructor params).
 *
 * Wave 0 state: Tenancy\Bundle\Attribute\Shared lands in Plan 25-01.
 * Tests skip gracefully until the production class exists.
 */
final class SharedTest extends TestCase
{
    protected function setUp(): void
    {
        if (!class_exists(\Tenancy\Bundle\Attribute\Shared::class)) {
            self::markTestSkipped('Tenancy\\Bundle\\Attribute\\Shared not yet available — lands in Plan 25-01.');
        }
    }

    /**
     * SHARE-01-a: #[Shared] is a bare class-target PHP attribute (no constructor params).
     */
    public function testSharedAttributeIsClassTarget(): void
    {
        $reflClass = new \ReflectionClass(\Tenancy\Bundle\Attribute\Shared::class);
        $attributes = $reflClass->getAttributes(\Attribute::class);

        $this->assertNotEmpty($attributes, 'Shared class must have #[Attribute] attribute declared');

        $attributeInstance = $attributes[0]->newInstance();
        $this->assertSame(\Attribute::TARGET_CLASS, $attributeInstance->flags);
    }

    /**
     * SHARE-01-a: #[Shared] attribute can be instantiated without constructor arguments.
     */
    public function testSharedAttributeCanBeInstantiated(): void
    {
        $instance = new \Tenancy\Bundle\Attribute\Shared();
        $this->assertInstanceOf(\Tenancy\Bundle\Attribute\Shared::class, $instance);
    }
}
