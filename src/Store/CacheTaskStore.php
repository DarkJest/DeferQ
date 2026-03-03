<?php

declare(strict_types=1);

namespace DeferQ\Store;

use DeferQ\Task\Task;
use DeferQ\Task\TaskStatus;
use Psr\SimpleCache\CacheInterface;

final class CacheTaskStore implements TaskStoreInterface
{
    private const int DEFAULT_TTL = 86400;
    private const int FINISHED_TTL = 3600;

    public function __construct(
        private readonly CacheInterface $cache,
        private readonly string $prefix = 'deferq:',
    ) {}

    public function save(Task $task, TaskStatus $status = TaskStatus::Pending): void
    {
        $data = [
            'task' => $task->toArray(),
            'status' => $status->value,
        ];

        $this->cache->set(
            $this->taskKey($task->id),
            serialize($data),
            self::DEFAULT_TTL,
        );

        $this->cache->set(
            $this->fingerprintKey($task->fingerprint),
            $task->id,
            self::DEFAULT_TTL,
        );
    }

    public function find(string $taskId): ?Task
    {
        $raw = $this->cache->get($this->taskKey($taskId));

        if ($raw === null) {
            return null;
        }

        /** @var array{task: array<string, mixed>, status: string} $data */
        $data = unserialize($raw);

        return Task::fromArray($data['task']);
    }

    public function findByFingerprint(string $fingerprint): ?Task
    {
        $taskId = $this->cache->get($this->fingerprintKey($fingerprint));

        if ($taskId === null) {
            return null;
        }

        return $this->find($taskId);
    }

    public function getStatus(string $taskId): ?TaskStatus
    {
        $raw = $this->cache->get($this->taskKey($taskId));

        if ($raw === null) {
            return null;
        }

        /** @var array{task: array<string, mixed>, status: string} $data */
        $data = unserialize($raw);

        return TaskStatus::from($data['status']);
    }

    public function updateStatus(string $taskId, TaskStatus $status): void
    {
        $raw = $this->cache->get($this->taskKey($taskId));

        if ($raw === null) {
            return;
        }

        /** @var array{task: array<string, mixed>, status: string} $data */
        $data = unserialize($raw);
        $data['status'] = $status->value;

        $ttl = $status->isFinished() ? self::FINISHED_TTL : self::DEFAULT_TTL;

        $this->cache->set(
            $this->taskKey($taskId),
            serialize($data),
            $ttl,
        );

        if ($status->isFinished()) {
            $fingerprint = $data['task']['fingerprint'];
            $this->cache->set(
                $this->fingerprintKey($fingerprint),
                $taskId,
                $ttl,
            );
        }
    }

    private function taskKey(string $taskId): string
    {
        return $this->prefix . 'task:' . $taskId;
    }

    private function fingerprintKey(string $fingerprint): string
    {
        return $this->prefix . 'fp:' . $fingerprint;
    }
}
