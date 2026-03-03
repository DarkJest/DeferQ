<?php

declare(strict_types=1);

namespace DeferQ\Lock;

use Psr\SimpleCache\CacheInterface;

/**
 * Best-effort lock implementation via PSR-16.
 *
 * PSR-16 does not guarantee atomic set-if-not-exists. This implementation uses
 * a get→set→get pattern to minimize race conditions. For production environments
 * with high concurrency, use a Redis-based lock (e.g., Redlock) instead.
 */
final class CacheLock implements LockInterface
{
    private readonly string $lockToken;

    public function __construct(
        private readonly CacheInterface $cache,
        private readonly string $prefix = 'deferq:lock:',
    ) {
        $this->lockToken = bin2hex(random_bytes(16));
    }

    public function acquire(string $key, int $ttl = 10): bool
    {
        $cacheKey = $this->prefix . $key;

        $existing = $this->cache->get($cacheKey);

        if ($existing !== null) {
            return false;
        }

        $this->cache->set($cacheKey, $this->lockToken, $ttl);

        $stored = $this->cache->get($cacheKey);

        return $stored === $this->lockToken;
    }

    public function release(string $key): void
    {
        $cacheKey = $this->prefix . $key;

        $stored = $this->cache->get($cacheKey);

        if ($stored === $this->lockToken) {
            $this->cache->delete($cacheKey);
        }
    }
}
