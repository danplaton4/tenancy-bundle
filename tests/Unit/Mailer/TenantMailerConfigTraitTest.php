<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Unit\Mailer;

use PHPUnit\Framework\TestCase;
use Tenancy\Bundle\Mailer\TenantMailerConfigTrait;

final class TenantMailerConfigTraitTest extends TestCase
{
    private function makeStub(): object
    {
        return new class {
            use TenantMailerConfigTrait;
        };
    }

    public function testGettersDefaultToNull(): void
    {
        $obj = $this->makeStub();
        self::assertNull($obj->getMailerDsn());
        self::assertNull($obj->getMailerFrom());
        self::assertNull($obj->getMailerReplyTo());
    }

    public function testRoundTripDsn(): void
    {
        $obj = $this->makeStub();
        $obj->setMailerDsn('smtp://u:p@h:25');
        self::assertSame('smtp://u:p@h:25', $obj->getMailerDsn());
    }

    public function testRoundTripFromAndReplyTo(): void
    {
        $obj = $this->makeStub();
        $obj->setMailerFrom('a@b.com')->setMailerReplyTo('r@b.com');
        self::assertSame('a@b.com', $obj->getMailerFrom());
        self::assertSame('r@b.com', $obj->getMailerReplyTo());
    }

    public function testSettersReturnStatic(): void
    {
        $obj = $this->makeStub();
        self::assertSame($obj, $obj->setMailerDsn(null));
        self::assertSame($obj, $obj->setMailerFrom(null));
        self::assertSame($obj, $obj->setMailerReplyTo(null));
    }

    public function testDoctrineColumnAttributesPresent(): void
    {
        if (!class_exists(\Doctrine\ORM\Mapping\Column::class)) {
            self::markTestSkipped('doctrine/orm not installed');
        }
        $rc = new \ReflectionClass($this->makeStub());
        foreach (['mailerDsn', 'mailerFrom', 'mailerReplyTo'] as $prop) {
            $rp = $rc->getProperty($prop);
            $attrs = $rp->getAttributes(\Doctrine\ORM\Mapping\Column::class);
            self::assertNotEmpty($attrs, "Property {$prop} should carry #[ORM\\Column]");
        }
    }

    public function testPropertiesArePrivateNullableStringDefaultNull(): void
    {
        $rc = new \ReflectionClass($this->makeStub());
        foreach (['mailerDsn', 'mailerFrom', 'mailerReplyTo'] as $prop) {
            self::assertTrue($rc->hasProperty($prop), "Trait should declare {$prop}");
            $rp = $rc->getProperty($prop);
            self::assertTrue($rp->isPrivate(), "Property {$prop} should be private");
            $type = $rp->getType();
            self::assertNotNull($type, "Property {$prop} should be typed");
            /** @var \ReflectionNamedType $type */
            self::assertSame('string', $type->getName(), "Property {$prop} should be typed string");
            self::assertTrue($type->allowsNull(), "Property {$prop} should allow null");
            self::assertTrue($rp->hasDefaultValue(), "Property {$prop} should have a default");
            self::assertNull($rp->getDefaultValue(), "Property {$prop} default should be null");
        }
    }
}
