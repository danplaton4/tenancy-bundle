<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Unit\Mailer;

use PHPUnit\Framework\TestCase;

final class SanitizingMailerDecoratorTest extends TestCase
{
    public function testStubReservedForWave1Implementation(): void
    {
        if (!interface_exists(\Symfony\Component\Mailer\MailerInterface::class)) {
            $this->markTestSkipped('symfony/mailer not installed');
        }
        $this->markTestIncomplete(
            'Reserved for Wave 1 implementation of SanitizingMailerDecorator '
            .'(see .planning/phases/20-mailer-bootstrapper/20-VALIDATION.md row BOOT-04-e: '
            .'SanitizingMailerDecorator redacts password from TransportException message and trace)'
        );
    }
}
