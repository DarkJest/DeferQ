<?php

declare(strict_types=1);

namespace DeferQ;

use DeferQ\Fingerprint\DefaultFingerprintGenerator;
use DeferQ\Fingerprint\FingerprintGeneratorInterface;
use DeferQ\Lock\LockInterface;
use DeferQ\Queue\QueueAdapterInterface;
use DeferQ\Result\ResultStoreInterface;
use DeferQ\Store\TaskStoreInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class DeferQConfig
{
    public function __construct(
        public readonly QueueAdapterInterface $queue,
        public readonly TaskStoreInterface $taskStore,
        public readonly ResultStoreInterface $resultStore,
        public readonly LockInterface $lock,
        public readonly FingerprintGeneratorInterface $fingerprinter = new DefaultFingerprintGenerator(),
        public readonly LoggerInterface $logger = new NullLogger(),
        public readonly int $defaultResultTtl = 3600,
        public readonly int $lockTtl = 10,
    ) {}
}
