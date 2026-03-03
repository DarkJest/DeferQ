<?php

declare(strict_types=1);

namespace DeferQ;

use DeferQ\Callback\CallbackInterface;
use DeferQ\Exception\TaskNotFoundException;
use DeferQ\Fingerprint\DefaultFingerprintGenerator;
use DeferQ\Fingerprint\FingerprintGeneratorInterface;
use DeferQ\Lock\LockInterface;
use DeferQ\Queue\QueueAdapterInterface;
use DeferQ\Result\ResultStoreInterface;
use DeferQ\Store\TaskStoreInterface;
use DeferQ\Task\Task;
use DeferQ\Task\TaskReceipt;
use DeferQ\Task\TaskStatus;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class DeferQ
{
    private readonly FingerprintGeneratorInterface $fingerprinter;
    private readonly LoggerInterface $logger;
    private readonly int $defaultResultTtl;
    private readonly int $lockTtl;

    public function __construct(
        private readonly QueueAdapterInterface $queue,
        private readonly TaskStoreInterface $taskStore,
        private readonly ResultStoreInterface $resultStore,
        private readonly LockInterface $lock,
        ?FingerprintGeneratorInterface $fingerprinter = null,
        ?LoggerInterface $logger = null,
        int $defaultResultTtl = 3600,
        int $lockTtl = 10,
    ) {
        $this->fingerprinter = $fingerprinter ?? new DefaultFingerprintGenerator();
        $this->logger = $logger ?? new NullLogger();
        $this->defaultResultTtl = $defaultResultTtl;
        $this->lockTtl = $lockTtl;
    }

    /**
     * @param array<string, mixed> $params
     */
    public function dispatch(
        string $name,
        array $params = [],
        ?CallbackInterface $callback = null,
        ?int $resultTtl = null,
    ): TaskReceipt {
        $resultTtl ??= $this->defaultResultTtl;
        $fingerprint = $this->fingerprinter->generate($name, $params);

        // 1. Check result cache
        if ($this->resultStore->has($fingerprint)) {
            $result = $this->resultStore->get($fingerprint);

            $this->logger->debug('DeferQ: returning cached result', [
                'name' => $name,
                'fingerprint' => $fingerprint,
            ]);

            // Find existing task or generate a synthetic ID
            $existingTask = $this->taskStore->findByFingerprint($fingerprint);
            $taskId = $existingTask?->id ?? $fingerprint;

            return new TaskReceipt(
                taskId: $taskId,
                fingerprint: $fingerprint,
                status: TaskStatus::Completed,
                result: $result,
            );
        }

        // 2. Deduplication: check for running/pending task with same fingerprint
        $existingTask = $this->taskStore->findByFingerprint($fingerprint);

        if ($existingTask !== null) {
            $status = $this->taskStore->getStatus($existingTask->id);

            if ($status !== null && !$status->isFinished()) {
                $this->logger->debug('DeferQ: deduplicating task', [
                    'name' => $name,
                    'fingerprint' => $fingerprint,
                    'existingTaskId' => $existingTask->id,
                ]);

                return new TaskReceipt(
                    taskId: $existingTask->id,
                    fingerprint: $fingerprint,
                    status: $status,
                );
            }
        }

        // 3. Acquire lock for dispatch atomicity
        $lockKey = "dispatch:{$fingerprint}";

        if (!$this->lock->acquire($lockKey, $this->lockTtl)) {
            // Another process is dispatching the same task — re-check store
            $existingTask = $this->taskStore->findByFingerprint($fingerprint);

            if ($existingTask !== null) {
                $status = $this->taskStore->getStatus($existingTask->id) ?? TaskStatus::Pending;

                return new TaskReceipt(
                    taskId: $existingTask->id,
                    fingerprint: $fingerprint,
                    status: $status,
                );
            }
        }

        try {
            // 4. Double-check after lock acquisition
            $existingTask = $this->taskStore->findByFingerprint($fingerprint);

            if ($existingTask !== null) {
                $status = $this->taskStore->getStatus($existingTask->id);

                if ($status !== null && !$status->isFinished()) {
                    return new TaskReceipt(
                        taskId: $existingTask->id,
                        fingerprint: $fingerprint,
                        status: $status,
                    );
                }
            }

            // 5. Create and enqueue new task
            $task = Task::create(
                name: $name,
                params: $params,
                fingerprint: $fingerprint,
                callback: $callback,
                resultTtl: $resultTtl,
            );

            $this->taskStore->save($task);
            $this->queue->push($task);

            $this->logger->info('DeferQ: dispatched new task', [
                'taskId' => $task->id,
                'name' => $name,
                'fingerprint' => $fingerprint,
            ]);

            return new TaskReceipt(
                taskId: $task->id,
                fingerprint: $fingerprint,
                status: TaskStatus::Pending,
            );
        } finally {
            $this->lock->release($lockKey);
        }
    }

    public function getStatus(string $taskId): TaskReceipt
    {
        $task = $this->taskStore->find($taskId)
            ?? throw TaskNotFoundException::withId($taskId);

        $status = $this->taskStore->getStatus($taskId) ?? TaskStatus::Pending;
        $result = null;

        if ($status === TaskStatus::Completed) {
            $result = $this->resultStore->get($task->fingerprint);
        }

        return new TaskReceipt(
            taskId: $task->id,
            fingerprint: $task->fingerprint,
            status: $status,
            result: $result,
        );
    }

    public function getResult(string $taskId): mixed
    {
        $task = $this->taskStore->find($taskId)
            ?? throw TaskNotFoundException::withId($taskId);

        return $this->resultStore->get($task->fingerprint);
    }
}
