<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Integration\Mailer;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Mailer\MailerInterface;

/**
 * Test-only compiler pass that overrides the TenantAwareTransportsDecorator's
 * 6th-positional Closure transportFactory argument with a SpyTransportFactory
 * closure.
 *
 * Production wiring (config/services.php) passes only 5 args to the decorator
 * (inner, provider, cache, context, event_dispatcher) — the 6th (index 5)
 * transportFactory defaults to Transport::fromDsn. This pass injects a Closure
 * service at index 5.
 *
 * Decorator constructor positions (verified against
 * src/Mailer/TenantAwareTransportsDecorator.php):
 *   0=inner, 1=provider, 2=cache, 3=context, 4=eventDispatcher, 5=transportFactory
 *
 * Skip silently if MailerInterface is absent or the decorator definition is
 * not yet registered on this kernel (defensive — the pass remains harmless
 * if added to a kernel that does not wire mailer).
 *
 * Threat surface: this pass lives under tests/Integration/Mailer/ and is
 * autoloaded from composer.json `autoload-dev` only. Consumers of the
 * production bundle never see it.
 */
final class ReplaceTenantTransportFactoryPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!interface_exists(MailerInterface::class)) {
            return;
        }
        if (!$container->hasDefinition('tenancy.mailer.transports_decorator')) {
            return;
        }

        // Register the Closure factory service: a non-public service whose
        // factory call is `SpyTransportFactory::create()` (returns \Closure).
        $factoryDef = new Definition(\Closure::class);
        $factoryDef->setFactory([SpyTransportFactory::class, 'create']);
        $factoryDef->setPublic(false);
        $container->setDefinition('test.tenancy.mailer.spy_transport_factory', $factoryDef);

        // Override the decorator's 6th positional arg (index 5).
        $decoratorDef = $container->getDefinition('tenancy.mailer.transports_decorator');
        $decoratorDef->setArgument(5, new Reference('test.tenancy.mailer.spy_transport_factory'));
    }
}
