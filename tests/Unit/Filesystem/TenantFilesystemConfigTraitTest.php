<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Unit\Filesystem;

use PHPUnit\Framework\TestCase;

/**
 * Verifies the Phase 24 TenantFilesystemConfigTrait default implementation.
 *
 * Per .planning/phases/24-filesystem-bootstrapper/24-CONTEXT.md §DEC-FILE-CONFIG
 * (TenantInterface does NOT gain a new abstract method; users opt into the
 * trait OR receive the default via AbstractTenant — zero BC break for v0.3
 * downstreams with custom Tenant entities).
 *
 * The trait intentionally does NOT validate the array shape; validation lives
 * in Plan 24-06 (TenantAwareFilesystemDecorator) and Plan 24-07
 * (FilesystemContractPass).
 */
final class TenantFilesystemConfigTraitTest extends TestCase
{
    private function makeStub(): object
    {
        return new class {
            use \Tenancy\Bundle\Filesystem\TenantFilesystemConfigTrait;
        };
    }

    public function testGetterDefaultsToNull(): void
    {
        $obj = $this->makeStub();
        self::assertNull($obj->getFilesystemConfig());
    }

    public function testRoundTripPrefixOnly(): void
    {
        $obj = $this->makeStub();
        $obj->setFilesystemConfig(['prefix' => 'tenant_acme/']);
        self::assertSame(['prefix' => 'tenant_acme/'], $obj->getFilesystemConfig());
    }

    public function testRoundTripAdapterDsnAndServices(): void
    {
        $obj = $this->makeStub();
        $config = [
            'adapter_dsn' => 's3:///bucket?region=eu-central-1',
            'services' => ['users.storage'],
        ];
        $obj->setFilesystemConfig($config);
        self::assertSame($config, $obj->getFilesystemConfig());
    }

    public function testSetNullResetsValue(): void
    {
        $obj = $this->makeStub();
        $obj->setFilesystemConfig(['prefix' => 'x/']);
        $obj->setFilesystemConfig(null);
        self::assertNull($obj->getFilesystemConfig());
    }

    public function testUnknownKeyDoesNotThrow(): void
    {
        $obj = $this->makeStub();
        $obj->setFilesystemConfig(['unknown_key' => 'x']);
        self::assertSame(['unknown_key' => 'x'], $obj->getFilesystemConfig());
    }

    public function testSetterReturnsStatic(): void
    {
        $obj = $this->makeStub();
        self::assertSame($obj, $obj->setFilesystemConfig(null));
        self::assertSame($obj, $obj->setFilesystemConfig(['prefix' => 'a/']));
    }

    public function testDoctrineColumnAttributePresent(): void
    {
        if (!class_exists(\Doctrine\ORM\Mapping\Column::class)) {
            self::markTestSkipped('doctrine/orm not installed');
        }
        $rc = new \ReflectionClass($this->makeStub());
        $rp = $rc->getProperty('filesystemConfig');
        $attrs = $rp->getAttributes(\Doctrine\ORM\Mapping\Column::class);
        self::assertNotEmpty($attrs, 'Property filesystemConfig should carry #[ORM\\Column]');
    }

    public function testPropertyIsPrivateNullableArrayDefaultNull(): void
    {
        $rc = new \ReflectionClass($this->makeStub());
        self::assertTrue($rc->hasProperty('filesystemConfig'), 'Trait should declare filesystemConfig');
        $rp = $rc->getProperty('filesystemConfig');
        self::assertTrue($rp->isPrivate(), 'Property filesystemConfig should be private');
        $type = $rp->getType();
        self::assertNotNull($type, 'Property filesystemConfig should be typed');
        /* @var \ReflectionNamedType $type */
        self::assertSame('array', $type->getName(), 'Property filesystemConfig should be typed array');
        self::assertTrue($type->allowsNull(), 'Property filesystemConfig should allow null');
        self::assertTrue($rp->hasDefaultValue(), 'Property filesystemConfig should have a default');
        self::assertNull($rp->getDefaultValue(), 'Property filesystemConfig default should be null');
    }
}
