<?php

declare(strict_types=1);

namespace DeferQ\Tests\Unit;

use DeferQ\Callback\CallbackInterface;
use DeferQ\Handler\TaskHandlerInterface;
use DeferQ\Handler\TaskHandlerRegistry;
use DeferQ\Queue\QueueAdapterInterface;
use DeferQ\Result\ResultStoreInterface;
use DeferQ\Store\TaskStoreInterface;
use DeferQ\Task\Task;
use DeferQ\Task\TaskStatus;
use DeferQ\Worker\Worker;
use DeferQ\Worker\WorkerConfig;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class WorkerTest extends TestCase
{
    private QueueAdapterInterface $queue;
    private TaskHandlerRegistry $handlers;
    private TaskStoreInterface $taskStore;
    private ResultStoreInterface $resultStore;

    protected function setUp(): void
    {
        $this->queue = $this->createMock(QueueAdapterInterface::class);
        $this->handlers = new TaskHandlerRegistry();
        $this->taskStore = $this->createMock(TaskStoreInterface::class);
        $this->resultStore = $this->createMock(ResultStoreInterface::class);
    }

    private function createWorker(int $maxJobs = 1): Worker
    {
        return new Worker(
            queue: $this->queue,
            handlers: $this->handlers,
            taskStore: $this->taskStore,
            resultStore: $this->resultStore,
            config: new WorkerConfig(
                sleepMs: 0,
                maxJobs: $maxJobs,
                maxMemoryMb: 512,
            ),
            logger: new NullLogger(),
        );
    }

    #[Test]
    public function processes_task_successfully(): void
    {
        $task = Task::create(
            name: 'report.generate',
            params: ['year' => 2024],
            fingerprint: 'fp_123',
        );

        $handler = $this->createMock(TaskHandlerInterface::class);
        $handler->expects(self::once())
            ->method('handle')
            ->with($task)
            ->willReturn(['report' => 'data']);

        $this->handlers->register('report.generate', $handler);

        $this->queue->method('pop')->willReturn($task);

        $this->taskStore->expects(self::exactly(2))
            ->method('updateStatus')
            ->willReturnCallback(function (string $taskId, TaskStatus $status) use ($task): void {
                static $call = 0;
                $call++;
                self::assertSame($task->id, $taskId);
                if ($call === 1) {
                    self::assertSame(TaskStatus::Running, $status);
                } else {
                    self::assertSame(TaskStatus::Completed, $status);
                }
            });

        $this->resultStore->expects(self::once())
            ->method('save')
            ->with('fp_123', ['report' => 'data'], 3600);

        $this->queue->expects(self::once())->method('ack')->with($task);

        $worker = $this->createWorker(maxJobs: 1);
        $worker->run();

        self::assertSame(1, $worker->getProcessedJobs());
    }

    #[Test]
    public function invokes_callback_after_completion(): void
    {
        $callbackInvoked = false;

        $callback = new class($callbackInvoked) implements CallbackInterface {
            public function __construct(private bool &$invoked) {}

            public function __invoke(Task $task, mixed $result): void
            {
                $this->invoked = true;
            }
        };

        $task = Task::create(
            name: 'test.task',
            params: [],
            fingerprint: 'fp_cb',
            callback: $callback,
        );

        $handler = $this->createMock(TaskHandlerInterface::class);
        $handler->method('handle')->willReturn('result');

        $this->handlers->register('test.task', $handler);

        $this->queue->method('pop')->willReturn($task);

        $worker = $this->createWorker(maxJobs: 1);
        $worker->run();

        self::assertTrue($callbackInvoked);
    }

    #[Test]
    public function handles_handler_failure_gracefully(): void
    {
        $task = Task::create(
            name: 'failing.task',
            params: [],
            fingerprint: 'fp_fail',
        );

        $handler = $this->createMock(TaskHandlerInterface::class);
        $handler->method('handle')->willThrowException(new \RuntimeException('Handler exploded'));

        $this->handlers->register('failing.task', $handler);

        $this->queue->method('pop')->willReturn($task);

        $statusUpdates = [];
        $this->taskStore->method('updateStatus')
            ->willReturnCallback(function (string $id, TaskStatus $status) use (&$statusUpdates): void {
                $statusUpdates[] = $status;
            });

        $this->queue->expects(self::once())->method('nack')->with($task);
        $this->queue->expects(self::never())->method('ack');

        $worker = $this->createWorker(maxJobs: 1);
        $worker->run();

        self::assertSame(TaskStatus::Running, $statusUpdates[0]);
        self::assertSame(TaskStatus::Failed, $statusUpdates[1]);
    }

    #[Test]
    public function nacks_task_when_no_handler_found(): void
    {
        $task = Task::create(
            name: 'unknown.task',
            params: [],
            fingerprint: 'fp_unknown',
        );

        $this->queue->method('pop')->willReturn($task);

        $this->taskStore->expects(self::atLeastOnce())
            ->method('updateStatus');

        $this->queue->expects(self::once())->method('nack')->with($task);
        $this->queue->expects(self::never())->method('ack');

        $worker = $this->createWorker(maxJobs: 1);
        $worker->run();
    }

    #[Test]
    public function stops_after_max_jobs(): void
    {
        $callCount = 0;

        $this->queue->method('pop')->willReturnCallback(function () use (&$callCount): Task {
            $callCount++;
            return Task::create(
                name: 'batch.task',
                params: ['i' => $callCount],
                fingerprint: 'fp_' . $callCount,
            );
        });

        $handler = $this->createMock(TaskHandlerInterface::class);
        $handler->method('handle')->willReturn('ok');

        $this->handlers->register('batch.task', $handler);

        $worker = $this->createWorker(maxJobs: 3);
        $worker->run();

        self::assertSame(3, $worker->getProcessedJobs());
    }

    #[Test]
    public function callback_failure_does_not_crash_worker(): void
    {
        $callback = new class implements CallbackInterface {
            public function __invoke(Task $task, mixed $result): void
            {
                throw new \RuntimeException('Callback exploded');
            }
        };

        $task = Task::create(
            name: 'cb.fail',
            params: [],
            fingerprint: 'fp_cbfail',
            callback: $callback,
        );

        $handler = $this->createMock(TaskHandlerInterface::class);
        $handler->method('handle')->willReturn('result');

        $this->handlers->register('cb.fail', $handler);

        $this->queue->method('pop')->willReturn($task);

        // Task should still be acked even though callback failed
        $this->queue->expects(self::once())->method('ack');

        $worker = $this->createWorker(maxJobs: 1);
        $worker->run();

        self::assertSame(1, $worker->getProcessedJobs());
    }
}
