<?php

declare(strict_types=1);

namespace DeferQ\Worker;

final class WorkerConfig
{
    public function __construct(
        public readonly int $sleepMs = 1000,
        public readonly int $maxJobs = 0,
        public readonly int $maxMemoryMb = 128,
        public readonly int $taskTimeoutSeconds = 300,
    ) {}
}
