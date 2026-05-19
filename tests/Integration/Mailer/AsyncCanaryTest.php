<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Integration\Mailer;

use PHPUnit\Framework\TestCase;

/**
 * Phase 20 load-bearing test (BOOT-04-g).
 *
 * Two scenarios reserved for Wave 1+ implementation:
 *
 *   1. testSyncDispatchUsesTenantDsn — set tenant context, send via
 *      MailerInterface, assert SpyTransport recorded the tenant's DSN.
 *
 *   2. testAsyncDispatchInWorkerUsesTenantDsnNotLandlord — dispatch
 *      SendEmailMessage in tenant context, clear context, run worker
 *      in a clean process, assert SpyTransport still recorded the
 *      ORIGINATING tenant's DSN (not the landlord fallback). This is
 *      the canary that proves async tenant-context preservation
 *      across the message bus boundary.
 *
 * Plan 04 implements the sync path; Plan 06 implements the async path
 * and flips this test from incomplete to green.
 */
final class AsyncCanaryTest extends TestCase
{
    /** @phpstan-ignore-next-line property.unusedType — kernel boot deferred to Plan 04/06 */
    private static ?MailerTestKernel $kernel = null;

    public static function setUpBeforeClass(): void
    {
        // Kernel boot is deferred to Plan 04/06 — Wave 0 only verifies the
        // stub can be loaded and reported as incomplete by PHPUnit.
        $cacheDir = sys_get_temp_dir().'/tenancy_mailer_test';
        if (is_dir($cacheDir)) {
            self::removeDir($cacheDir);
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (null !== static::$kernel) {
            static::$kernel->shutdown();
            static::$kernel = null;
        }

        $cacheDir = sys_get_temp_dir().'/tenancy_mailer_test';
        if (is_dir($cacheDir)) {
            self::removeDir($cacheDir);
        }
    }

    public function testSyncDispatchUsesTenantDsn(): void
    {
        if (!interface_exists(\Symfony\Component\Mailer\MailerInterface::class)) {
            $this->markTestSkipped('symfony/mailer not installed');
        }
        $this->markTestIncomplete(
            'Reserved for Plan 04 — see .planning/phases/20-mailer-bootstrapper/20-VALIDATION.md '
            .'row BOOT-04-g: sync dispatch must use tenant DSN, recorded by SpyTransport.'
        );
    }

    public function testAsyncDispatchInWorkerUsesTenantDsnNotLandlord(): void
    {
        if (!interface_exists(\Symfony\Component\Mailer\MailerInterface::class)) {
            $this->markTestSkipped('symfony/mailer not installed');
        }
        $this->markTestIncomplete(
            'Reserved for Plan 06 — see .planning/phases/20-mailer-bootstrapper/20-VALIDATION.md '
            .'row BOOT-04-g: dispatch in tenant A HTTP context; worker in clean context restores '
            .'tenant A; SpyTransport must record tenant A DSN, never landlord null://null.'
        );
    }

    private static function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        if (false === $items) {
            return;
        }
        foreach ($items as $item) {
            if ('.' === $item || '..' === $item) {
                continue;
            }
            $path = $dir.'/'.$item;
            if (is_dir($path)) {
                self::removeDir($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }
}
