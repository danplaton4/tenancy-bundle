<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Cache;

use Psr\Cache\CacheItemInterface;
use Symfony\Component\Cache\Adapter\AdapterInterface;
use Symfony\Component\Cache\CacheItem;
use Symfony\Component\Cache\PruneableInterface;
use Symfony\Component\Cache\ResettableInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\NamespacedPoolInterface;
use Tenancy\Bundle\Context\TenantContext;

class TenantAwareCacheAdapter implements AdapterInterface, CacheInterface, NamespacedPoolInterface, PruneableInterface, ResettableInterface
{
    public function __construct(
        protected AdapterInterface&CacheInterface&NamespacedPoolInterface&PruneableInterface&ResettableInterface $inner,
        protected readonly TenantContext $tenantContext,
        protected readonly string $cachePrefixSeparator = '.',
    ) {
    }

    protected function pool(): AdapterInterface&CacheInterface&NamespacedPoolInterface&PruneableInterface&ResettableInterface
    {
        $tenant = $this->tenantContext->getTenant();
        if (null !== $tenant) {
            return $this->inner->withSubNamespace($tenant->getSlug().$this->cachePrefixSeparator);
        }

        return $this->inner;
    }

    public function getItem(mixed $key): CacheItem
    {
        return $this->pool()->getItem($key);
    }

    /** @return iterable<string, CacheItem> */
    public function getItems(array $keys = []): iterable
    {
        return $this->pool()->getItems($keys);
    }

    public function hasItem(mixed $key): bool
    {
        return $this->pool()->hasItem($key);
    }

    public function clear(string $prefix = ''): bool
    {
        return $this->pool()->clear($prefix);
    }

    public function deleteItem(mixed $key): bool
    {
        return $this->pool()->deleteItem($key);
    }

    public function deleteItems(array $keys): bool
    {
        return $this->pool()->deleteItems($keys);
    }

    public function save(CacheItemInterface $item): bool
    {
        return $this->pool()->save($item);
    }

    public function saveDeferred(CacheItemInterface $item): bool
    {
        return $this->pool()->saveDeferred($item);
    }

    public function commit(): bool
    {
        return $this->pool()->commit();
    }

    /**
     * @param array<array-key, mixed>|null $metadata
     */
    public function get(string $key, callable $callback, ?float $beta = null, ?array &$metadata = null): mixed
    {
        return $this->pool()->get($key, $callback, $beta, $metadata);
    }

    public function delete(string $key): bool
    {
        return $this->pool()->delete($key);
    }

    public function prune(): bool
    {
        return $this->inner->prune();
    }

    public function reset(): void
    {
        $this->inner->reset();
    }

    public function withSubNamespace(string $namespace): static
    {
        $clone = clone $this;
        $clone->inner = $this->inner->withSubNamespace($namespace);

        return $clone;
    }
}
