<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Unit\Exception;

use PHPUnit\Framework\TestCase;
use Tenancy\Bundle\Exception\TenantMissingException;

final class TenantMissingExceptionTest extends TestCase
{
    public function testExtendsRuntimeException(): void
    {
        $exception = new TenantMissingException('App\Entity\Post');
        $this->assertInstanceOf(\RuntimeException::class, $exception);
    }

    public function testMessageIncludesEntityClassName(): void
    {
        $exception = new TenantMissingException('App\Entity\Post');
        $this->assertStringContainsString('App\Entity\Post', $exception->getMessage());
    }

    public function testMessageIncludesCannotQueryPhrase(): void
    {
        $exception = new TenantMissingException('App\Entity\Post');
        $this->assertStringContainsString('Cannot query TenantAware entity', $exception->getMessage());
    }

    public function testPreviousExceptionIsPassable(): void
    {
        $previous = new \RuntimeException('original');
        $exception = new TenantMissingException('App\Entity\Post', $previous);
        $this->assertSame($previous, $exception->getPrevious());
    }
}
