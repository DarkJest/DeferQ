<?php

declare(strict_types=1);

namespace DeferQ\Fingerprint;

final class DefaultFingerprintGenerator implements FingerprintGeneratorInterface
{
    /**
     * @param array<string, mixed> $params
     */
    public function generate(string $name, array $params): string
    {
        $this->sortRecursive($params);

        $canonical = json_encode([
            'name' => $name,
            'params' => $params,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return hash('sha256', $canonical);
    }

    /**
     * @param array<string, mixed> $array
     */
    private function sortRecursive(array &$array): void
    {
        ksort($array);

        foreach ($array as &$value) {
            if (is_array($value)) {
                $this->sortRecursive($value);
            }
        }
    }
}
