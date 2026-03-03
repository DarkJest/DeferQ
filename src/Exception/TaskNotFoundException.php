<?php

declare(strict_types=1);

namespace DeferQ\Exception;

final class TaskNotFoundException extends DeferQException
{
    public static function withId(string $taskId): self
    {
        return new self("Task not found: {$taskId}");
    }

    public static function withFingerprint(string $fingerprint): self
    {
        return new self("Task not found by fingerprint: {$fingerprint}");
    }
}
