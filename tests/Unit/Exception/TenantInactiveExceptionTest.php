<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Unit\Exception;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tenancy\Bundle\Exception\TenantInactiveException;

final class TenantInactiveExceptionTest extends TestCase
{
    public function testGetStatusCodeReturns403(): void
    {
        $exception = new TenantInactiveException();
        $this->assertSame(403, $exception->getStatusCode());
    }

    public function testImplementsHttpExceptionInterface(): void
    {
        $exception = new TenantInactiveException();
        $this->assertInstanceOf(HttpExceptionInterface::class, $exception);
    }

    public function testDefaultMessage(): void
    {
        $exception = new TenantInactiveException();
        $this->assertSame('Tenant is inactive.', $exception->getMessage());
    }

    public function testSlugInMessageWhenProvided(): void
    {
        $exception = new TenantInactiveException('acme');
        $this->assertStringContainsString('acme', $exception->getMessage());
    }

    public function testGetHeadersReturnsEmptyArray(): void
    {
        $exception = new TenantInactiveException();
        $this->assertSame([], $exception->getHeaders());
    }

    public function testExtendsRuntimeException(): void
    {
        $exception = new TenantInactiveException();
        $this->assertInstanceOf(\RuntimeException::class, $exception);
    }
}
