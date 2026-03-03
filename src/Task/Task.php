<?php

declare(strict_types=1);

namespace DeferQ\Task;

use DeferQ\Callback\CallbackInterface;
use Ramsey\Uuid\Uuid;

final class Task
{
    /**
     * @param array<string, mixed> $params
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly array $params,
        public readonly string $fingerprint,
        public readonly \DateTimeImmutable $createdAt,
        public readonly ?CallbackInterface $callback = null,
        public readonly int $resultTtl = 3600,
    ) {}

    /**
     * @param array<string, mixed> $params
     */
    public static function create(
        string $name,
        array $params,
        string $fingerprint,
        ?CallbackInterface $callback = null,
        int $resultTtl = 3600,
    ): self {
        return new self(
            id: Uuid::uuid7()->toString(),
            name: $name,
            params: $params,
            fingerprint: $fingerprint,
            createdAt: new \DateTimeImmutable(),
            callback: $callback,
            resultTtl: $resultTtl,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'params' => $this->params,
            'fingerprint' => $this->fingerprint,
            'createdAt' => $this->createdAt->format(\DateTimeInterface::RFC3339_EXTENDED),
            'callback' => $this->callback !== null ? serialize($this->callback) : null,
            'resultTtl' => $this->resultTtl,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: $data['id'],
            name: $data['name'],
            params: $data['params'],
            fingerprint: $data['fingerprint'],
            createdAt: new \DateTimeImmutable($data['createdAt']),
            callback: isset($data['callback']) ? unserialize($data['callback']) : null,
            resultTtl: $data['resultTtl'] ?? 3600,
        );
    }
}
