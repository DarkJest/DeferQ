<?php

declare(strict_types=1);

namespace DeferQ\Result;

interface ResultStoreInterface
{
    public function save(string $fingerprint, mixed $result, int $ttl = 3600): void;

    public function get(string $fingerprint): mixed;

    public function has(string $fingerprint): bool;
}
