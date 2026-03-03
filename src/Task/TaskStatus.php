<?php

declare(strict_types=1);

namespace DeferQ\Task;

enum TaskStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';

    public function isFinished(): bool
    {
        return $this === self::Completed || $this === self::Failed;
    }
}
