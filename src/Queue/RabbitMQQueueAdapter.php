<?php

declare(strict_types=1);

namespace DeferQ\Queue;

use DeferQ\Exception\QueueConnectionException;
use DeferQ\Task\Task;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Message\AMQPMessage;

final class RabbitMQQueueAdapter implements QueueAdapterInterface
{
    /** @var array<string, int> Map of taskId => delivery tag */
    private array $deliveryTags = [];

    public function __construct(
        private readonly AMQPChannel $channel,
        private readonly string $queueName = 'deferq',
    ) {
        try {
            $this->channel->queue_declare(
                queue: $this->queueName,
                durable: true,
                auto_delete: false,
            );
        } catch (\Throwable $e) {
            throw QueueConnectionException::fromPrevious('Failed to declare RabbitMQ queue', $e);
        }
    }

    public function push(Task $task): void
    {
        try {
            $payload = json_encode($task->toArray(), JSON_THROW_ON_ERROR);
            $message = new AMQPMessage($payload, [
                'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
                'content_type' => 'application/json',
            ]);

            $this->channel->basic_publish($message, routing_key: $this->queueName);
        } catch (\Throwable $e) {
            throw QueueConnectionException::fromPrevious('Failed to publish to RabbitMQ', $e);
        }
    }

    public function pop(int $timeoutSeconds = 5): ?Task
    {
        try {
            $message = $this->channel->basic_get($this->queueName);

            if ($message === null) {
                return null;
            }

            /** @var array<string, mixed> $data */
            $data = json_decode($message->getBody(), true, 512, JSON_THROW_ON_ERROR);
            $task = Task::fromArray($data);

            $this->deliveryTags[$task->id] = $message->getDeliveryTag();

            return $task;
        } catch (\JsonException $e) {
            throw QueueConnectionException::fromPrevious('Failed to decode RabbitMQ message', $e);
        } catch (QueueConnectionException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw QueueConnectionException::fromPrevious('Failed to get message from RabbitMQ', $e);
        }
    }

    public function ack(Task $task): void
    {
        try {
            $deliveryTag = $this->deliveryTags[$task->id]
                ?? throw new \RuntimeException("No delivery tag for task {$task->id}");

            $this->channel->basic_ack($deliveryTag);
            unset($this->deliveryTags[$task->id]);
        } catch (\Throwable $e) {
            throw QueueConnectionException::fromPrevious('Failed to ack RabbitMQ message', $e);
        }
    }

    public function nack(Task $task): void
    {
        try {
            $deliveryTag = $this->deliveryTags[$task->id]
                ?? throw new \RuntimeException("No delivery tag for task {$task->id}");

            $this->channel->basic_nack($deliveryTag, requeue: true);
            unset($this->deliveryTags[$task->id]);
        } catch (\Throwable $e) {
            throw QueueConnectionException::fromPrevious('Failed to nack RabbitMQ message', $e);
        }
    }
}
