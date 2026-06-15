<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Unit\DependencyInjection\Compiler;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Tenancy\Bundle\DependencyInjection\Compiler\SharedAsyncContractPass;

/**
 * Unit tests for SharedAsyncContractPass — the compile-time guard for
 * the async shared-entity fan-out wiring (D-06 / SHARE-03-i).
 *
 * Mirrors the structure of MailerTransportContractPassTest (the established
 * project precedent for optional-dependency compile-time guard tests):
 *  - Instantiate a real ContainerBuilder
 *  - Set/omit the tenancy.shared.async parameter
 *  - Call process() and assert no-throw vs \LogicException per the behavior cases
 *
 * @see MailerTransportContractPassTest
 *   — the established precedent this test mirrors for optional-dependency guard testing
 */
final class SharedAsyncContractPassTest extends TestCase
{
    public function testShortCircuitsWhenParameterAbsent(): void
    {
        $container = new ContainerBuilder();
        // NO setParameter('tenancy.shared.async', …) — simulates shared stack not loaded.

        $pass = new SharedAsyncContractPass();
        $pass->process($container);

        // No exception thrown — process() returns early when parameter is absent.
        $this->addToAssertionCount(1);
    }

    public function testShortCircuitsWhenAsyncDisabled(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('tenancy.shared.async', false);

        $pass = new SharedAsyncContractPass();
        $pass->process($container);

        // No exception thrown — process() returns early when async is false (the default).
        $this->addToAssertionCount(1);
    }

    public function testGuardThrowsWhenMessengerAbsent(): void
    {
        if (interface_exists(\Symfony\Component\Messenger\MessageBusInterface::class)) {
            // Cannot simulate the Messenger-absent condition when symfony/messenger IS installed.
            // This is the same pattern used by MailerTransportContractPassTest for MailerInterface:
            // the runtime negative path is skip-guarded, and the structural guarantee is provided
            // by the grep-based SHARE-03-i acceptance criterion (the throw new \LogicException
            // inside the interface_exists(MessageBusInterface) guard branch in SharedAsyncContractPass).
            self::markTestSkipped(
                'Cannot simulate symfony/messenger absence when it is installed. '
                .'See MailerTransportContractPassTest for the established project precedent. '
                .'The throw-on-absent path is verified structurally by the SHARE-03-i grep assertion.'
            );
        }

        $container = new ContainerBuilder();
        $container->setParameter('tenancy.shared.async', true);

        $pass = new SharedAsyncContractPass();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/symfony\/messenger/');
        $pass->process($container);
    }

    public function testNoThrowWhenAsyncTrueAndMessengerPresent(): void
    {
        if (!interface_exists(\Symfony\Component\Messenger\MessageBusInterface::class)) {
            self::markTestSkipped('symfony/messenger not installed');
        }

        $container = new ContainerBuilder();
        $container->setParameter('tenancy.shared.async', true);

        $pass = new SharedAsyncContractPass();
        $pass->process($container);

        // No exception — async:true + Messenger present is the valid async configuration.
        $this->addToAssertionCount(1);
    }
}
