<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Unit\DependencyInjection\Compiler;

use PHPUnit\Framework\TestCase;

final class MailerTransportContractPassTest extends TestCase
{
    public function testStubReservedForWave1Implementation(): void
    {
        if (!interface_exists(\Symfony\Component\Mailer\MailerInterface::class)) {
            $this->markTestSkipped('symfony/mailer not installed');
        }
        $this->markTestIncomplete(
            'Reserved for Wave 1 implementation of MailerTransportContractPass '
            .'(see .planning/phases/20-mailer-bootstrapper/20-VALIDATION.md row BOOT-04-f: '
            .'MailerTransportContractPass rejects missing strategy at compile time; rejects async without x_transport)'
        );
    }
}
