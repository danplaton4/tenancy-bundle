<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Unit\Exception;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tenancy\Bundle\Exception\TenantNotFoundException;

final class TenantNotFoundExceptionTest extends TestCase
{
    public function testGetStatusCodeReturns404(): void
    {
        $exception = new TenantNotFoundException();
        $this->assertSame(404, $exception->getStatusCode());
    }

    public function testImplementsHttpExceptionInterface(): void
    {
        $exception = new TenantNotFoundException();
        $this->assertInstanceOf(HttpExceptionInterface::class, $exception);
    }

    public function testDefaultMessage(): void
    {
        $exception = new TenantNotFoundException();
        $this->assertSame('Tenant not found.', $exception->getMessage());
    }

    public function testCustomMessageIsAccepted(): void
    {
        $exception = new TenantNotFoundException('Custom message.');
        $this->assertSame('Custom message.', $exception->getMessage());
    }

    public function testGetHeadersReturnsEmptyArray(): void
    {
        $exception = new TenantNotFoundException();
        $this->assertSame([], $exception->getHeaders());
    }

    public function testExtendsRuntimeException(): void
    {
        $exception = new TenantNotFoundException();
        $this->assertInstanceOf(\RuntimeException::class, $exception);
    }

    public function testPreviousExceptionIsPassable(): void
    {
        $previous = new \RuntimeException('original');
        $exception = new TenantNotFoundException('Tenant not found.', $previous);
        $this->assertSame($previous, $exception->getPrevious());
    }
}
