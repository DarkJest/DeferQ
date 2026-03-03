# DeferQ

Менеджер асинхронных задач для PHP 8.4 со встроенной дедупликацией, кешированием результатов (PSR-16) и callback-уведомлениями. Отправляйте тяжёлые задачи (генерация отчётов, экспорт данных и т.п.), а DeferQ гарантирует, что каждая уникальная задача выполнится только один раз, закеширует результат для последующих запросов и уведомит ваше приложение по завершении.

## Установка

```bash
composer require deferq/deferq
```

Для поддержки очереди через Redis:

```bash
composer require predis/predis
```

Для поддержки очереди через RabbitMQ:

```bash
composer require php-amqplib/php-amqplib
```

## Быстрый старт

### 1. Настройка DeferQ

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

### 2. Регистрация обработчиков задач

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

        // ... тяжёлые вычисления ...

        return ['url' => "/reports/report-{$year}.{$format}", 'rows' => 15000];
    }
}

$handlers = new TaskHandlerRegistry();
$handlers->register('report.generate', new ReportGenerateHandler());
```

### 3. Отправка задачи

```php
<?php

use DeferQ\Task\TaskStatus;

$receipt = $deferq->dispatch(
    name: 'report.generate',
    params: ['year' => 2024, 'format' => 'xlsx'],
    resultTtl: 3600,
);

match ($receipt->status) {
    TaskStatus::Completed => handleReady($receipt->result),  // результат уже в кеше
    TaskStatus::Running   => pollLater($receipt->taskId),     // задача выполняется
    TaskStatus::Pending   => pollLater($receipt->taskId),     // задача в очереди
    TaskStatus::Failed    => handleError($receipt->taskId),   // задача завершилась с ошибкой
};
```

### 4. Проверка статуса (polling)

```php
$receipt = $deferq->getStatus($taskId);

if ($receipt->status === TaskStatus::Completed) {
    $result = $receipt->result;
}
```

### 5. Запуск воркера

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
        sleepMs: 1000,        // пауза между опросами очереди (мс)
        maxJobs: 500,         // максимум задач перед остановкой
        maxMemoryMb: 128,     // лимит памяти (МБ)
        taskTimeoutSeconds: 300, // таймаут задачи (сек)
    ),
    logger: $psrLogger,
);

$worker->run();
```

Или через CLI-воркер:

```bash
php bin/deferq-worker --bootstrap=worker-bootstrap.php --max-jobs=1000 --sleep=500
```

## Дедупликация

DeferQ предотвращает повторное выполнение одинаковых задач с помощью механизма fingerprint:

1. При вызове `dispatch()` генерируется SHA-256 fingerprint из имени задачи и канонически отсортированных параметров.
2. Если результат для этого fingerprint уже есть в кеше — он возвращается сразу со статусом `TaskStatus::Completed`.
3. Если задача с таким fingerprint уже находится в статусе Pending или Running — возвращается receipt существующей задачи. Новая задача **не создаётся**.
4. Только если нет ни кешированного результата, ни активной задачи — создаётся новая задача и помещается в очередь.

Это значит, что 100 пользователей, одновременно запросивших один и тот же отчёт, инициируют **только одно** выполнение.

```php
// Оба вызова вернут один и тот же receipt (дедупликация)
$receipt1 = $deferq->dispatch('report.generate', ['year' => 2024, 'format' => 'xlsx']);
$receipt2 = $deferq->dispatch('report.generate', ['format' => 'xlsx', 'year' => 2024]);

// $receipt1->taskId === $receipt2->taskId
```

Порядок ключей в параметрах не имеет значения — `['a' => 1, 'b' => 2]` и `['b' => 2, 'a' => 1]` дают одинаковый fingerprint.

## Callback-уведомления

Callback вызывается воркером после завершения задачи и сохранения результата в кеш. Для production реализуйте `CallbackInterface`:

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

Цепочка из нескольких callback через `CallbackChain`:

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

Ошибки в callback перехватываются и логируются — они **никогда** не ломают воркер.

## Создание своего адаптера очереди

Реализуйте `QueueAdapterInterface` для использования любого бэкенда очередей:

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
        // Получить и заблокировать самую старую необработанную строку
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
        // Удалить обработанную строку
    }

    public function nack(Task $task): void
    {
        // Сбросить флаг processing
    }
}
```

## Bootstrap-файл для CLI-воркера

Создайте файл `worker-bootstrap.php`, который возвращает сконфигурированный экземпляр `Worker`:

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

// Ваша реализация PSR-16 кеша
$redis = new Client('tcp://127.0.0.1:6379');
$cache = /* ваш PSR-16 кеш на базе Redis */;

// Регистрация обработчиков
$handlers = new TaskHandlerRegistry();
$handlers->register('report.generate', new ReportGenerateHandler());
$handlers->register('export.csv', new CsvExportHandler());

// Переопределения из аргументов командной строки
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

Запуск:

```bash
php bin/deferq-worker --bootstrap=worker-bootstrap.php --max-jobs=1000 --sleep=500
```

## Обработка сигналов

CLI-воркер обрабатывает сигналы `SIGTERM` и `SIGINT` для корректного завершения. При получении сигнала воркер дожидается окончания обработки текущей задачи и только потом останавливается.

## Автор

**DarkJest**

## Лицензия

MIT
