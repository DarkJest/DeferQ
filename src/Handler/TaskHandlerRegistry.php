<?php

declare(strict_types=1);

namespace DeferQ\Handler;

use DeferQ\Exception\HandlerNotFoundException;

final class TaskHandlerRegistry
{
    /** @var array<string, TaskHandlerInterface> */
    private array $handlers = [];

    public function register(string $taskName, TaskHandlerInterface $handler): void
    {
        $this->handlers[$taskName] = $handler;
    }

    public function get(string $taskName): TaskHandlerInterface
    {
        return $this->handlers[$taskName]
            ?? throw HandlerNotFoundException::forTask($taskName);
    }

    public function has(string $taskName): bool
    {
        return isset($this->handlers[$taskName]);
    }
}
