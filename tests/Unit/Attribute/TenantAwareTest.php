<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Unit\Attribute;

use PHPUnit\Framework\TestCase;
use Tenancy\Bundle\Attribute\TenantAware;

final class TenantAwareTest extends TestCase
{
    public function testAttributeHasTargetClassFlag(): void
    {
        $reflClass = new \ReflectionClass(TenantAware::class);
        $attributes = $reflClass->getAttributes(\Attribute::class);

        $this->assertNotEmpty($attributes, 'TenantAware class must have #[Attribute] attribute declared');

        $attributeInstance = $attributes[0]->newInstance();
        $this->assertSame(\Attribute::TARGET_CLASS, $attributeInstance->flags);
    }

    public function testAttributeCanBeInstantiated(): void
    {
        $instance = new TenantAware();
        $this->assertInstanceOf(TenantAware::class, $instance);
    }
}
