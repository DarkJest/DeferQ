<?php

declare(strict_types=1);

namespace DeferQ\Queue;

use DeferQ\Exception\QueueConnectionException;
use DeferQ\Task\Task;
use Predis\ClientInterface;

final class RedisQueueAdapter implements QueueAdapterInterface
{
    private readonly string $queueKey;
    private readonly string $processingKey;

    public function __construct(
        private readonly ClientInterface $redis,
        string $queueName = 'default',
    ) {
        $this->queueKey = "deferq:queue:{$queueName}";
        $this->processingKey = "deferq:processing:{$queueName}";
    }

    public function push(Task $task): void
    {
        try {
            $payload = json_encode($task->toArray(), JSON_THROW_ON_ERROR);
            $this->redis->lpush($this->queueKey, [$payload]);
        } catch (\Throwable $e) {
            throw QueueConnectionException::fromPrevious('Failed to push task to Redis', $e);
        }
    }

    public function pop(int $timeoutSeconds = 5): ?Task
    {
        try {
            $result = $this->redis->brpoplpush($this->queueKey, $this->processingKey, $timeoutSeconds);

            if ($result === null) {
                return null;
            }

            /** @var array<string, mixed> $data */
            $data = json_decode($result, true, 512, JSON_THROW_ON_ERROR);

            return Task::fromArray($data);
        } catch (\JsonException $e) {
            throw QueueConnectionException::fromPrevious('Failed to decode task from Redis', $e);
        } catch (QueueConnectionException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw QueueConnectionException::fromPrevious('Failed to pop task from Redis', $e);
        }
    }

    public function ack(Task $task): void
    {
        try {
            $payload = json_encode($task->toArray(), JSON_THROW_ON_ERROR);
            $this->redis->lrem($this->processingKey, 1, $payload);
        } catch (\Throwable $e) {
            throw QueueConnectionException::fromPrevious('Failed to ack task in Redis', $e);
        }
    }

    public function nack(Task $task): void
    {
        try {
            $payload = json_encode($task->toArray(), JSON_THROW_ON_ERROR);
            $this->redis->lrem($this->processingKey, 1, $payload);
            $this->redis->rpush($this->queueKey, [$payload]);
        } catch (\Throwable $e) {
            throw QueueConnectionException::fromPrevious('Failed to nack task in Redis', $e);
        }
    }
}
