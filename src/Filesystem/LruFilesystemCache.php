<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Filesystem;

use League\Flysystem\FilesystemOperator;

/**
 * Bounded LRU cache for per-tenant FilesystemOperator instances.
 *
 * Mirrors the Phase 20 src/Mailer/LruTransportCache shape — substituting
 * FilesystemOperator for TransportInterface and close() for stop(). Default
 * maxSize=32 is calibrated symmetrically with the Mailer cache.
 *
 * On eviction (when size would exceed maxSize) or full clear
 * (TenantContextCleared event), the evicted operator's `close()` method is
 * invoked when available — method_exists() guard is forward-compat for
 * user-supplied adapters that hold socket resources (S3 SDK clients, FTP
 * connections, etc.). For Local / InMemory / S3 adapters from
 * league/flysystem 3.x this is a no-op today.
 *
 * Pure PHP — no Symfony deps beyond the FilesystemOperator import. Stateful
 * holder mirroring TenantContext's design pattern (final class, private
 * state). Load this class only when league/flysystem-bundle is installed;
 * runtime registration in Plan 24-07 sits behind interface_exists().
 *
 * @see .planning/phases/24-filesystem-bootstrapper/24-RESEARCH.md §Pattern 2
 * @see .planning/phases/24-filesystem-bootstrapper/24-RESEARCH.md §Assumption A4
 * @see .planning/phases/24-filesystem-bootstrapper/24-CONTEXT.md §DEC-FILE-MODE
 */
final class LruFilesystemCache
{
    /** @var array<string, FilesystemOperator> slug → operator in LRU order (most-recent last) */
    private array $cache = [];

    private int $hits = 0;

    private int $evictions = 0;

    public function __construct(private readonly int $maxSize = 32)
    {
    }

    public function get(string $slug): ?FilesystemOperator
    {
        if (!isset($this->cache[$slug])) {
            return null;
        }

        $operator = $this->cache[$slug];
        // Move-to-end: re-insert to bump LRU order.
        unset($this->cache[$slug]);
        $this->cache[$slug] = $operator;
        ++$this->hits;

        return $operator;
    }

    public function set(string $slug, FilesystemOperator $fs): void
    {
        if (isset($this->cache[$slug])) {
            // Update in place — drop the old position so the new one lands at the end.
            unset($this->cache[$slug]);
        } elseif (count($this->cache) >= $this->maxSize) {
            $lruSlug = array_key_first($this->cache);
            if (null !== $lruSlug) {
                $this->closeOperator($this->cache[$lruSlug]);
                unset($this->cache[$lruSlug]);
                ++$this->evictions;
            }
        }

        $this->cache[$slug] = $fs;
    }

    public function clear(): void
    {
        foreach ($this->cache as $operator) {
            $this->closeOperator($operator);
        }
        $this->cache = [];
    }

    public function size(): int
    {
        return count($this->cache);
    }

    public function maxSize(): int
    {
        return $this->maxSize;
    }

    public function hits(): int
    {
        return $this->hits;
    }

    public function evictions(): int
    {
        return $this->evictions;
    }

    private function closeOperator(FilesystemOperator $fs): void
    {
        if (method_exists($fs, 'close')) {
            $fs->close();
        }
    }
}
