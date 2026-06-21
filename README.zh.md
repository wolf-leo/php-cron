# php-cron

[English](README.md)

PHP 8.2+ 定时任务调度器，提供流畅的 API 和完整的任务生命周期管理。

## 特性

- **Cron 表达式** — 标准 5 字段或 6 字段（带秒），支持 `*/n`、范围、列表、英文月/周名称、`@daily` 别名
- **流畅 API** — `second(3)`、`minute(5)`、`hour(2)`、`dailyAt('8:30')`、`weeklyOn('monday')`
- **任务约束** — 时间范围 `between()`、环境过滤 `environments()`、条件 `when()`/`skip()`
- **生命周期钩子** — `before()`、`after()`、`onSuccess()`、`onFailure()`
- **防重叠** — `withoutOverlapping()` 基于文件互斥锁
- **时区支持** — 全局 daemon 时区 + 单个 task 时区
- **三种任务类型** — Closure、shell 命令、`Class@method`
- **Daemon 模式** — 持续运行，支持秒级精度，自动 fork 后台

## 扩展要求

| 扩展            | 用途                                                      | 是否必须                        |
|---------------|---------------------------------------------------------|-----------------------------|
| `ext-pcntl`   | 进程控制：`pcntl_fork()`（后台 daemon）、信号处理                     | 可选 — daemon 后台模式需要，前台模式无需   |
| `ext-posix`   | POSIX：`posix_setsid()`（创建新会话）、`posix_kill()`（停止 daemon） | 可选 — daemon 的 start/stop 需要 |
| `ext-json`    | 任务信息序列化（PID 文件 `.tasks.json`）                           | 可选 — PHP 默认内置               |
| `ext-opcache` | `opcache_reset()`（长驻进程周期重置）                             | 可选 — 无 opcache 不影响功能        |

> `ext-pcntl` 和 `ext-posix` 仅在 CLI 模式下可用，Web SAPI 下不可用。
> 如果缺少 `ext-posix`，`stop` 命令会 fallback 到 `exec("kill ...")`。

## 安装

```bash
composer require wolfcode/php-cron
```

## 快速开始

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use PhpCron\Scheduler;
use PhpCron\Timezone;

Scheduler::run(function (Scheduler $s) {
    $s->call(function () {
        echo date('Y-m-d H:i:s') . " 每分钟执行一次\n";
    })->minute(1)->name('heartbeat');

    $s->command('df -h')
        ->hourly()
        ->appendOutputTo(__DIR__ . '/disk-usage.log');

    $s->call(function () {
        // 工作日早 9 点发报告
    })->dailyAt('9:00')->weekdays()->name('daily-report');
}, Timezone::ASIA_SHANGHAI);  // ← 全局时区
```

```bash
php my-cron.php            # 启动（后台运行，自动 fork）
php my-cron.php status     # 查看任务列表
php my-cron.php stop       # 停止
```

## 时区

时区分两个层级：

- **全局时区** — `Scheduler::run()` 第二参数，影响 daemon 的 `started_at`、所有 `date()` 调用、以及全部 task 的调度判断
- **任务时区** — `->timezone()`，仅覆盖单个 task 的调度时区判断，不影响 `date()`

```php
use PhpCron\Timezone;

// 全局时区（推荐）：所有 date() + status 时间 + 调度判断 都走上海时间
Scheduler::run(function (Scheduler $s) {
    $s->call(function () {
        file_put_contents('log.txt', date('Y-m-d H:i:s') . "\n", FILE_APPEND);
    })->minute(1);
}, Timezone::ASIA_SHANGHAI);

// 任务级时区：仅覆盖该任务的调度判断，不影响 date()
$s->call(fn() => doSomething())
    ->timezone(Timezone::AMERICA_NEW_YORK)
    ->dailyAt('9:00');
```

| 常量                              | 时区          |
|---------------------------------|-------------|
| `Timezone::UTC`                 | UTC         |
| `Timezone::ASIA_SHANGHAI`       | 中国 (上海)     |
| `Timezone::ASIA_HONG_KONG`      | 中国 (香港)     |
| `Timezone::ASIA_TAIPEI`         | 中国 (台北)     |
| `Timezone::ASIA_TOKYO`          | 日本 (东京)     |
| `Timezone::ASIA_SEOUL`          | 韩国 (首尔)     |
| `Timezone::ASIA_SINGAPORE`      | 新加坡         |
| `Timezone::ASIA_BANGKOK`        | 泰国 (曼谷)     |
| `Timezone::ASIA_MOSCOW`         | 俄罗斯 (莫斯科)   |
| `Timezone::EUROPE_LONDON`       | 英国 (伦敦)     |
| `Timezone::EUROPE_PARIS`        | 法国 (巴黎)     |
| `Timezone::EUROPE_BERLIN`       | 德国 (柏林)     |
| `Timezone::AMERICA_NEW_YORK`    | 美国 (纽约/美东)  |
| `Timezone::AMERICA_CHICAGO`     | 美国 (芝加哥/美中) |
| `Timezone::AMERICA_LOS_ANGELES` | 美国 (洛杉矶/美西) |
| `Timezone::AUSTRALIA_SYDNEY`    | 澳大利亚 (悉尼)   |
| `Timezone::PACIFIC_AUCKLAND`    | 新西兰 (奥克兰)   |

## API 概览

### 频次方法（参数式）

| 方法          | 说明     | 示例 cron            |
|-------------|--------|--------------------|
| `second(3)` | 每 N 秒  | `*/3 * * * * *`    |
| `minute(5)` | 每 N 分钟 | `*/5 * * * *`      |
| `hour(2)`   | 每 N 小时 | `0 */2 * * *`      |
| `day(3)`    | 每 N 天  | `0 0 */3 * *`      |
| `week(2)`   | 每 N 周  | `0 0 * * 0` + 周数过滤 |
| `month(6)`  | 每 N 月  | `0 0 1 */6 *`      |

### 语义化方法

| 方法                           | cron 表达式              |
|------------------------------|-----------------------|
| `hourly()`                   | `0 * * * *`           |
| `hourlyAt(30)`               | `30 * * * *`          |
| `daily()`                    | `0 0 * * *`           |
| `dailyAt('8:30')`            | `30 8 * * *`          |
| `twiceDaily(1, 13)`          | `0 1,13 * * *`        |
| `weekly()`                   | `0 0 * * 0`           |
| `weeklyOn('monday', '9:00')` | `0 9 * * 1`           |
| `monthly()`                  | `0 0 1 * *`           |
| `monthlyOn(15, '14:30')`     | `30 14 15 * *`        |
| `quarterly()`                | `0 0 1 1,4,7,10 *`    |
| `yearly()`                   | `0 0 1 1 *`           |
| `weekdays()`                 | `0 0 * * 1-5`         |
| `weekends()`                 | `0 0 * * 0,6`         |
| `lastDayOfMonth()`           | `0 0 28-31 * *` + 过滤器 |

### 自定义表达式

```php
->cron('0 9 * * 1')     // 每周一 9 点
->cron('*/5 * * * * *') // 每 5 秒（6 字段）
```

### 约束

```php
->between('9:00', '17:00')          // 仅在 9-17 点之间执行
->unlessBetween('23:00', '6:00')    // 不在指定时间段执行
->when(fn() => someCondition())      // 条件为真时执行
->skip(fn() => holidayCheck())       // 条件为真时跳过
->environments('production')         // 仅限指定环境
->timezone(Timezone::ASIA_SHANGHAI)  // 指定时区（支持常量）
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
->sendOutputTo('/path/to.log')   // 输出到文件
->appendOutputTo('/path/to.log') // 追加到文件
->name('task-name')              // 任务标识
```

## License

MIT
