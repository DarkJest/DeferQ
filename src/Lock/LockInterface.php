<?php

declare(strict_types=1);

namespace DeferQ\Lock;

interface LockInterface
{
    public function acquire(string $key, int $ttl = 10): bool;

    public function release(string $key): void;
}
