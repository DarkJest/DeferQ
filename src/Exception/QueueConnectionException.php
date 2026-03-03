<?php

declare(strict_types=1);

namespace DeferQ\Exception;

final class QueueConnectionException extends DeferQException
{
    public static function fromPrevious(string $message, \Throwable $previous): self
    {
        return new self("Queue connection error: {$message}", previous: $previous);
    }
}
