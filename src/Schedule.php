<?php

declare(strict_types=1);

namespace PhpCron;

/**
 * @method $this cron(string $expression)
 * @method $this second(int $seconds)
 * @method $this minute(int $minutes)
 * @method $this hour(int $hours)
 * @method $this day(int $days)
 * @method $this week(int $weeks)
 * @method $this month(int $months)
 * @method $this hourly()
 * @method $this hourlyAt(int|array $minute)
 * @method $this daily()
 * @method $this dailyAt(string $time)
 * @method $this twiceDaily(int $first = 1, int $second = 13)
 * @method $this twiceDailyAt(int $first, int $second, int|array $minutes)
 * @method $this weekly()
 * @method $this weeklyOn(int|string $day, string $time = '0:0')
 * @method $this twiceMonthly(int $first = 1, int $second = 16, string $time = '0:0')
 * @method $this lastDayOfMonth(string $time = '0:0')
 * @method $this quarterly()
 * @method $this yearly()
 * @method $this yearlyOn(int $month = 1, int|string $day = 1, string $time = '0:0')
 * @method $this weekdays()
 * @method $this weekends()
 * @method $this sundays()
 * @method $this mondays()
 * @method $this tuesdays()
 * @method $this wednesdays()
 * @method $this thursdays()
 * @method $this fridays()
 * @method $this saturdays()
 * @method $this between(string $start, string $end)
 * @method $this unlessBetween(string $start, string $end)
 * @method $this when(callable $callback)
 * @method $this skip(callable $callback)
 * @method $this environments(string|array ...$environments)
 * @method $this timezone(string|\DateTimeZone $timezone)
 * @method $this withoutOverlapping(int $expiresAt = 1440)
 * @method $this appendOutputTo(string $path)
 * @method $this sendOutputTo(string $path)
 * @method $this emailOutputTo(array $emails)
 * @method $this before(callable $callback)
 * @method $this after(callable $callback)
 * @method $this onSuccess(callable $callback)
 * @method $this onFailure(callable $callback)
 * @method $this name(string $name)
 * @method $this description(string $description)
 */
class Schedule
{
    use Frequencies;

    private const MUTEX_DIR = '/tmp/phpcron/mutex';

    private ?CronExpression $cronExpression = null;
    private string $expression = '* * * * *';
    private ?\DateTimeZone $timezone = null;

    /** @var callable[] */
    private array $filters = [];

    /** @var callable[] */
    private array $rejects = [];

    /** @var string[] */
    private array $environments = [];

    private bool $preventOverlapping = false;
    private int $expiresAfter = 1440;
    private ?string $appendOutputTo = null;
    private ?string $sendOutputTo = null;

    /** @var string[] */
    private ?array $emailOutputTo = null;

    /** @var callable[] */
    private array $beforeCallbacks = [];

    /** @var callable[] */
    private array $afterCallbacks = [];

    /** @var callable[] */
    private array $successCallbacks = [];

    /** @var callable[] */
    private array $failureCallbacks = [];

    private ?string $name = null;
    private ?string $description = null;

    private \Closure|string $task;

    public function __construct(\Closure|string $task)
    {
        $this->task = $task;
    }

    public function isDue(\DateTimeInterface $now): bool
    {
        $now = $this->applyTimezone($now);

        return $this->getCronExpression()->isDue($now) && $this->filtersPass($now);
    }

    public function filtersPass(\DateTimeInterface $now): bool
    {
        $now = $this->applyTimezone($now);

        foreach ($this->filters as $filter) {
            if (!$filter($now)) {
                return false;
            }
        }

        foreach ($this->rejects as $reject) {
            if ($reject($now)) {
                return false;
            }
        }

        if ($this->environments !== []) {
            $appEnv = $_SERVER['APP_ENV'] ?? $_ENV['APP_ENV'] ?? 'production';

            if (!in_array($appEnv, $this->environments, true)) {
                return false;
            }
        }

        return true;
    }

    public function run(): mixed
    {
        if ($this->preventOverlapping && !$this->createMutex()) {
            return null;
        }

        try {
            foreach ($this->beforeCallbacks as $callback) {
                $callback($this);
            }

            $output = $this->executeTask();

            if ($this->sendOutputTo !== null) {
                file_put_contents($this->sendOutputTo, $output);
            }

            if ($this->appendOutputTo !== null) {
                file_put_contents($this->appendOutputTo, $output, FILE_APPEND);
            }

            $this->callCallbacks($this->successCallbacks, $output);
        } catch (\Throwable $e) {
            $this->callCallbacks($this->failureCallbacks, $e);

            throw $e;
        } finally {
            $this->callCallbacks($this->afterCallbacks);
            $this->removeMutex();
        }

        return $output;
    }

    public function getCronExpression(): CronExpression
    {
        return $this->cronExpression ??= new CronExpression($this->expression);
    }

    public function getTask(): \Closure|string
    {
        return $this->task;
    }

    public function getExpression(): string
    {
        return $this->expression;
    }

    public function getTimezone(): ?\DateTimeZone
    {
        return $this->timezone;
    }

    public function preventsOverlapping(): bool
    {
        return $this->preventOverlapping;
    }

    public function getExpiresAfter(): int
    {
        return $this->expiresAfter;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function getSummary(): string
    {
        return $this->name ?? $this->description ?? $this->getMutexName();
    }

    public function getMutexName(): string
    {
        $taskHash = $this->task instanceof \Closure
            ? spl_object_id($this->task)
            : md5($this->task);

        return md5($this->expression . '|' . $taskHash);
    }

    private function applyTimezone(\DateTimeInterface $dateTime): \DateTimeInterface
    {
        if ($this->timezone === null) {
            return $dateTime;
        }

        if ($dateTime instanceof \DateTimeImmutable) {
            return $dateTime->setTimezone($this->timezone);
        }

        $clone = clone $dateTime;
        $clone->setTimezone($this->timezone);

        return $clone;
    }

    private function executeTask(): mixed
    {
        if ($this->task instanceof \Closure) {
            return ($this->task)();
        }

        if (str_contains($this->task, '@')) {
            [$class, $method] = explode('@', $this->task, 2);

            /** @var object $instance */
            $instance = new $class();

            return $instance->$method();
        }

        $output = [];
        $resultCode = 0;
        exec($this->task, $output, $resultCode);

        if ($resultCode !== 0) {
            throw new \RuntimeException(sprintf(
                'Command "%s" failed with exit code %d: %s',
                $this->task,
                $resultCode,
                implode("\n", $output)
            ));
        }

        return implode("\n", $output);
    }

    private function callCallbacks(array $callbacks, mixed ...$args): void
    {
        foreach ($callbacks as $callback) {
            $callback(...$args);
        }
    }

    private function mutexPath(): string
    {
        return self::MUTEX_DIR . '/' . $this->getMutexName();
    }

    private function createMutex(): bool
    {
        $path = $this->mutexPath();
        $dir = dirname($path);

        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }

        if (file_exists($path)) {
            $expiresAt = (int) file_get_contents($path);

            if (time() < $expiresAt) {
                return false;
            }
        }

        file_put_contents($path, (string) (time() + $this->expiresAfter * 60), LOCK_EX);

        return true;
    }

    private function removeMutex(): void
    {
        $path = $this->mutexPath();

        if (file_exists($path)) {
            @unlink($path);
        }
    }
}
