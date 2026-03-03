<?php

declare(strict_types=1);

namespace DeferQ\Worker;

use DeferQ\Exception\HandlerNotFoundException;
use DeferQ\Handler\TaskHandlerRegistry;
use DeferQ\Queue\QueueAdapterInterface;
use DeferQ\Result\ResultStoreInterface;
use DeferQ\Store\TaskStoreInterface;
use DeferQ\Task\Task;
use DeferQ\Task\TaskStatus;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class Worker
{
    private bool $shouldStop = false;
    private int $processedJobs = 0;

    public function __construct(
        private readonly QueueAdapterInterface $queue,
        private readonly TaskHandlerRegistry $handlers,
        private readonly TaskStoreInterface $taskStore,
        private readonly ResultStoreInterface $resultStore,
        private readonly WorkerConfig $config = new WorkerConfig(),
        private readonly ?LoggerInterface $logger = null,
    ) {}

    public function run(): void
    {
        $this->registerSignalHandlers();

        $logger = $this->logger ?? new NullLogger();
        $logger->info('DeferQ Worker started', [
            'maxJobs' => $this->config->maxJobs,
            'maxMemoryMb' => $this->config->maxMemoryMb,
        ]);

        while (!$this->shouldStop) {
            $task = $this->queue->pop(timeoutSeconds: 5);

            if ($task === null) {
                $this->sleep();
                $this->checkLimits($logger);
                continue;
            }

            $this->processTask($task, $logger);
            $this->processedJobs++;

            if ($this->checkLimits($logger)) {
                break;
            }
        }

        $logger->info('DeferQ Worker stopped', [
            'processedJobs' => $this->processedJobs,
        ]);
    }

    public function stop(): void
    {
        $this->shouldStop = true;
    }

    public function getProcessedJobs(): int
    {
        return $this->processedJobs;
    }

    private function processTask(Task $task, LoggerInterface $logger): void
    {
        $logger->info('Processing task', [
            'taskId' => $task->id,
            'name' => $task->name,
        ]);

        $this->taskStore->updateStatus($task->id, TaskStatus::Running);

        if (!$this->handlers->has($task->name)) {
            $logger->error('No handler found for task', ['name' => $task->name]);
            $this->taskStore->updateStatus($task->id, TaskStatus::Failed);
            $this->queue->nack($task);
            return;
        }

        try {
            $handler = $this->handlers->get($task->name);
            $result = $handler->handle($task);

            $this->resultStore->save($task->fingerprint, $result, $task->resultTtl);
            $this->taskStore->updateStatus($task->id, TaskStatus::Completed);

            $this->invokeCallback($task, $result, $logger);

            $this->queue->ack($task);

            $logger->info('Task completed', [
                'taskId' => $task->id,
                'name' => $task->name,
            ]);
        } catch (\Throwable $e) {
            $logger->error('Task failed', [
                'taskId' => $task->id,
                'name' => $task->name,
                'error' => $e->getMessage(),
            ]);

            $this->taskStore->updateStatus($task->id, TaskStatus::Failed);
            $this->queue->nack($task);
        }
    }

    private function invokeCallback(Task $task, mixed $result, LoggerInterface $logger): void
    {
        if ($task->callback === null) {
            return;
        }

        try {
            ($task->callback)($task, $result);
        } catch (\Throwable $e) {
            $logger->error('Callback failed', [
                'taskId' => $task->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function checkLimits(LoggerInterface $logger): bool
    {
        if ($this->config->maxJobs > 0 && $this->processedJobs >= $this->config->maxJobs) {
            $logger->info('Max jobs reached, stopping', [
                'maxJobs' => $this->config->maxJobs,
            ]);
            $this->shouldStop = true;
            return true;
        }

        $memoryUsageMb = memory_get_usage(true) / 1024 / 1024;

        if ($memoryUsageMb >= $this->config->maxMemoryMb) {
            $logger->info('Max memory reached, stopping', [
                'memoryMb' => round($memoryUsageMb, 2),
                'maxMemoryMb' => $this->config->maxMemoryMb,
            ]);
            $this->shouldStop = true;
            return true;
        }

        return false;
    }

    private function sleep(): void
    {
        usleep($this->config->sleepMs * 1000);
    }

    private function registerSignalHandlers(): void
    {
        if (!function_exists('pcntl_signal')) {
            return;
        }

        pcntl_signal(SIGTERM, function (): void {
            $this->shouldStop = true;
        });

        pcntl_signal(SIGINT, function (): void {
            $this->shouldStop = true;
        });
    }
}
