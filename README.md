# DeferQ

[Документация на русском (README.ru.md)](README.ru.md)

Async task manager for PHP 8.4 with built-in deduplication, result caching (PSR-16), and callback notifications. Submit heavy tasks (report generation, data exports, etc.), and DeferQ will ensure each unique task runs only once, cache results for subsequent requests, and notify your application when work is done.

## Installation

```bash
composer require deferq/deferq
```

For Redis queue support:

```bash
composer require predis/predis
```

For RabbitMQ queue support:

```bash
composer require php-amqplib/php-amqplib
```

## Quick Start

### 1. Configure DeferQ

```php
<?php

use DeferQ\DeferQ;
use DeferQ\Fingerprint\DefaultFingerprintGenerator;
use DeferQ\Lock\CacheLock;
use DeferQ\Queue\RedisQueueAdapter;
use DeferQ\Result\CacheResultStore;
use DeferQ\Store\CacheTaskStore;
use Predis\Client;
use Symfony\Component\Cache\Psr16Cache;
use Symfony\Component\Cache\Adapter\RedisAdapter;

$redis = new Client('tcp://127.0.0.1:6379');
$cache = new Psr16Cache(new RedisAdapter($redis));

$deferq = new DeferQ(
    queue: new RedisQueueAdapter($redis),
    taskStore: new CacheTaskStore($cache),
    resultStore: new CacheResultStore($cache),
    lock: new CacheLock($cache),
    fingerprinter: new DefaultFingerprintGenerator(),
);
```

### 2. Register Task Handlers

```php
<?php

use DeferQ\Handler\TaskHandlerInterface;
use DeferQ\Handler\TaskHandlerRegistry;
use DeferQ\Task\Task;

class ReportGenerateHandler implements TaskHandlerInterface
{
    public function handle(Task $task): mixed
    {
        $year = $task->params['year'];
        $format = $task->params['format'];

        // ... heavy computation ...

        return ['url' => "/reports/report-{$year}.{$format}", 'rows' => 15000];
    }
}

$handlers = new TaskHandlerRegistry();
$handlers->register('report.generate', new ReportGenerateHandler());
```

### 3. Dispatch a Task

```php
<?php

use DeferQ\Task\TaskStatus;

$receipt = $deferq->dispatch(
    name: 'report.generate',
    params: ['year' => 2024, 'format' => 'xlsx'],
    resultTtl: 3600,
);

match ($receipt->status) {
    TaskStatus::Completed => handleReady($receipt->result),
    TaskStatus::Running   => pollLater($receipt->taskId),
    TaskStatus::Pending   => pollLater($receipt->taskId),
    TaskStatus::Failed    => handleError($receipt->taskId),
};
```

### 4. Poll for Status

```php
$receipt = $deferq->getStatus($taskId);

if ($receipt->status === TaskStatus::Completed) {
    $result = $receipt->result;
}
```

### 5. Run the Worker

```php
<?php

use DeferQ\Worker\Worker;
use DeferQ\Worker\WorkerConfig;

$worker = new Worker(
    queue: $queueAdapter,
    handlers: $handlers,
    taskStore: $taskStore,
    resultStore: $resultStore,
    config: new WorkerConfig(
        sleepMs: 1000,
        maxJobs: 500,
        maxMemoryMb: 128,
        taskTimeoutSeconds: 300,
    ),
    logger: $psrLogger,
);

$worker->run();
```

Or use the CLI worker:

```bash
php bin/deferq-worker --bootstrap=worker-bootstrap.php --max-jobs=1000 --sleep=500
```

## Deduplication

DeferQ prevents duplicate execution of identical tasks using fingerprinting:

1. When you call `dispatch()`, DeferQ generates a SHA-256 fingerprint from the task name and canonically sorted parameters.
2. If a result for this fingerprint already exists in cache, it returns immediately with `TaskStatus::Completed`.
3. If a task with this fingerprint is already pending or running, the existing task's receipt is returned — no new task is created.
4. Only if no cached result and no active task exist, a new task is created and queued.

This means 100 users requesting the same report simultaneously will trigger only a single execution.

```php
// Both calls return the same task receipt (deduplication)
$receipt1 = $deferq->dispatch('report.generate', ['year' => 2024, 'format' => 'xlsx']);
$receipt2 = $deferq->dispatch('report.generate', ['format' => 'xlsx', 'year' => 2024]);

// $receipt1->taskId === $receipt2->taskId
```

Parameter key ordering does not matter — `['a' => 1, 'b' => 2]` and `['b' => 2, 'a' => 1]` produce the same fingerprint.

## Callbacks

Callbacks are invoked by the worker after a task completes and its result is saved to cache. Implement `CallbackInterface` for production use:

```php
<?php

use DeferQ\Callback\CallbackInterface;
use DeferQ\Task\Task;

class WebSocketNotifier implements CallbackInterface
{
    public function __construct(private WebSocketServer $ws) {}

    public function __invoke(Task $task, mixed $result): void
    {
        $this->ws->send($task->id, json_encode($result));
    }
}

$receipt = $deferq->dispatch(
    name: 'report.generate',
    params: ['year' => 2024],
    callback: new WebSocketNotifier($ws),
);
```

Chain multiple callbacks with `CallbackChain`:

```php
<?php

use DeferQ\Callback\CallbackChain;

$receipt = $deferq->dispatch(
    name: 'report.generate',
    params: ['year' => 2024],
    callback: new CallbackChain(
        new WebSocketNotifier($ws),
        new EmailNotifier($mailer),
        new MetricsRecorder($metrics),
    ),
);
```

Callback failures are caught and logged — they never crash the worker.

## Custom Queue Adapter

Implement `QueueAdapterInterface` to use any queue backend:

```php
<?php

use DeferQ\Queue\QueueAdapterInterface;
use DeferQ\Task\Task;

class DatabaseQueueAdapter implements QueueAdapterInterface
{
    public function __construct(private PDO $pdo) {}

    public function push(Task $task): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO deferq_queue (payload, created_at) VALUES (?, NOW())'
        );
        $stmt->execute([json_encode($task->toArray())]);
    }

    public function pop(int $timeoutSeconds = 5): ?Task
    {
        // Fetch and lock the oldest unprocessed row
        $stmt = $this->pdo->prepare(
            'SELECT id, payload FROM deferq_queue
             WHERE processing = 0
             ORDER BY created_at ASC
             LIMIT 1
             FOR UPDATE SKIP LOCKED'
        );
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        $this->pdo->prepare('UPDATE deferq_queue SET processing = 1 WHERE id = ?')
            ->execute([$row['id']]);

        return Task::fromArray(json_decode($row['payload'], true));
    }

    public function ack(Task $task): void
    {
        // Delete processed row
    }

    public function nack(Task $task): void
    {
        // Reset processing flag
    }
}
```

## CLI Worker Bootstrap

Create a `worker-bootstrap.php` file that returns a configured `Worker` instance:

```php
<?php
// worker-bootstrap.php

require __DIR__ . '/vendor/autoload.php';

use DeferQ\Handler\TaskHandlerRegistry;
use DeferQ\Lock\CacheLock;
use DeferQ\Queue\RedisQueueAdapter;
use DeferQ\Result\CacheResultStore;
use DeferQ\Store\CacheTaskStore;
use DeferQ\Worker\Worker;
use DeferQ\Worker\WorkerConfig;
use Predis\Client;
use Psr\Log\NullLogger;

// Your PSR-16 cache implementation
$redis = new Client('tcp://127.0.0.1:6379');
$cache = /* your PSR-16 cache backed by Redis */;

// Register handlers
$handlers = new TaskHandlerRegistry();
$handlers->register('report.generate', new ReportGenerateHandler());
$handlers->register('export.csv', new CsvExportHandler());

// CLI overrides from command-line arguments
$overrides = $GLOBALS['deferq_cli_overrides'] ?? [];

return new Worker(
    queue: new RedisQueueAdapter($redis),
    handlers: $handlers,
    taskStore: new CacheTaskStore($cache),
    resultStore: new CacheResultStore($cache),
    config: new WorkerConfig(
        sleepMs: $overrides['sleepMs'] ?? 1000,
        maxJobs: $overrides['maxJobs'] ?? 0,
        maxMemoryMb: $overrides['maxMemoryMb'] ?? 128,
        taskTimeoutSeconds: $overrides['taskTimeoutSeconds'] ?? 300,
    ),
    logger: new NullLogger(),
);
```

Then run:

```bash
php bin/deferq-worker --bootstrap=worker-bootstrap.php --max-jobs=1000 --sleep=500
```

## Signal Handling

The CLI worker handles `SIGTERM` and `SIGINT` for graceful shutdown. When a signal is received, the worker finishes processing the current task before exiting.

## License

MIT
