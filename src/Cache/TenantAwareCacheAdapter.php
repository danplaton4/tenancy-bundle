<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Cache;

use Psr\Cache\CacheItemInterface;
use Symfony\Component\Cache\Adapter\AdapterInterface;
use Symfony\Component\Cache\CacheItem;
use Symfony\Contracts\Cache\NamespacedPoolInterface;
use Tenancy\Bundle\Context\TenantContext;

final class TenantAwareCacheAdapter implements AdapterInterface, NamespacedPoolInterface
{
    public function __construct(
        private AdapterInterface&NamespacedPoolInterface $inner,
        private readonly TenantContext $tenantContext,
    ) {
    }

    private function pool(): AdapterInterface&NamespacedPoolInterface
    {
        if ($this->tenantContext->hasTenant()) {
            return $this->inner->withSubNamespace(
                $this->tenantContext->getTenant()->getSlug()
            );
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

    public function withSubNamespace(string $namespace): static
    {
        $clone = clone $this;
        $clone->inner = $this->inner->withSubNamespace($namespace);

        return $clone;
    }
}
