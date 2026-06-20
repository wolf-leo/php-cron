# php-cron

PHP 8.2+ 定时任务调度器，提供流畅的 API 和完整的任务生命周期管理。

## 特性

- **Cron 表达式** — 标准 5 字段，支持 `*/n`、范围、列表、英文月/周名称、`@daily` 别名
- **流畅 API** — `everyMinute()`、`hourly()`、`dailyAt('8:30')`、`weeklyOn('monday')` 等 30+ 方法
- **任务约束** — 时间范围 `between()`、环境过滤 `environments()`、条件 `when()`/`skip()`
- **生命周期钩子** — `before()`、`after()`、`onSuccess()`、`onFailure()`
- **防重叠** — `withoutOverlapping()` 基于文件互斥锁
- **时区支持** — `timezone('Asia/Shanghai')`
- **三种任务类型** — Closure、shell 命令、`Class@method`

## 安装

```bash
composer require wolfcode/php-cron
```

## 快速开始

```php
use PhpCron\Scheduler;

$scheduler = new Scheduler();

$scheduler->call(function () {
    echo "每分钟执行一次\n";
})->everyMinute()->name('heartbeat');

$scheduler->command('df -h')
    ->hourly()
    ->appendOutputTo(__DIR__ . '/disk-usage.log');

$scheduler->call(function () {
    // 工作日早 9 点发报告
})->dailyAt('9:00')->weekdays()->name('daily-report');

// 运行到期的任务
$scheduler->runDueEvents(new DateTimeImmutable());
```

## API 概览

### 频次方法

| 方法 | cron 表达式 |
|------|------------|
| `everyMinute()` | `* * * * *` |
| `everyFiveMinutes()` | `*/5 * * * *` |
| `everyFifteenMinutes()` | `*/15 * * * *` |
| `hourly()` | `0 * * * *` |
| `hourlyAt(30)` | `30 * * * *` |
| `daily()` | `0 0 * * *` |
| `dailyAt('8:30')` | `30 8 * * *` |
| `twiceDaily(1, 13)` | `0 1,13 * * *` |
| `weekly()` | `0 0 * * 0` |
| `weeklyOn('monday', '9:00')` | `0 9 * * 1` |
| `monthly()` | `0 0 1 * *` |
| `monthlyOn(15, '14:30')` | `30 14 15 * *` |
| `quarterly()` | `0 0 1 1,4,7,10 *` |
| `yearly()` | `0 0 1 1 *` |
| `weekdays()` | `0 0 * * 1-5` |
| `weekends()` | `0 0 * * 0,6` |
| `lastDayOfMonth()` | `0 0 28-31 * *` + 过滤器 |

### 约束

```php
->between('9:00', '17:00')     // 仅在 9-17 点之间执行
->unlessBetween('23:00', '6:00') // 不在指定时间段执行
->when(fn() => someCondition())   // 条件为真时执行
->skip(fn() => holidayCheck())    // 条件为真时跳过
->environments('production')      // 仅限指定环境
->timezone('Asia/Shanghai')       // 指定时区
```

### 钩子

```php
->before(function () { /* 执行前 */ })
->after(function () { /* 执行后（无论成功失败） */ })
->onSuccess(function ($output) { /* 成功时 */ })
->onFailure(function (\Throwable $e) { /* 失败时 */ })
```

### 其他

```php
->withoutOverlapping()           // 防止任务重叠
->evenInMaintenanceMode()        // 维护模式下也执行
->sendOutputTo('/path/to.log')   // 输出到文件
->appendOutputTo('/path/to.log') // 追加到文件
->name('task-name')              // 任务标识
```

## License

MIT
