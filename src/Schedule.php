<?php

declare(strict_types=1);

namespace PhpCron;

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
    private bool $evenInMaintenanceMode = false;

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

    public function evenInMaintenanceMode(): bool
    {
        return $this->evenInMaintenanceMode;
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
