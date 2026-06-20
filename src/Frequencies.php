<?php

declare(strict_types=1);

namespace PhpCron;

trait Frequencies
{
    public function cron(string $expression): static
    {
        $this->expression = $expression;
        $this->cronExpression = null;

        return $this;
    }

    public function everyMinute(): static
    {
        return $this->cron('* * * * *');
    }

    public function everyTwoMinutes(): static
    {
        return $this->cron('*/2 * * * *');
    }

    public function everyThreeMinutes(): static
    {
        return $this->cron('*/3 * * * *');
    }

    public function everyFourMinutes(): static
    {
        return $this->cron('*/4 * * * *');
    }

    public function everyFiveMinutes(): static
    {
        return $this->cron('*/5 * * * *');
    }

    public function everyTenMinutes(): static
    {
        return $this->cron('*/10 * * * *');
    }

    public function everyFifteenMinutes(): static
    {
        return $this->cron('*/15 * * * *');
    }

    public function everyThirtyMinutes(): static
    {
        return $this->cron('*/30 * * * *');
    }

    public function hourly(): static
    {
        return $this->cron('0 * * * *');
    }

    public function hourlyAt(int|array $minute): static
    {
        $minutes = is_array($minute) ? implode(',', $minute) : (string) $minute;

        return $this->cron("{$minutes} * * * *");
    }

    public function everyTwoHours(): static
    {
        return $this->cron('0 */2 * * *');
    }

    public function everyThreeHours(): static
    {
        return $this->cron('0 */3 * * *');
    }

    public function everyFourHours(): static
    {
        return $this->cron('0 */4 * * *');
    }

    public function everySixHours(): static
    {
        return $this->cron('0 */6 * * *');
    }

    public function daily(): static
    {
        return $this->cron('0 0 * * *');
    }

    public function dailyAt(string $time): static
    {
        [$hour, $minute] = self::parseTime($time);

        return $this->cron("{$minute} {$hour} * * *");
    }

    public function twiceDaily(int $first = 1, int $second = 13): static
    {
        return $this->cron("0 {$first},{$second} * * *");
    }

    public function twiceDailyAt(int $first, int $second, int|array $minutes): static
    {
        $mins = is_array($minutes) ? implode(',', $minutes) : (string) $minutes;

        return $this->cron("{$mins} {$first},{$second} * * *");
    }

    public function weekly(): static
    {
        return $this->cron('0 0 * * 0');
    }

    public function weeklyOn(int|string $day, string $time = '0:0'): static
    {
        $dayOfWeek = is_string($day)
            ? (string) (CronExpression::resolveDayName($day) ?? throw new \InvalidArgumentException("Invalid day name: {$day}"))
            : (string) ($day % 7);

        [$hour, $minute] = self::parseTime($time);

        return $this->cron("{$minute} {$hour} * * {$dayOfWeek}");
    }

    public function monthly(): static
    {
        return $this->cron('0 0 1 * *');
    }

    public function monthlyOn(int $day, string $time = '0:0'): static
    {
        [$hour, $minute] = self::parseTime($time);

        return $this->cron("{$minute} {$hour} {$day} * *");
    }

    public function twiceMonthly(int $first = 1, int $second = 16, string $time = '0:0'): static
    {
        [$hour, $minute] = self::parseTime($time);

        return $this->cron("{$minute} {$hour} {$first},{$second} * *");
    }

    public function lastDayOfMonth(string $time = '0:0'): static
    {
        [$hour, $minute] = self::parseTime($time);

        return $this->cron("{$minute} {$hour} 28-31 * *")
            ->when(fn(\DateTimeInterface $now): bool => $now->format('j') === $now->format('t'));
    }

    public function quarterly(): static
    {
        return $this->cron('0 0 1 1,4,7,10 *');
    }

    public function yearly(): static
    {
        return $this->cron('0 0 1 1 *');
    }

    public function yearlyOn(int $month = 1, int|string $day = 1, string $time = '0:0'): static
    {
        [$hour, $minute] = self::parseTime($time);

        return $this->cron("{$minute} {$hour} {$day} {$month} *");
    }

    public function weekdays(): static
    {
        return $this->cron(sprintf('%s %s * * 1-5', $this->minutePart(), $this->hourPart()));
    }

    public function weekends(): static
    {
        return $this->cron(sprintf('%s %s * * 0,6', $this->minutePart(), $this->hourPart()));
    }

    public function sundays(): static
    {
        return $this->cron(sprintf('%s %s * * 0', $this->minutePart(), $this->hourPart()));
    }

    public function mondays(): static
    {
        return $this->cron(sprintf('%s %s * * 1', $this->minutePart(), $this->hourPart()));
    }

    public function tuesdays(): static
    {
        return $this->cron(sprintf('%s %s * * 2', $this->minutePart(), $this->hourPart()));
    }

    public function wednesdays(): static
    {
        return $this->cron(sprintf('%s %s * * 3', $this->minutePart(), $this->hourPart()));
    }

    public function thursdays(): static
    {
        return $this->cron(sprintf('%s %s * * 4', $this->minutePart(), $this->hourPart()));
    }

    public function fridays(): static
    {
        return $this->cron(sprintf('%s %s * * 5', $this->minutePart(), $this->hourPart()));
    }

    public function saturdays(): static
    {
        return $this->cron(sprintf('%s %s * * 6', $this->minutePart(), $this->hourPart()));
    }

    public function between(string $start, string $end): static
    {
        $this->filters[] = function (\DateTimeInterface $now) use ($start, $end): bool {
            $ts = $now->getTimestamp();
            $startTs = strtotime($start, $ts);
            $endTs = strtotime($end, $ts);

            if ($startTs === false || $endTs === false) {
                return true;
            }

            return match (true) {
                $endTs < $startTs => $ts >= $startTs || $ts <= $endTs,
                default           => $ts >= $startTs && $ts <= $endTs,
            };
        };

        return $this;
    }

    public function unlessBetween(string $start, string $end): static
    {
        $this->rejects[] = function (\DateTimeInterface $now) use ($start, $end): bool {
            $ts = $now->getTimestamp();
            $startTs = strtotime($start, $ts);
            $endTs = strtotime($end, $ts);

            if ($startTs === false || $endTs === false) {
                return false;
            }

            return match (true) {
                $endTs < $startTs => $ts >= $startTs && $ts <= $endTs,
                default           => $ts >= $startTs && $ts <= $endTs,
            };
        };

        return $this;
    }

    public function when(callable $callback): static
    {
        $this->filters[] = $callback;

        return $this;
    }

    public function skip(callable $callback): static
    {
        $this->rejects[] = $callback;

        return $this;
    }

    public function environments(string|array ...$environments): static
    {
        $this->environments = array_merge(
            $this->environments,
            is_array($environments[0] ?? null) ? $environments[0] : $environments,
        );

        return $this;
    }

    public function timezone(string|\DateTimeZone $timezone): static
    {
        $this->timezone = $timezone instanceof \DateTimeZone
            ? $timezone
            : new \DateTimeZone($timezone);

        return $this;
    }

    public function withoutOverlapping(int $expiresAt = 1440): static
    {
        $this->preventOverlapping = true;
        $this->expiresAfter = $expiresAt;

        return $this;
    }

    public function evenInMaintenanceMode(): static
    {
        $this->evenInMaintenanceMode = true;

        return $this;
    }

    public function appendOutputTo(string $path): static
    {
        $this->appendOutputTo = $path;

        return $this;
    }

    public function sendOutputTo(string $path): static
    {
        $this->sendOutputTo = $path;

        return $this;
    }

    /** @param string[] $emails */
    public function emailOutputTo(array $emails): static
    {
        $this->emailOutputTo = $emails;

        return $this;
    }

    public function before(callable $callback): static
    {
        $this->beforeCallbacks[] = $callback;

        return $this;
    }

    public function after(callable $callback): static
    {
        $this->afterCallbacks[] = $callback;

        return $this;
    }

    public function onSuccess(callable $callback): static
    {
        $this->successCallbacks[] = $callback;

        return $this;
    }

    public function onFailure(callable $callback): static
    {
        $this->failureCallbacks[] = $callback;

        return $this;
    }

    public function name(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function description(string $description): static
    {
        $this->description = $description;

        return $this;
    }

    /**
     * @return array{int, int}
     */
    private static function parseTime(string $time): array
    {
        $segments = explode(':', $time, 2);

        return [(int) $segments[0], (int) ($segments[1] ?? 0)];
    }

    private function minutePart(): string
    {
        $current = $this->cronExpression?->expression ?? $this->expression;

        return explode(' ', $current)[0] ?? '*';
    }

    private function hourPart(): string
    {
        $current = $this->cronExpression?->expression ?? $this->expression;

        return explode(' ', $current)[1] ?? '*';
    }
}
