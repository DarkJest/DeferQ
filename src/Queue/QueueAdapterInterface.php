<?php

declare(strict_types=1);

namespace DeferQ\Queue;

use DeferQ\Task\Task;

interface QueueAdapterInterface
{
    public function push(Task $task): void;

    public function pop(int $timeoutSeconds = 5): ?Task;

    public function ack(Task $task): void;

    public function nack(Task $task): void;
}
