<?php

declare(strict_types=1);

namespace DeferQ\Tests\Unit;

use DeferQ\Callback\CallbackInterface;
use DeferQ\DeferQ;
use DeferQ\Exception\TaskNotFoundException;
use DeferQ\Fingerprint\FingerprintGeneratorInterface;
use DeferQ\Lock\LockInterface;
use DeferQ\Queue\QueueAdapterInterface;
use DeferQ\Result\ResultStoreInterface;
use DeferQ\Store\TaskStoreInterface;
use DeferQ\Task\Task;
use DeferQ\Task\TaskStatus;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DeferQTest extends TestCase
{
    private QueueAdapterInterface $queue;
    private TaskStoreInterface $taskStore;
    private ResultStoreInterface $resultStore;
    private FingerprintGeneratorInterface $fingerprinter;
    private LockInterface $lock;
    private DeferQ $deferq;

    protected function setUp(): void
    {
        $this->queue = $this->createMock(QueueAdapterInterface::class);
        $this->taskStore = $this->createMock(TaskStoreInterface::class);
        $this->resultStore = $this->createMock(ResultStoreInterface::class);
        $this->fingerprinter = $this->createMock(FingerprintGeneratorInterface::class);
        $this->lock = $this->createMock(LockInterface::class);

        $this->fingerprinter->method('generate')->willReturn('fp_test_123');
        $this->lock->method('acquire')->willReturn(true);

        $this->deferq = new DeferQ(
            queue: $this->queue,
            taskStore: $this->taskStore,
            resultStore: $this->resultStore,
            lock: $this->lock,
            fingerprinter: $this->fingerprinter,
        );
    }

    #[Test]
    public function dispatch_returns_completed_when_result_cached(): void
    {
        $this->resultStore->method('has')->with('fp_test_123')->willReturn(true);
        $this->resultStore->method('get')->with('fp_test_123')->willReturn(['data' => 'report']);
        $this->taskStore->method('findByFingerprint')->willReturn(null);

        $this->queue->expects(self::never())->method('push');

        $receipt = $this->deferq->dispatch(
            name: 'report.generate',
            params: ['year' => 2024],
        );

        self::assertSame(TaskStatus::Completed, $receipt->status);
        self::assertSame(['data' => 'report'], $receipt->result);
        self::assertSame('fp_test_123', $receipt->fingerprint);
    }

    #[Test]
    public function dispatch_deduplicates_running_task(): void
    {
        $existingTask = Task::create(
            name: 'report.generate',
            params: ['year' => 2024],
            fingerprint: 'fp_test_123',
        );

        $this->resultStore->method('has')->willReturn(false);
        $this->taskStore->method('findByFingerprint')
            ->with('fp_test_123')
            ->willReturn($existingTask);
        $this->taskStore->method('getStatus')
            ->with($existingTask->id)
            ->willReturn(TaskStatus::Running);

        $this->queue->expects(self::never())->method('push');

        $receipt = $this->deferq->dispatch(
            name: 'report.generate',
            params: ['year' => 2024],
        );

        self::assertSame(TaskStatus::Running, $receipt->status);
        self::assertSame($existingTask->id, $receipt->taskId);
    }

    #[Test]
    public function dispatch_deduplicates_pending_task(): void
    {
        $existingTask = Task::create(
            name: 'report.generate',
            params: ['year' => 2024],
            fingerprint: 'fp_test_123',
        );

        $this->resultStore->method('has')->willReturn(false);
        $this->taskStore->method('findByFingerprint')
            ->with('fp_test_123')
            ->willReturn($existingTask);
        $this->taskStore->method('getStatus')
            ->with($existingTask->id)
            ->willReturn(TaskStatus::Pending);

        $this->queue->expects(self::never())->method('push');

        $receipt = $this->deferq->dispatch(
            name: 'report.generate',
            params: ['year' => 2024],
        );

        self::assertSame(TaskStatus::Pending, $receipt->status);
        self::assertSame($existingTask->id, $receipt->taskId);
    }

    #[Test]
    public function dispatch_creates_new_task_when_no_cache_or_duplicate(): void
    {
        $this->resultStore->method('has')->willReturn(false);
        $this->taskStore->method('findByFingerprint')->willReturn(null);

        $this->taskStore->expects(self::once())->method('save');
        $this->queue->expects(self::once())->method('push');

        $receipt = $this->deferq->dispatch(
            name: 'report.generate',
            params: ['year' => 2024],
        );

        self::assertSame(TaskStatus::Pending, $receipt->status);
        self::assertSame('fp_test_123', $receipt->fingerprint);
        self::assertNotEmpty($receipt->taskId);
    }

    #[Test]
    public function dispatch_releases_lock_even_on_failure(): void
    {
        $this->resultStore->method('has')->willReturn(false);
        $this->taskStore->method('findByFingerprint')->willReturn(null);
        $this->taskStore->method('save')->willThrowException(new \RuntimeException('Store error'));

        $this->lock->expects(self::once())->method('release');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Store error');

        $this->deferq->dispatch(name: 'report.generate', params: []);
    }

    #[Test]
    public function get_status_returns_receipt_for_existing_task(): void
    {
        $task = Task::create(
            name: 'test',
            params: [],
            fingerprint: 'fp_abc',
        );

        $this->taskStore->method('find')->with($task->id)->willReturn($task);
        $this->taskStore->method('getStatus')->with($task->id)->willReturn(TaskStatus::Running);

        $receipt = $this->deferq->getStatus($task->id);

        self::assertSame($task->id, $receipt->taskId);
        self::assertSame(TaskStatus::Running, $receipt->status);
        self::assertNull($receipt->result);
    }

    #[Test]
    public function get_status_includes_result_when_completed(): void
    {
        $task = Task::create(
            name: 'test',
            params: [],
            fingerprint: 'fp_abc',
        );

        $this->taskStore->method('find')->with($task->id)->willReturn($task);
        $this->taskStore->method('getStatus')->with($task->id)->willReturn(TaskStatus::Completed);
        $this->resultStore->method('get')->with('fp_abc')->willReturn('done');

        $receipt = $this->deferq->getStatus($task->id);

        self::assertSame(TaskStatus::Completed, $receipt->status);
        self::assertSame('done', $receipt->result);
    }

    #[Test]
    public function get_status_throws_when_task_not_found(): void
    {
        $this->taskStore->method('find')->willReturn(null);

        $this->expectException(TaskNotFoundException::class);

        $this->deferq->getStatus('nonexistent-id');
    }

    #[Test]
    public function get_result_returns_cached_result(): void
    {
        $task = Task::create(
            name: 'test',
            params: [],
            fingerprint: 'fp_abc',
        );

        $this->taskStore->method('find')->with($task->id)->willReturn($task);
        $this->resultStore->method('get')->with('fp_abc')->willReturn(['data' => 42]);

        $result = $this->deferq->getResult($task->id);

        self::assertSame(['data' => 42], $result);
    }

    #[Test]
    public function get_result_throws_when_task_not_found(): void
    {
        $this->taskStore->method('find')->willReturn(null);

        $this->expectException(TaskNotFoundException::class);

        $this->deferq->getResult('missing');
    }
}
