<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Unit\DependencyInjection\Compiler;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Argument\IteratorArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Tenancy\Bundle\DependencyInjection\Compiler\MessengerMiddlewarePass;

final class MessengerMiddlewarePassTest extends TestCase
{
    public function testSkipsWhenNoMessengerBusesRegistered(): void
    {
        $container = new ContainerBuilder();
        $pass = new MessengerMiddlewarePass();

        $pass->process($container);

        // No exception — pass exits gracefully
        $this->assertTrue(true);
    }

    public function testPrependsMiddlewareToExistingBusParameter(): void
    {
        if (!interface_exists(\Symfony\Component\Messenger\MessageBusInterface::class)) {
            $this->markTestSkipped('symfony/messenger not installed');
        }

        $container = new ContainerBuilder();

        // Register a bus with the messenger.bus tag
        $busDefinition = new Definition(\stdClass::class);
        $busDefinition->addTag('messenger.bus');
        $container->setDefinition('messenger.bus.default', $busDefinition);

        // Simulate FrameworkExtension setting the middleware parameter
        $container->setParameter('messenger.bus.default.middleware', [
            ['id' => 'send_message'],
            ['id' => 'handle_message'],
        ]);

        $pass = new MessengerMiddlewarePass();
        $pass->process($container);

        /** @var array<array{id: string}> $middleware */
        $middleware = $container->getParameter('messenger.bus.default.middleware');

        $this->assertCount(4, $middleware);
        $this->assertSame('tenancy.messenger.sending_middleware', $middleware[0]['id']);
        $this->assertSame('tenancy.messenger.worker_middleware', $middleware[1]['id']);
        $this->assertSame('send_message', $middleware[2]['id']);
        $this->assertSame('handle_message', $middleware[3]['id']);
    }

    public function testPrependsMiddlewareToMultipleBuses(): void
    {
        if (!interface_exists(\Symfony\Component\Messenger\MessageBusInterface::class)) {
            $this->markTestSkipped('symfony/messenger not installed');
        }

        $container = new ContainerBuilder();

        // Register two buses
        $bus1 = new Definition(\stdClass::class);
        $bus1->addTag('messenger.bus');
        $container->setDefinition('messenger.bus.default', $bus1);
        $container->setParameter('messenger.bus.default.middleware', [['id' => 'handle_message']]);

        $bus2 = new Definition(\stdClass::class);
        $bus2->addTag('messenger.bus');
        $container->setDefinition('messenger.bus.events', $bus2);
        $container->setParameter('messenger.bus.events.middleware', [['id' => 'handle_message']]);

        $pass = new MessengerMiddlewarePass();
        $pass->process($container);

        /** @var array<array{id: string}> $defaultMiddleware */
        $defaultMiddleware = $container->getParameter('messenger.bus.default.middleware');
        $this->assertSame('tenancy.messenger.sending_middleware', $defaultMiddleware[0]['id']);

        /** @var array<array{id: string}> $eventsMiddleware */
        $eventsMiddleware = $container->getParameter('messenger.bus.events.middleware');
        $this->assertSame('tenancy.messenger.sending_middleware', $eventsMiddleware[0]['id']);
    }

    public function testFallsBackToDefinitionModificationWhenParameterMissing(): void
    {
        if (!interface_exists(\Symfony\Component\Messenger\MessageBusInterface::class)) {
            $this->markTestSkipped('symfony/messenger not installed');
        }

        $container = new ContainerBuilder();

        // Register a bus with an IteratorArgument (simulates post-MessengerPass state)
        $existingRef = new Reference('existing_middleware');
        $busDefinition = new Definition(\stdClass::class);
        $busDefinition->addTag('messenger.bus');
        $busDefinition->addArgument(new IteratorArgument([$existingRef]));
        $container->setDefinition('messenger.bus.default', $busDefinition);

        // NO parameter set — simulates MessengerPass already consuming it

        $pass = new MessengerMiddlewarePass();
        $pass->process($container);

        /** @var IteratorArgument $arg */
        $arg = $busDefinition->getArgument(0);
        $refs = $arg->getValues();

        $this->assertCount(3, $refs);
        $this->assertSame('tenancy.messenger.sending_middleware', (string) $refs[0]);
        $this->assertSame('tenancy.messenger.worker_middleware', (string) $refs[1]);
        $this->assertSame('existing_middleware', (string) $refs[2]);
    }

    public function testSkipsBusWhenDefinitionArgIsNotIteratorArgument(): void
    {
        if (!interface_exists(\Symfony\Component\Messenger\MessageBusInterface::class)) {
            $this->markTestSkipped('symfony/messenger not installed');
        }

        $container = new ContainerBuilder();

        // Bus with a non-IteratorArgument first arg (e.g. a string)
        $busDefinition = new Definition(\stdClass::class);
        $busDefinition->addTag('messenger.bus');
        $busDefinition->addArgument('not_an_iterator');
        $container->setDefinition('messenger.bus.default', $busDefinition);

        // No parameter — falls into definition-based path but arg is not IteratorArgument

        $pass = new MessengerMiddlewarePass();
        $pass->process($container);

        // Argument unchanged — pass skipped this bus gracefully
        $this->assertSame('not_an_iterator', $busDefinition->getArgument(0));
    }
}
