<?php

declare(strict_types=1);

namespace DeferQ\Exception;

final class HandlerNotFoundException extends DeferQException
{
    public static function forTask(string $taskName): self
    {
        return new self("No handler registered for task: {$taskName}");
    }
}
