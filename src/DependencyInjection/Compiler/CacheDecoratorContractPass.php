<?php

declare(strict_types=1);

namespace Tenancy\Bundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

/**
 * Asserts at container compile time that every cache decorator shipped by TenancyBundle
 * implements every Symfony\* interface exposed by the decorated service.
 *
 * Without this pass, a missing interface silently compiles; PHP only type-errors at the
 * point a consumer type-hints the missing interface. The resulting stack trace doesn't
 * point at TenancyBundle — this pass is the early guard that turns a runtime boot failure
 * into a container compile error with a descriptive message.
 *
 * Filter: only Symfony\* interfaces are enforced. Non-Symfony interfaces (e.g.
 * Psr\Log\LoggerAwareInterface on FilesystemAdapter) are not aliased in the container
 * and are not required on the decorator.
 */
final class CacheDecoratorContractPass implements CompilerPassInterface
{
    /**
     * Decorator service ID => decorated service ID.
     *
     * @var array<string, string>
     */
    private const DECORATORS = [
        'tenancy.cache_adapter' => 'cache.app',
        'tenancy.cache_adapter.taggable' => 'cache.app.taggable',
    ];

    public function process(ContainerBuilder $container): void
    {
        foreach (self::DECORATORS as $decoratorId => $decoratedId) {
            if (!$container->hasDefinition($decoratorId)) {
                continue;
            }
            if (!$container->hasDefinition($decoratedId)) {
                continue;
            }

            $decoratorClass = $container->getDefinition($decoratorId)->getClass();
            if (!is_string($decoratorClass) || !class_exists($decoratorClass)) {
                continue;
            }

            $decoratedClass = $this->resolveEffectiveClass($container, $decoratedId);
            if (!is_string($decoratedClass) || !class_exists($decoratedClass)) {
                continue;
            }

            $decoratedInterfaces = class_implements($decoratedClass) ?: [];
            $decoratorInterfaces = class_implements($decoratorClass) ?: [];

            $missing = array_diff($decoratedInterfaces, $decoratorInterfaces);
            $missing = array_filter($missing, static fn (string $i): bool => str_starts_with($i, 'Symfony\\'));

            if ([] !== $missing) {
                throw new \LogicException(sprintf(
                    'Cache decorator "%s" must implement every Symfony interface exposed by "%s". Missing: %s',
                    $decoratorClass,
                    $decoratedClass,
                    implode(', ', $missing),
                ));
            }
        }
    }

    private function resolveEffectiveClass(ContainerBuilder $container, string $id): ?string
    {
        $def = $container->getDefinition($id);

        // Parent-definition recursion (cache.app's parent is cache.adapter.filesystem)
        while ($def instanceof Definition && null === $def->getClass() && null !== $def->getParent()) {
            $parentId = $def->getParent();
            if (!$container->hasDefinition($parentId)) {
                return null;
            }
            $def = $container->getDefinition($parentId);
        }

        return $def->getClass();
    }
}
