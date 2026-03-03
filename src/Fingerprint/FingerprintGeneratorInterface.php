<?php

declare(strict_types=1);

namespace DeferQ\Fingerprint;

interface FingerprintGeneratorInterface
{
    /**
     * @param array<string, mixed> $params
     */
    public function generate(string $name, array $params): string;
}
