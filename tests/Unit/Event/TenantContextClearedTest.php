<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Unit\Event;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tenancy\Bundle\Event\TenantContextCleared;

final class TenantContextClearedTest extends TestCase
{
    public function testCanBeInstantiated(): void
    {
        $event = new TenantContextCleared();
        $this->assertInstanceOf(TenantContextCleared::class, $event);
    }

    public function testHasNoPublicProperties(): void
    {
        $rc = new ReflectionClass(TenantContextCleared::class);
        $publicProperties = $rc->getProperties(\ReflectionProperty::IS_PUBLIC);

        $this->assertCount(0, $publicProperties, 'TenantContextCleared must have no public properties (signal-only event)');
    }
}
