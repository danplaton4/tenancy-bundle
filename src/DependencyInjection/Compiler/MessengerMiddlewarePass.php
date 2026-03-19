<?php

declare(strict_types=1);

namespace Tenancy\Bundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Compiler pass that prepends tenancy middleware to every Messenger bus middleware stack.
 *
 * FrameworkExtension stores the merged middleware config in a container parameter named
 * "{busId}.middleware" (e.g. "messenger.bus.default.middleware"). MessengerPass later reads
 * that parameter to build the actual middleware service references.
 *
 * We modify the parameter directly here — after FrameworkExtension runs (loadExtension /
 * prependExtension phase) but before MessengerPass processes it — so our middleware is
 * injected regardless of what the user put in their framework.messenger.buses config.
 *
 * This approach is required because the middleware array uses performNoDeepMerging() in the
 * Symfony Configuration tree, so prependExtensionConfig cannot append to it reliably.
 */
final class MessengerMiddlewarePass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!interface_exists(\Symfony\Component\Messenger\MessageBusInterface::class)) {
            return;
        }

        $tenancyMiddleware = [
            ['id' => 'tenancy.messenger.sending_middleware'],
            ['id' => 'tenancy.messenger.worker_middleware'],
        ];

        $busIds = array_keys($container->findTaggedServiceIds('messenger.bus'));

        foreach ($busIds as $busId) {
            $paramName = $busId . '.middleware';

            if (!$container->hasParameter($paramName)) {
                // MessengerPass may have already consumed the parameter — try to modify bus definition directly
                if ($container->hasDefinition($busId)) {
                    $busDefinition = $container->getDefinition($busId);
                    $middlewareArg  = $busDefinition->getArgument(0);

                    if ($middlewareArg instanceof \Symfony\Component\DependencyInjection\Argument\IteratorArgument) {
                        $existingRefs = $middlewareArg->getValues();
                        $newRefs      = [];
                        foreach ($tenancyMiddleware as $m) {
                            $newRefs[] = new \Symfony\Component\DependencyInjection\Reference($m['id']);
                        }
                        $busDefinition->replaceArgument(0, new \Symfony\Component\DependencyInjection\Argument\IteratorArgument(array_merge($newRefs, $existingRefs)));
                    }
                }
                continue;
            }

            /** @var array<array{id: string, arguments?: list<mixed>}> $existing */
            $existing = $container->getParameter($paramName);

            // Prepend tenancy middleware so they run before user-defined middleware
            $container->setParameter($paramName, array_merge($tenancyMiddleware, $existing));
        }
    }
}
