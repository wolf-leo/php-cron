# php-cron

[中文文档](README.zh.md)

PHP 8.2+ task scheduler with a fluent API and full lifecycle management.

## Features

- **Cron expressions** — standard 5-field or 6-field (with seconds); supports `*/n`, ranges, lists, weekday/month names, `@daily` aliases
- **Fluent API** — `second(3)`, `minute(5)`, `hour(2)`, `dailyAt('8:30')`, `weeklyOn('monday')`
- **Constraints** — time range `between()`, environment `environments()`, condition `when()`/`skip()`
- **Lifecycle hooks** — `before()`, `after()`, `onSuccess()`, `onFailure()`
- **Overlap prevention** — `withoutOverlapping()` via file-based mutex
- **Timezone support** — global per-daemon and per-task
- **Three task types** — Closure, shell command, `Class@method`
- **Daemon mode** — continuous loop with second precision, auto-fork to background

## Requirements

| Extension     | Purpose                                                              | Required                                     |
|---------------|----------------------------------------------------------------------|----------------------------------------------|
| `ext-pcntl`   | Process control: `pcntl_fork()` (background daemon), signal handling | Optional — needed for daemon background mode |
| `ext-posix`   | POSIX: `posix_setsid()` (new session), `posix_kill()` (stop daemon)  | Optional — needed for daemon start/stop      |
| `ext-json`    | Task info serialization (`.tasks.json` PID file)                     | Optional — bundled with PHP by default       |
| `ext-opcache` | `opcache_reset()` (periodic reset in long-running process)           | Optional — works fine without                |

> `ext-pcntl` and `ext-posix` are CLI-only and unavailable in Web SAPI.
> If `ext-posix` is missing, `stop` falls back to `exec("kill ...")`.

## Installation

```bash
composer require wolfcode/php-cron
```

## Quick Start

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use PhpCron\Scheduler;
use PhpCron\Timezone;

Scheduler::run(function (Scheduler $s) {
    $s->call(function () {
        echo date('Y-m-d H:i:s') . " heartbeat\n";
    })->minute(1)->name('heartbeat');

    $s->command('df -h')
        ->hourly()
        ->appendOutputTo(__DIR__ . '/disk-usage.log');

    $s->call(function () {
        // Send daily report at 9 AM on weekdays
    })->dailyAt('9:00')->weekdays()->name('daily-report');
}, Timezone::AMERICA_NEW_YORK);
```

```bash
php my-cron.php            # Start (background, auto-fork)
php my-cron.php status     # List tasks
php my-cron.php stop       # Stop daemon
```

## Timezone

Two levels:

- **Global timezone** — second argument of `Scheduler::run()`. Affects `started_at` in status output, all `date()` calls, and every task's scheduling
- **Per-task timezone** — `->timezone()`. Overrides scheduling timezone for that task only; does NOT affect `date()`

```php
use PhpCron\Timezone;

// Global timezone: all date() + status time + scheduling use Eastern time
Scheduler::run(function (Scheduler $s) {
    $s->call(function () {
        file_put_contents('log.txt', date('Y-m-d H:i:s') . "\n", FILE_APPEND);
    })->minute(1);
}, Timezone::AMERICA_NEW_YORK);

// Per-task timezone: only affects when this task triggers, not date()
$s->call(fn() => doSomething())
    ->timezone(Timezone::AMERICA_NEW_YORK)
    ->dailyAt('9:00');
```

| Constant                        | Timezone                   |
|---------------------------------|----------------------------|
| `Timezone::UTC`                 | UTC                        |
| `Timezone::ASIA_SHANGHAI`       | China (Shanghai)           |
| `Timezone::ASIA_HONG_KONG`      | China (Hong Kong)          |
| `Timezone::ASIA_TAIPEI`         | China (Taipei)             |
| `Timezone::ASIA_TOKYO`          | Japan (Tokyo)              |
| `Timezone::ASIA_SEOUL`          | South Korea (Seoul)        |
| `Timezone::ASIA_SINGAPORE`      | Singapore                  |
| `Timezone::ASIA_BANGKOK`        | Thailand (Bangkok)         |
| `Timezone::ASIA_MOSCOW`         | Russia (Moscow)            |
| `Timezone::EUROPE_LONDON`       | UK (London)                |
| `Timezone::EUROPE_PARIS`        | France (Paris)             |
| `Timezone::EUROPE_BERLIN`       | Germany (Berlin)           |
| `Timezone::AMERICA_NEW_YORK`    | US (New York / Eastern)    |
| `Timezone::AMERICA_CHICAGO`     | US (Chicago / Central)     |
| `Timezone::AMERICA_LOS_ANGELES` | US (Los Angeles / Pacific) |
| `Timezone::AUSTRALIA_SYDNEY`    | Australia (Sydney)         |
| `Timezone::PACIFIC_AUCKLAND`    | New Zealand (Auckland)     |

## API Reference

### Frequency Methods (Parameter-based)

| Method      | Description     | Example cron              |
|-------------|-----------------|---------------------------|
| `second(3)` | Every N seconds | `*/3 * * * * *`           |
| `minute(5)` | Every N minutes | `*/5 * * * *`             |
| `hour(2)`   | Every N hours   | `0 */2 * * *`             |
| `day(3)`    | Every N days    | `0 0 */3 * *`             |
| `week(2)`   | Every N weeks   | `0 0 * * 0` + week filter |
| `month(6)`  | Every N months  | `0 0 1 */6 *`             |

### Semantic Methods

| Method                       | Cron expression          |
|------------------------------|--------------------------|
| `hourly()`                   | `0 * * * *`              |
| `hourlyAt(30)`               | `30 * * * *`             |
| `daily()`                    | `0 0 * * *`              |
| `dailyAt('8:30')`            | `30 8 * * *`             |
| `twiceDaily(1, 13)`          | `0 1,13 * * *`           |
| `weekly()`                   | `0 0 * * 0`              |
| `weeklyOn('monday', '9:00')` | `0 9 * * 1`              |
| `monthly()`                  | `0 0 1 * *`              |
| `monthlyOn(15, '14:30')`     | `30 14 15 * *`           |
| `quarterly()`                | `0 0 1 1,4,7,10 *`       |
| `yearly()`                   | `0 0 1 1 *`              |
| `weekdays()`                 | `0 0 * * 1-5`            |
| `weekends()`                 | `0 0 * * 0,6`            |
| `lastDayOfMonth()`           | `0 0 28-31 * *` + filter |

### Custom Expression

```php
->cron('0 9 * * 1')     // Every Monday at 9 AM
->cron('*/5 * * * * *') // Every 5 seconds (6-field)
```

### Constraints

```php
->between('9:00', '17:00')          // Only between 9 AM and 5 PM
->unlessBetween('23:00', '6:00')    // Skip during specified hours
->when(fn() => someCondition())      // Run when truthy
->skip(fn() => holidayCheck())       // Skip when truthy
->environments('production')         // Only in given environments
->timezone(Timezone::ASIA_SHANGHAI)  // Per-task timezone
```

### Hooks

```php
->before(function () { /* before task */ })
->after(function () { /* after task (always) */ })
->onSuccess(function ($output) { /* on success */ })
->onFailure(function (\Throwable $e) { /* on failure */ })
```

### Others

```php
->withoutOverlapping()           // Prevent overlapping runs
->sendOutputTo('/path/to.log')   // Write output to file
->appendOutputTo('/path/to.log') // Append output to file
->name('task-name')              // Task identifier
```

## License

MIT
