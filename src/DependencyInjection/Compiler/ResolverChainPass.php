<?php

declare(strict_types=1);

namespace Tenancy\Bundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\PriorityTaggedServiceTrait;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Tenancy\Bundle\Resolver\ConsoleResolver;
use Tenancy\Bundle\Resolver\HeaderResolver;
use Tenancy\Bundle\Resolver\HostResolver;
use Tenancy\Bundle\Resolver\QueryParamResolver;
use Tenancy\Bundle\Resolver\ResolverChain;

final class ResolverChainPass implements CompilerPassInterface
{
    use PriorityTaggedServiceTrait;

    private const BUILT_IN_RESOLVER_MAP = [
        'host' => HostResolver::class,
        'header' => HeaderResolver::class,
        'query_param' => QueryParamResolver::class,
        'console' => ConsoleResolver::class,
    ];

    public function process(ContainerBuilder $container): void
    {
        if (!$container->has(ResolverChain::class)) {
            return;
        }

        $definition = $container->findDefinition(ResolverChain::class);

        // Build allowed FQCN set from config short-names
        $allowedFqcns = null;
        if ($container->hasParameter('tenancy.resolvers')) {
            /** @var list<string> $configuredResolvers */
            $configuredResolvers = $container->getParameter('tenancy.resolvers');
            $allowedFqcns = [];
            foreach ($configuredResolvers as $name) {
                if (isset(self::BUILT_IN_RESOLVER_MAP[$name])) {
                    $allowedFqcns[] = self::BUILT_IN_RESOLVER_MAP[$name];
                } elseif (class_exists($name) || interface_exists($name)) {
                    // If name is not in the map, it could be an FQCN — add directly
                    $allowedFqcns[] = $name;
                }
            }
        }

        $resolvers = $this->findAndSortTaggedServices('tenancy.resolver', $container);

        foreach ($resolvers as $resolver) {
            $serviceId = (string) $resolver;

            // If filtering is active, check whether this resolver is allowed
            if (null !== $allowedFqcns) {
                // Resolve actual FQCN from the definition to handle aliased service IDs
                $resolverDefinition = $container->findDefinition($serviceId);
                $fqcn = $resolverDefinition->getClass() ?? $serviceId;

                // Built-in resolvers must be in the allowed list
                if (in_array($fqcn, self::BUILT_IN_RESOLVER_MAP, true)) {
                    if (!in_array($fqcn, $allowedFqcns, true)) {
                        continue; // Skip this built-in resolver — not in config
                    }
                }
                // Custom resolvers (not in the built-in map) always pass through
            }

            $definition->addMethodCall('addResolver', [$resolver]);
        }
    }
}
