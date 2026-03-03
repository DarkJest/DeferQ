<?php

declare(strict_types=1);

namespace DeferQ\Store;

use DeferQ\Task\Task;
use DeferQ\Task\TaskStatus;

interface TaskStoreInterface
{
    public function save(Task $task, TaskStatus $status = TaskStatus::Pending): void;

    public function find(string $taskId): ?Task;

    public function findByFingerprint(string $fingerprint): ?Task;

    public function getStatus(string $taskId): ?TaskStatus;

    public function updateStatus(string $taskId, TaskStatus $status): void;
}
