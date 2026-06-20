<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use PhpCron\Scheduler;

$scheduler = new Scheduler();

// Task 1: Closure - every minute (default)
$scheduler->call(function () {
    echo "This runs every minute!\n";
})->everyMinute()->name('minute-task');

// Task 2: Closure with day constraints - daily at 8:30 on weekdays
$scheduler->call(function () {
    echo "8:30 AM on weekdays!\n";
})->dailyAt('8:30')->weekdays()->name('weekday-alarm');

// Task 3: Shell command - hourly, append output to log
$scheduler->command('df -h')
    ->hourly()
    ->appendOutputTo(__DIR__ . '/storage/disk-usage.log')
    ->name('disk-usage');

// Task 4: Twice daily with overlapping prevention
$scheduler->call(function () {
    sleep(5);
    echo "Long-running task at 1 AM and 13 PM\n";
})->twiceDaily(1, 13)->withoutOverlapping()->name('twice-daily');

// Task 5: Time-range constraint - every 30 min during work hours
$scheduler->call(function () {
    echo "Only between 9 AM and 5 PM\n";
})->everyThirtyMinutes()->between('9:00', '17:00')->name('work-hours');

// Task 6: Custom cron expression - every 15 min on weekends
$scheduler->call(function () {
    echo "Weekend 15-minute task\n";
})->cron('*/15 * * * 0,6')->name('weekend-15min');

// Task 7: Full lifecycle hooks
$scheduler->call(function () {
    echo "Task with hooks!\n";
})->everyMinute()
    ->before(function () { echo "Before task\n"; })
    ->after(function () { echo "After task\n"; })
    ->onSuccess(function ($output) { echo "Success: $output\n"; })
    ->onFailure(function (\Throwable $e) { echo "Failed: {$e->getMessage()}\n"; })
    ->name('hooks-demo');

// --- Execution ---

// Find and run all due tasks for the current minute
$now = new DateTimeImmutable('now', new DateTimeZone('Asia/Shanghai'));

echo "Checking schedules at " . $now->format('Y-m-d H:i:s') . "\n";
echo str_repeat('-', 50) . "\n";

$results = $scheduler->runDueEvents($now);

echo str_repeat('-', 50) . "\n";
echo "Executed " . count($results) . " task(s)\n";
