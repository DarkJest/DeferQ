<?php

declare(strict_types=1);

namespace DeferQ\Tests\Unit;

use DeferQ\Task\Task;
use DeferQ\Task\TaskStatus;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TaskTest extends TestCase
{
    #[Test]
    public function create_generates_valid_task(): void
    {
        $task = Task::create(
            name: 'report.generate',
            params: ['year' => 2024],
            fingerprint: 'abc123',
        );

        self::assertNotEmpty($task->id);
        self::assertSame('report.generate', $task->name);
        self::assertSame(['year' => 2024], $task->params);
        self::assertSame('abc123', $task->fingerprint);
        self::assertSame(3600, $task->resultTtl);
        self::assertNull($task->callback);
        self::assertInstanceOf(\DateTimeImmutable::class, $task->createdAt);
    }

    #[Test]
    public function create_generates_uuid_v7_format(): void
    {
        $task = Task::create(
            name: 'test',
            params: [],
            fingerprint: 'fp',
        );

        // UUIDv7 format: xxxxxxxx-xxxx-7xxx-xxxx-xxxxxxxxxxxx
        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $task->id,
        );
    }

    #[Test]
    public function to_array_and_from_array_roundtrip(): void
    {
        $task = Task::create(
            name: 'export.csv',
            params: ['filter' => 'active', 'limit' => 100],
            fingerprint: 'fp_abc',
            resultTtl: 7200,
        );

        $array = $task->toArray();
        $restored = Task::fromArray($array);

        self::assertSame($task->id, $restored->id);
        self::assertSame($task->name, $restored->name);
        self::assertSame($task->params, $restored->params);
        self::assertSame($task->fingerprint, $restored->fingerprint);
        self::assertSame($task->resultTtl, $restored->resultTtl);
        self::assertEquals($task->createdAt->format('Y-m-d H:i:s'), $restored->createdAt->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function task_status_enum_is_finished(): void
    {
        self::assertFalse(TaskStatus::Pending->isFinished());
        self::assertFalse(TaskStatus::Running->isFinished());
        self::assertTrue(TaskStatus::Completed->isFinished());
        self::assertTrue(TaskStatus::Failed->isFinished());
    }

    #[Test]
    public function unique_ids_for_different_tasks(): void
    {
        $task1 = Task::create(name: 'a', params: [], fingerprint: 'fp1');
        $task2 = Task::create(name: 'b', params: [], fingerprint: 'fp2');

        self::assertNotSame($task1->id, $task2->id);
    }
}
