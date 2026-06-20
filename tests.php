<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use PhpCron\CronExpression;
use PhpCron\Scheduler;

$passed = 0;
$failed = 0;

function assert_true(mixed $condition, string $description): void
{
    global $passed, $failed;
    if ($condition) {
        echo "  PASS: {$description}\n";
        $passed++;
    } else {
        echo "  FAIL: {$description}\n";
        $failed++;
    }
}

function assert_false(mixed $condition, string $description): void
{
    assert_true(!$condition, $description);
}

// ============ CronExpression Tests ============
echo "=== CronExpression Tests ===\n";

// Test 1: everyMinute
$cron = new CronExpression('* * * * *');
assert_true($cron->isDue(new DateTimeImmutable('2026-01-01 00:00:00')), 'everyMinute matches 00:00');
assert_true($cron->isDue(new DateTimeImmutable('2026-06-15 14:30:00')), 'everyMinute matches 14:30');

// Test 2: hourly at specific minute
$cron = new CronExpression('30 * * * *');
assert_true($cron->isDue(new DateTimeImmutable('2026-01-01 01:30:00')), 'hourly at :30 matches');
assert_false($cron->isDue(new DateTimeImmutable('2026-01-01 01:00:00')), 'hourly at :30 rejects :00');

// Test 3: daily at midnight
$cron = new CronExpression('0 0 * * *');
assert_true($cron->isDue(new DateTimeImmutable('2026-01-01 00:00:00')), 'daily midnight matches');
assert_false($cron->isDue(new DateTimeImmutable('2026-01-01 12:00:00')), 'daily midnight rejects noon');

// Test 4: specific day of week
$cron = new CronExpression('0 9 * * 1'); // Monday at 9 AM
assert_true($cron->isDue(new DateTimeImmutable('2026-06-22 09:00:00')), 'Monday 9AM matches (2026-06-22 is Monday)');
assert_false($cron->isDue(new DateTimeImmutable('2026-06-23 09:00:00')), 'Tuesday 9AM rejects (2026-06-23 is Tuesday)');

// Test 5: range and step
$cron = new CronExpression('*/15 * * * *');
assert_true($cron->isDue(new DateTimeImmutable('2026-01-01 00:00:00')), '*/15 matches :00');
assert_true($cron->isDue(new DateTimeImmutable('2026-01-01 00:15:00')), '*/15 matches :15');
assert_true($cron->isDue(new DateTimeImmutable('2026-01-01 00:30:00')), '*/15 matches :30');
assert_true($cron->isDue(new DateTimeImmutable('2026-01-01 00:45:00')), '*/15 matches :45');
assert_false($cron->isDue(new DateTimeImmutable('2026-01-01 00:07:00')), '*/15 rejects :07');

// Test 6: list
$cron = new CronExpression('0 9,18 * * *');
assert_true($cron->isDue(new DateTimeImmutable('2026-01-01 09:00:00')), 'list matches 9AM');
assert_true($cron->isDue(new DateTimeImmutable('2026-01-01 18:00:00')), 'list matches 6PM');
assert_false($cron->isDue(new DateTimeImmutable('2026-01-01 12:00:00')), 'list rejects noon');

// Test 7: range
$cron = new CronExpression('0 9-17 * * *');
assert_true($cron->isDue(new DateTimeImmutable('2026-01-01 09:00:00')), 'range matches 9AM');
assert_true($cron->isDue(new DateTimeImmutable('2026-01-01 12:00:00')), 'range matches noon');
assert_true($cron->isDue(new DateTimeImmutable('2026-01-01 17:00:00')), 'range matches 5PM');
assert_false($cron->isDue(new DateTimeImmutable('2026-01-01 08:00:00')), 'range rejects 8AM');

// Test 8: step with range
$cron = new CronExpression('0 1-10/3 * * *');
assert_true($cron->isDue(new DateTimeImmutable('2026-01-01 01:00:00')), '1-10/3 matches 1AM');
assert_true($cron->isDue(new DateTimeImmutable('2026-01-01 04:00:00')), '1-10/3 matches 4AM');
assert_true($cron->isDue(new DateTimeImmutable('2026-01-01 10:00:00')), '1-10/3 matches 10AM');
assert_false($cron->isDue(new DateTimeImmutable('2026-01-01 02:00:00')), '1-10/3 rejects 2AM');

// Test 9: aliases
$cron = new CronExpression('@daily');
assert_true($cron->isDue(new DateTimeImmutable('2026-01-01 00:00:00')), '@daily matches midnight');
assert_false($cron->isDue(new DateTimeImmutable('2026-01-01 12:00:00')), '@daily rejects noon');

$cron = new CronExpression('@hourly');
assert_true($cron->isDue(new DateTimeImmutable('2026-01-01 01:00:00')), '@hourly matches 1AM');
assert_false($cron->isDue(new DateTimeImmutable('2026-01-01 01:30:00')), '@hourly rejects :30');

// Test 10: day of week names (0 and 7 both = Sunday)
$cron = new CronExpression('0 0 * * 0');
$cron2 = new CronExpression('0 0 * * 7');
$sunday = new DateTimeImmutable('2026-06-21 00:00:00'); // Sunday
$monday = new DateTimeImmutable('2026-06-22 00:00:00'); // Monday
assert_true($cron->isDue($sunday), 'dow=0 matches Sunday');
assert_true($cron2->isDue($sunday), 'dow=7 matches Sunday');
assert_false($cron->isDue($monday), 'dow=0 rejects Monday');

// Test 11: month names
$cron = new CronExpression('0 0 1 jan *');
assert_true($cron->isDue(new DateTimeImmutable('2026-01-01 00:00:00')), 'jan matches January');
assert_false($cron->isDue(new DateTimeImmutable('2026-02-01 00:00:00')), 'jan rejects February');

// Test 12: weekend
$cron = new CronExpression('* * * * 0,6');
$sat = new DateTimeImmutable('2026-06-20 12:00:00'); // Saturday
$sun = new DateTimeImmutable('2026-06-21 12:00:00'); // Sunday
$mon = new DateTimeImmutable('2026-06-22 12:00:00'); // Monday
assert_true($cron->isDue($sat), 'weekend matches Saturday');
assert_true($cron->isDue($sun), 'weekend matches Sunday');
assert_false($cron->isDue($mon), 'weekend rejects Monday');

// ============ Schedule Tests ============
echo "\n=== Schedule Tests ===\n";

$scheduler = new Scheduler();

// Test schedule creation with various frequencies
$s1 = $scheduler->call(fn() => 'every-5-min')->everyFiveMinutes();
assert_true($s1->getExpression() === '*/5 * * * *', 'everyFiveMinutes sets correct expression');

$s2 = $scheduler->call(fn() => 'hourly')->hourly();
assert_true($s2->getExpression() === '0 * * * *', 'hourly sets correct expression');

$s3 = $scheduler->call(fn() => 'daily-830')->dailyAt('8:30');
assert_true($s3->getExpression() === '30 8 * * *', 'dailyAt 8:30 sets correct expression');

$s4 = $scheduler->call(fn() => 'twice')->twiceDaily(1, 13);
assert_true($s4->getExpression() === '0 1,13 * * *', 'twiceDaily sets correct expression');

$s5 = $scheduler->call(fn() => 'weekly-mon')->weeklyOn('monday', '9:00');
assert_true($s5->getExpression() === '0 9 * * 1', 'weeklyOn Monday 9:00 sets correct expression');

// Test isDue with timezone
$s6 = $scheduler->call(fn() => 'tz-test')->dailyAt('0:00')->timezone('Asia/Shanghai');
// It's midnight in Shanghai, but say UTC is 16:00 the previous day
$utcTime = new DateTimeImmutable('2026-06-20 16:00:00', new DateTimeZone('UTC'));
assert_true($s6->isDue($utcTime), 'timezone shifts to midnight Shanghai');

// Test filters: between
$s7 = $scheduler->call(fn() => 'between-test')->everyMinute()->between('9:00', '17:00');
assert_true($s7->isDue(new DateTimeImmutable('2026-01-01 12:00:00')), 'between 9-17 passes at noon');
assert_false($s7->isDue(new DateTimeImmutable('2026-01-01 06:00:00')), 'between 9-17 rejects at 6AM');

// Test summary/name
$s8 = $scheduler->call(fn() => 'named-test')->everyMinute()->name('my-custom-task');
assert_true($s8->getSummary() === 'my-custom-task', 'getSummary returns name');
assert_true($s8->getName() === 'my-custom-task', 'getName works');

// Test without overlapping
$s9 = $scheduler->call(fn() => 'no-overlap')->everyMinute()->withoutOverlapping(30);
assert_true($s9->preventsOverlapping() === true, 'withoutOverlapping sets flag');
assert_true($s9->getExpiresAfter() === 30, 'withoutOverlapping sets expires');

// ============ Integration: run due events ============
echo "\n=== Integration Tests ===\n";

$scheduler2 = new Scheduler();
$results = [];

$scheduler2->call(function () use (&$results) { $results[] = 'task1'; })->everyMinute()->name('t1');
$scheduler2->call(function () use (&$results) { $results[] = 'task2'; })->everyMinute()->name('t2');

$scheduler2->runDueEvents(new DateTimeImmutable('2026-01-01 00:00:00'));
assert_true(count($results) === 2, 'runs 2 due tasks');
assert_true($results[0] === 'task1', 'first result is task1');
assert_true($results[1] === 'task2', 'second result is task2');

// ============ Summary ============
echo "\n=== Summary ===\n";
$total = $passed + $failed;
echo "{$passed}/{$total} passed, {$failed}/{$total} failed\n";

exit($failed > 0 ? 1 : 0);
