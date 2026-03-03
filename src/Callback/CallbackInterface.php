<?php

declare(strict_types=1);

namespace DeferQ\Callback;

use DeferQ\Task\Task;

interface CallbackInterface
{
    public function __invoke(Task $task, mixed $result): void;
}
