<?php

declare(strict_types=1);

namespace DeferQ\Handler;

use DeferQ\Task\Task;

interface TaskHandlerInterface
{
    public function handle(Task $task): mixed;
}
