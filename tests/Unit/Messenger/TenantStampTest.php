<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Unit\Messenger;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Stamp\StampInterface;
use Tenancy\Bundle\Messenger\TenantStamp;

final class TenantStampTest extends TestCase
{
    public function testImplementsStampInterface(): void
    {
        $stamp = new TenantStamp('acme');

        $this->assertInstanceOf(StampInterface::class, $stamp);
    }

    public function testCarriesSlug(): void
    {
        $stamp = new TenantStamp('acme-corp');

        $this->assertSame('acme-corp', $stamp->getTenantSlug());
    }

    public function testSurvivesPhpSerializeRoundTrip(): void
    {
        $stamp = new TenantStamp('acme');

        /** @var TenantStamp $restored */
        $restored = unserialize(serialize($stamp));

        $this->assertInstanceOf(TenantStamp::class, $restored);
        $this->assertSame('acme', $restored->getTenantSlug());
    }

    public function testReadonlySlugProperty(): void
    {
        $stamp = new TenantStamp('my-tenant');

        $this->assertSame('my-tenant', $stamp->tenantSlug);
    }
}
