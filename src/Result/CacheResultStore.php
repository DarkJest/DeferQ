<?php

declare(strict_types=1);

namespace DeferQ\Result;

use Psr\SimpleCache\CacheInterface;

final class CacheResultStore implements ResultStoreInterface
{
    public function __construct(
        private readonly CacheInterface $cache,
        private readonly string $prefix = 'deferq:result:',
    ) {}

    public function save(string $fingerprint, mixed $result, int $ttl = 3600): void
    {
        $this->cache->set(
            $this->key($fingerprint),
            serialize($result),
            $ttl,
        );
    }

    public function get(string $fingerprint): mixed
    {
        $raw = $this->cache->get($this->key($fingerprint));

        if ($raw === null) {
            return null;
        }

        return unserialize($raw);
    }

    public function has(string $fingerprint): bool
    {
        return $this->cache->has($this->key($fingerprint));
    }

    private function key(string $fingerprint): string
    {
        return $this->prefix . $fingerprint;
    }
}
