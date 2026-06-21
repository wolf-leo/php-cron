<?php

declare(strict_types=1);

namespace PhpCron;

class Scheduler
{
    /** @var Schedule[] */
    private array $schedules = [];

    private string $pidFile;

    public function __construct()
    {
        $this->pidFile = self::pidPathFromScript();
    }

    public function setPidFile(string $path): static
    {
        $this->pidFile = $path;
        return $this;
    }

    public function getPidFile(): ?string
    {
        return $this->pidFile;
    }

    public function call(\Closure $task): Schedule
    {
        return $this->pushSchedule(new Schedule($task));
    }

    public function command(string $command): Schedule
    {
        return $this->pushSchedule(new Schedule($command));
    }

    /**
     * @return Schedule[]
     */
    public function getSchedules(): array
    {
        return $this->schedules;
    }

    /**
     * @return Schedule[]
     */
    public function dueEvents(\DateTimeInterface $now): array
    {
        return array_values(
            array_filter(
                $this->schedules,
                fn(Schedule $schedule): bool => $schedule->isDue($now),
            ),
        );
    }

    /**
     * @return array<int, mixed>
     */
    public function runDueEvents(\DateTimeInterface $now): array
    {
        $results = [];
        $defaultTz = date_default_timezone_get();

        foreach ($this->dueEvents($now) as $schedule) {
            $tz = $schedule->getTimezone();
            if ($tz) {
                date_default_timezone_set($tz->getName());
            }
            try {
                $results[] = $schedule->run();
            } catch (\Throwable $e) {
                $results[] = null;
            } finally {
                date_default_timezone_set($defaultTz);
            }
        }

        return $results;
    }

    /**
     * Run the scheduler in a continuous loop (daemon mode).
     * Checks and executes due tasks every second.
     * Designed for second-precision schedules.
     * Gracefully handles SIGINT/SIGTERM for clean shutdown.
     *
     * If pcntl is available and $background is true, self-daemonizes
     * into background (no nohup/supervisor needed).
     */
    public function daemon(?string $timezone = null, bool $background = false): never
    {
        $timezone ??= date_default_timezone_get();
        date_default_timezone_set($timezone);
        if ($background && function_exists('pcntl_fork')) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                fwrite(STDERR, "Fork failed\n");
                exit(1);
            }
            if ($pid > 0) {
                $dir = dirname($this->pidFile);
                if (!is_dir($dir)) {
                    @mkdir($dir, 0755, true);
                }
                file_put_contents($this->pidFile, $pid);
                file_put_contents(
                    $this->pidFile . '.tasks.json',
                    json_encode([
                        'started_at' => time(),
                        'timezone'   => $timezone,
                        'tasks'      => $this->getTasksInfo(),
                    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
                );
                fprintf(STDOUT, "Daemon started (PID: %d)\n", $pid);
                exit(0);
            }
            if (function_exists('posix_setsid')) {
                posix_setsid();
            }
        }

        $running = true;

        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGINT,  function () use (&$running) { $running = false; });
            pcntl_signal(SIGTERM, function () use (&$running) { $running = false; });
        }

        $tick = 0;

        while ($running) {
            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }

            $now = new \DateTimeImmutable('now', new \DateTimeZone($timezone));

            $defaultTz = date_default_timezone_get();

            foreach ($this->dueEvents($now) as $schedule) {
                $tz = $schedule->getTimezone();
                if ($tz) {
                    date_default_timezone_set($tz->getName());
                }
                try {
                    $schedule->run();
                } catch (\Throwable $e) {
                    // Logged via onFailure callbacks; continue loop
                } finally {
                    date_default_timezone_set($defaultTz);
                }
            }

            // Periodic cleanup
            if (++$tick % 300 === 0) {
                gc_collect_cycles();
                if (function_exists('opcache_reset')) {
                    opcache_reset();
                }
            }

            usleep(1_000_000);
        }

        if (file_exists($this->pidFile)) {
            @unlink($this->pidFile);
        }

        exit(0);
    }

    /**
     * @return list<array{name: string, expression: string, type: string}>
     */
    public function getTasksInfo(): array
    {
        $info = [];
        foreach ($this->schedules as $schedule) {
            $type = match (true) {
                $schedule->getTask() instanceof \Closure => 'Closure',
                str_contains((string) $schedule->getTask(), '@') => 'Class@method',
                default => 'Command',
            };
            $info[] = [
                'name'       => $schedule->getName() ?? $schedule->getSummary(),
                'expression' => $schedule->getExpression(),
                'type'       => $type,
            ];
        }
        return $info;
    }

    /**
     * 启动 daemon (CLI 入口)，自动处理 start/status/stop 命令。
     *
     * 用户在自己的 xxx.php 中:
     *
     *   <?php
     *   require __DIR__ . '/vendor/autoload.php';
     *
     *   PhpCron\Scheduler::run(function (PhpCron\Scheduler $s) {
     *       $s->call(fn() => file_get_contents('https://api.x.com/xxx'))->second(3)->name('api');
     *       $s->call(fn() => cleanup())->minute(5)->name('cleanup');
     *   });
     *
     * 用法:
     *   php xxx.php         启动（后台运行）
     *   php xxx.php status  查看任务列表
     *   php xxx.php stop    停止
     */
    public static function run(callable $setup, ?string $timezone = null): void
    {
        $timezone ??= date_default_timezone_get();
        $cmd = $_SERVER['argv'][1] ?? 'start';

        if ($cmd === 'start') {
            $info = self::checkPidFile();
            if ($info['running']) {
                echo "Already running (PID: {$info['pid']})\n";
                return;
            }
            $s = new self();
            $setup($s);
            $s->daemon($timezone, background: true);
            return;
        }

        if ($cmd === 'status') {
            $info = self::checkPidFile();
            if ($info['running']) {
                echo sprintf("%-12s %s\n", "Status:", "Running");
                echo sprintf("%-12s %d\n", "PID:", $info['pid']);
                $started = $info['started_at'];
                if ($started) {
                    $tz = new \DateTimeZone($info['timezone']);
                    $started = (new \DateTimeImmutable('@' . $started))->setTimezone($tz)->format('Y-m-d H:i:s');
                }
                echo sprintf("%-12s %s\n", "Started:", $started ?? 'N/A');
                echo str_repeat('-', 44) . "\n";
                echo sprintf("%-16s %-15s %s\n", "Name", "Expression", "Type");
                echo str_repeat('-', 44) . "\n";
                foreach ($info['tasks'] as $task) {
                    echo sprintf("%-16s %-15s %s\n", $task['name'], $task['expression'], $task['type']);
                }
            } else {
                echo "Status: Stopped\n";
            }
            return;
        }

        if ($cmd === 'stop') {
            $info = self::checkPidFile();
            if (!$info['running']) {
                echo "Not running\n";
                return;
            }
            if (function_exists('posix_kill')) {
                posix_kill($info['pid'], SIGTERM);
            } else {
                exec("kill {$info['pid']}");
            }
            echo "Stopped (PID: {$info['pid']})\n";
            $pidFile = self::pidFile();
            foreach ([$pidFile, $pidFile . '.tasks.json'] as $f) {
                if (file_exists($f)) @unlink($f);
            }
            return;
        }

        exit("Usage: php {$_SERVER['argv'][0]} [status|stop]\n");
    }

    /**
     * Default PID file path: next to the entry script (xxx.php) that the user runs.
     */
    public static function pidFile(): string
    {
        return self::pidPathFromScript();
    }

    private static function pidPathFromScript(): string
    {
        $script = $_SERVER['SCRIPT_FILENAME'] ?? getcwd() . '/xxx.php';
        return dirname($script) . '/.php-cron.pid';
    }

    /**
     * Check if a daemon is running and return its info.
     *
     * @return array{pid: int, alive: bool, running: bool, started_at: ?int, timezone: string, tasks: list<array{name: string, expression: string, type: string}>}
     */
    public static function checkPidFile(?string $pidFile = null): array
    {
        $pidFile ??= self::pidFile();
        $result = ['pid' => 0, 'alive' => false, 'running' => false, 'started_at' => null, 'timezone' => 'UTC', 'tasks' => []];

        if (!file_exists($pidFile)) {
            return $result;
        }

        $pid = (int) file_get_contents($pidFile);
        $alive = $pid > 0 && function_exists('posix_kill') && posix_kill($pid, 0);

        $result['pid'] = $pid;
        $result['alive'] = $alive;
        $result['running'] = $alive;

        $tasksFile = $pidFile . '.tasks.json';
        if (file_exists($tasksFile)) {
            $data = json_decode(file_get_contents($tasksFile), true);
            if (is_array($data)) {
                $result['started_at'] = $data['started_at'] ?? null;
                $result['timezone']  = $data['timezone'] ?? 'UTC';
                $result['tasks'] = $data['tasks'] ?? $data;
            }
        }

        return $result;
    }

    private function pushSchedule(Schedule $schedule): Schedule
    {
        $this->schedules[] = $schedule;

        return $schedule;
    }
}
