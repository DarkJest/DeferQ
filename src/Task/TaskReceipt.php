<?php

declare(strict_types=1);

namespace DeferQ\Task;

final class TaskReceipt
{
    public function __construct(
        public readonly string $taskId,
        public readonly string $fingerprint,
        public readonly TaskStatus $status,
        public readonly mixed $result = null,
    ) {}
}
