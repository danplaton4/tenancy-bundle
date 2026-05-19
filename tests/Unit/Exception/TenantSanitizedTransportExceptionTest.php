<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Unit\Exception;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Tenancy\Bundle\Exception\TenantSanitizedTransportException;

/**
 * @see .planning/phases/20-mailer-bootstrapper/20-VALIDATION.md row BOOT-04-e
 */
final class TenantSanitizedTransportExceptionTest extends TestCase
{
    protected function setUp(): void
    {
        if (!class_exists(TransportException::class)) {
            self::markTestSkipped('symfony/mailer not installed');
        }
    }

    public function testIsFinal(): void
    {
        $reflection = new \ReflectionClass(TenantSanitizedTransportException::class);
        self::assertTrue($reflection->isFinal(), 'TenantSanitizedTransportException must be final');
    }

    public function testExtendsTransportException(): void
    {
        $exception = new TenantSanitizedTransportException('msg');
        self::assertInstanceOf(TransportException::class, $exception);
    }

    public function testImplementsTransportExceptionInterface(): void
    {
        $exception = new TenantSanitizedTransportException('msg');
        self::assertInstanceOf(TransportExceptionInterface::class, $exception);
    }

    public function testPreservesMessageCodePrevious(): void
    {
        $previous = new \RuntimeException('original');
        $exception = new TenantSanitizedTransportException('msg', 42, $previous);

        self::assertSame('msg', $exception->getMessage());
        self::assertSame(42, $exception->getCode());
        self::assertSame($previous, $exception->getPrevious());
    }
}
