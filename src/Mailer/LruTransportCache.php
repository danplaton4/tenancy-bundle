<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Mailer;

use Symfony\Component\Mailer\Transport\TransportInterface;

/**
 * Bounded LRU cache for per-tenant TransportInterface instances.
 *
 * On eviction (when size would exceed maxSize) or full clear
 * (TenantContextCleared event), the evicted transport's `stop()` method is
 * invoked when available — closes the underlying SMTP socket cleanly.
 * Prevents socket exhaustion in long-running workers (roadmap success
 * criterion 6).
 *
 * Pure PHP — no Symfony deps beyond the TransportInterface import. Stateful
 * holder mirroring TenantContext's design pattern (final class, private state).
 *
 * @see .planning/phases/20-mailer-bootstrapper/20-CONTEXT.md D-03 (default size 32)
 */
final class LruTransportCache
{
    /** @var array<string, TransportInterface> slug → transport in LRU order (most-recent last) */
    private array $cache = [];

    private int $hits = 0;

    private int $evictions = 0;

    public function __construct(private readonly int $maxSize = 32)
    {
    }

    public function get(string $slug): ?TransportInterface
    {
        if (!isset($this->cache[$slug])) {
            return null;
        }

        $transport = $this->cache[$slug];
        // Move-to-end: re-insert to bump LRU order.
        unset($this->cache[$slug]);
        $this->cache[$slug] = $transport;
        ++$this->hits;

        return $transport;
    }

    public function set(string $slug, TransportInterface $transport): void
    {
        if (isset($this->cache[$slug])) {
            // Update in place — drop the old position so the new one lands at the end.
            unset($this->cache[$slug]);
        } elseif (count($this->cache) >= $this->maxSize) {
            $lruSlug = array_key_first($this->cache);
            if (null !== $lruSlug) {
                $this->stopTransport($this->cache[$lruSlug]);
                unset($this->cache[$lruSlug]);
                ++$this->evictions;
            }
        }

        $this->cache[$slug] = $transport;
    }

    public function clear(): void
    {
        foreach ($this->cache as $transport) {
            $this->stopTransport($transport);
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

    private function stopTransport(TransportInterface $transport): void
    {
        if (method_exists($transport, 'stop')) {
            $transport->stop();
        }
    }
}
