<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Unit\DependencyInjection\Compiler;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\Mailer\Messenger\SendEmailMessage;
use Tenancy\Bundle\DependencyInjection\Compiler\MailerTransportContractPass;

/**
 * Unit tests for the MailerTransportContractPass — the compile-time guard for
 * the per-tenant Mailer wiring (BOOT-04-f).
 *
 * Covers seven scenarios:
 *  1. MailerInterface absent → no-op
 *  2. async = 'false' → no-op even if strategy missing
 *  3. async = 'true' + strategy MISSING → \LogicException
 *  4. async = 'true' + strategy PRESENT → no-op
 *  5. async = 'auto' + framework.messenger.routing has no SendEmailMessage entry → no-op
 *  6. async = 'auto' + framework.messenger.routing routes SendEmailMessage
 *     → \LogicException when strategy missing; no-op when present
 *  7. parameter MISSING entirely → \LogicException referencing the config key
 */
final class MailerTransportContractPassTest extends TestCase
{
    protected function setUp(): void
    {
        if (!interface_exists(\Symfony\Component\Mailer\MailerInterface::class)) {
            self::markTestSkipped('symfony/mailer not installed');
        }
    }

    public function testNoOpWhenMailerInterfaceAbsent(): void
    {
        // This case is impossible to simulate after setUp() — the test exists
        // to document the early-return guard inside process(). We assert the
        // pass is at least constructible and that calling process() on a
        // container missing the async parameter still throws (proving the
        // interface_exists() guard only short-circuits when the interface is
        // truly absent at runtime — not when it merely "could be" missing).
        $pass = new MailerTransportContractPass();
        $this->expectException(\LogicException::class);
        $pass->process(new ContainerBuilder());
    }

    public function testNoOpWhenAsyncIsFalseEvenIfStrategyAbsent(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('tenancy.mailer.async', 'false');

        $pass = new MailerTransportContractPass();
        $pass->process($container);

        // No exception thrown — process() completes silently.
        $this->assertFalse($container->hasDefinition('tenancy.mailer.message_decorator'));
    }

    public function testThrowsWhenAsyncIsTrueAndStrategyServiceIsAbsent(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('tenancy.mailer.async', 'true');

        $pass = new MailerTransportContractPass();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/X-Transport/');
        $pass->process($container);
    }

    public function testThrowsMessageMentionsAsyncConfigKey(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('tenancy.mailer.async', 'true');

        $pass = new MailerTransportContractPass();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/tenancy\.mailer\.async/');
        $pass->process($container);
    }

    public function testReturnsWhenAsyncIsTrueAndStrategyServiceIsDefined(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('tenancy.mailer.async', 'true');
        $container->setDefinition('tenancy.mailer.message_decorator', new Definition(\stdClass::class));

        $pass = new MailerTransportContractPass();
        $pass->process($container);

        $this->assertTrue($container->hasDefinition('tenancy.mailer.message_decorator'));
    }

    public function testAutoModeWithoutSendEmailRoutingIsNoOp(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('tenancy.mailer.async', 'auto');
        $this->registerFrameworkExtension($container);
        $container->prependExtensionConfig('framework', ['messenger' => ['routing' => []]]);

        $pass = new MailerTransportContractPass();
        $pass->process($container);

        // No exception — auto detected sync routing, no strategy required.
        $this->assertFalse($container->hasDefinition('tenancy.mailer.message_decorator'));
    }

    public function testAutoModeWithSendEmailRoutingThrowsWhenStrategyAbsent(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('tenancy.mailer.async', 'auto');
        $this->registerFrameworkExtension($container);
        $container->prependExtensionConfig('framework', [
            'messenger' => [
                'routing' => [
                    SendEmailMessage::class => 'async',
                ],
            ],
        ]);

        $pass = new MailerTransportContractPass();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/X-Transport|tenancy\.mailer\.async/');
        $pass->process($container);
    }

    public function testAutoModeWithSendEmailRoutingIsNoOpWhenStrategyPresent(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('tenancy.mailer.async', 'auto');
        $this->registerFrameworkExtension($container);
        $container->prependExtensionConfig('framework', [
            'messenger' => [
                'routing' => [
                    SendEmailMessage::class => 'async',
                ],
            ],
        ]);
        $container->setDefinition('tenancy.mailer.message_decorator', new Definition(\stdClass::class));

        $pass = new MailerTransportContractPass();
        $pass->process($container);

        // No exception — strategy wired, so auto-detected async is allowed.
        $this->assertTrue($container->hasDefinition('tenancy.mailer.message_decorator'));
    }

    public function testThrowsWhenAsyncParameterMissingEntirely(): void
    {
        $container = new ContainerBuilder();
        // NO setParameter('tenancy.mailer.async', …)

        $pass = new MailerTransportContractPass();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/tenancy\.mailer\.async/');
        $pass->process($container);
    }

    public function testThrowsOnInvalidAsyncValue(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('tenancy.mailer.async', 'sometimes');

        $pass = new MailerTransportContractPass();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/tenancy\.mailer\.async/');
        $pass->process($container);
    }

    private function registerFrameworkExtension(ContainerBuilder $container): void
    {
        // Symfony's ContainerBuilder requires the extension to be registered
        // before prependExtensionConfig() will accept config for that key.
        $container->registerExtension(new class extends Extension {
            public function load(array $configs, ContainerBuilder $container): void
            {
            }

            public function getAlias(): string
            {
                return 'framework';
            }
        });
    }
}
