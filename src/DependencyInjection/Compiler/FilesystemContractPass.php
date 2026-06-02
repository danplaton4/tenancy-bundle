<?php

declare(strict_types=1);

namespace Tenancy\Bundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Tenancy\Bundle\Filesystem\FilesystemPrefixingDecorator;
use Tenancy\Bundle\Filesystem\TenantAwareFilesystemDecorator;

/**
 * Compile-time guard + tag-driven decoration injection for per-tenant Filesystem scoping.
 *
 * Walks all services tagged 'tenancy.scoped' and, per tag attribute:
 *  - strategy: prefix  → wraps with FilesystemPrefixingDecorator
 *  - strategy: per_tenant_adapter → wraps with TenantAwareFilesystemDecorator
 *
 * Three compile-time guards (DEC-FILE-COMPILE-PASS):
 *  1. Reject "filesystem.enabled=true + league/flysystem-bundle not installed".
 *  2. Reject "per_tenant_adapter strategy + allow_per_tenant_adapter=false".
 *  3. Reject "invalid strategy attribute" (only 'prefix' and 'per_tenant_adapter' are valid).
 *
 * Returns early (no-op) when tenancy.filesystem.enabled is false (the default).
 * This preserves the zero-config story for v0.3 users upgrading — they see no
 * behaviour change until they explicitly opt in with enabled: true.
 *
 * @see .planning/phases/24-filesystem-bootstrapper/24-CONTEXT.md §DEC-FILE-COMPILE-PASS
 * @see .planning/phases/24-filesystem-bootstrapper/24-CONTEXT.md §DEC-FILE-MULTI
 * @see .planning/phases/24-filesystem-bootstrapper/24-RESEARCH.md §Pattern 3
 * @see .planning/phases/24-filesystem-bootstrapper/24-RESEARCH.md §Pitfall 1 (service IDs are bare names)
 */
final class FilesystemContractPass implements CompilerPassInterface
{
    /**
     * Tag name applied by users to opt a Flysystem storage into per-tenant scoping.
     */
    private const TAG = 'tenancy.scoped';

    /**
     * Container parameter: tenancy.filesystem.enabled (bool, default false).
     */
    private const ENABLED_PARAM = 'tenancy.filesystem.enabled';

    /**
     * Container parameter: tenancy.filesystem.allow_per_tenant_adapter (bool, default true).
     */
    private const ALLOW_PER_TENANT_PARAM = 'tenancy.filesystem.allow_per_tenant_adapter';

    /**
     * Default prefix template when 'prefix_template' tag attribute is absent.
     */
    private const DEFAULT_PREFIX_TEMPLATE = 'tenant_{slug}/';

    /**
     * Decorator class for 'prefix' strategy.
     */
    private const PREFIX_DECORATOR = FilesystemPrefixingDecorator::class;

    /**
     * Decorator class for 'per_tenant_adapter' strategy.
     */
    private const PER_TENANT_DECORATOR = TenantAwareFilesystemDecorator::class;

    public function process(ContainerBuilder $container): void
    {
        // Early-return when filesystem feature is disabled (the default).
        if (!$container->hasParameter(self::ENABLED_PARAM) || !$container->getParameter(self::ENABLED_PARAM)) {
            return;
        }

        // Guard 1: enabled but league/flysystem-bundle not installed.
        if (!interface_exists(\League\Flysystem\FilesystemOperator::class)) {
            throw new \LogicException('tenancy.filesystem.enabled: true requires league/flysystem-bundle. Run: composer require league/flysystem-bundle');
        }

        $allowPerTenant = (bool) $container->getParameter(self::ALLOW_PER_TENANT_PARAM);

        foreach ($container->findTaggedServiceIds(self::TAG) as $id => $tags) {
            // Guard 4: a service must not carry more than one tenancy.scoped tag.
            // Two tags on the same service would silently overwrite the first
            // decorator definition with the second — only the last tag would win,
            // with no error. Fail loudly instead.
            if (count($tags) > 1) {
                throw new \LogicException(sprintf('tenancy.scoped on "%s" declared %d times; exactly one strategy per service is supported.', $id, count($tags)));
            }

            $attrs = $tags[0];
            $strategy = $attrs['strategy'] ?? 'prefix';
            $prefixTemplate = $attrs['prefix_template'] ?? self::DEFAULT_PREFIX_TEMPLATE;

            // Guard 3: valid strategy attribute.
            if (!in_array($strategy, ['prefix', 'per_tenant_adapter'], true)) {
                throw new \LogicException(sprintf('tenancy.scoped tag on "%s" has invalid strategy "%s". Valid values: prefix, per_tenant_adapter.', $id, $strategy));
            }

            // Guard 2: per_tenant_adapter strategy blocked by admin escape hatch.
            if ('per_tenant_adapter' === $strategy && !$allowPerTenant) {
                throw new \LogicException(sprintf('tenancy.scoped on "%s" requested per_tenant_adapter strategy, but tenancy.filesystem.allow_per_tenant_adapter is false. Set allow_per_tenant_adapter: true in your tenancy.filesystem config to enable this mode.', $id));
            }

            $decorator = $this->buildDecorator($strategy, $prefixTemplate);
            $decorator->setDecoratedService($id);
            $container->setDefinition($id.'.tenant_scoped', $decorator);
        }
    }

    /**
     * Build the decorator Definition for the given strategy.
     *
     * prefix: FilesystemPrefixingDecorator($inner, TenantContext, $prefixTemplate)
     * per_tenant_adapter: TenantAwareFilesystemDecorator($inner, TenantContext, LruFilesystemCache, AdapterDsnParser)
     */
    private function buildDecorator(string $strategy, string $prefixTemplate): Definition
    {
        if ('prefix' === $strategy) {
            $decorator = new Definition(self::PREFIX_DECORATOR);
            $decorator->setArguments([
                new Reference('.inner'),
                new Reference('tenancy.context'),
                $prefixTemplate,
            ]);

            return $decorator;
        }

        // per_tenant_adapter
        $decorator = new Definition(self::PER_TENANT_DECORATOR);
        $decorator->setArguments([
            new Reference('.inner'),
            new Reference('tenancy.context'),
            new Reference('tenancy.filesystem.lru_cache'),
            new Reference('tenancy.filesystem.adapter_dsn_parser'),
        ]);

        return $decorator;
    }
}
