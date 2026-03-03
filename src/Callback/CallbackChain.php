<?php

declare(strict_types=1);

namespace DeferQ\Callback;

use DeferQ\Task\Task;

final class CallbackChain implements CallbackInterface
{
    /** @var list<CallbackInterface> */
    private array $callbacks;

    public function __construct(CallbackInterface ...$callbacks)
    {
        $this->callbacks = array_values($callbacks);
    }

    public function __invoke(Task $task, mixed $result): void
    {
        foreach ($this->callbacks as $callback) {
            ($callback)($task, $result);
        }
    }
}
