<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Cache;

use Symfony\Component\Cache\Adapter\TagAwareAdapterInterface;
use Symfony\Component\Cache\PruneableInterface;
use Symfony\Component\Cache\ResettableInterface;
use Symfony\Contracts\Cache\NamespacedPoolInterface;
use Symfony\Contracts\Cache\TagAwareCacheInterface;
use Tenancy\Bundle\Context\TenantContext;

/**
 * Sibling decorator for cache.app.taggable. Extends the base decorator so every
 * contract method on cache.app is inherited; adds invalidateTags() from the two
 * tag-aware interfaces.
 *
 * Parent's \$inner intersection (AdapterInterface&CacheInterface&...) is satisfied
 * because TagAwareAdapterInterface extends AdapterInterface and TagAwareCacheInterface
 * extends CacheInterface — so a TagAwareAdapterInterface&TagAwareCacheInterface value
 * is also an AdapterInterface&CacheInterface value.
 */
final class TenantAwareTagAwareCacheAdapter extends TenantAwareCacheAdapter implements TagAwareAdapterInterface, TagAwareCacheInterface
{
    public function __construct(
        TagAwareAdapterInterface&TagAwareCacheInterface&NamespacedPoolInterface&PruneableInterface&ResettableInterface $inner,
        TenantContext $tenantContext,
        string $cachePrefixSeparator = '.',
    ) {
        parent::__construct($inner, $tenantContext, $cachePrefixSeparator);
    }

    public function invalidateTags(array $tags): bool
    {
        /** @var TagAwareAdapterInterface&TagAwareCacheInterface&NamespacedPoolInterface&PruneableInterface&ResettableInterface $pool */
        $pool = $this->pool();

        return $pool->invalidateTags($tags);
    }
}
