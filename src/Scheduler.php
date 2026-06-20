<?php

declare(strict_types=1);

namespace PhpCron;

class Scheduler
{
    /** @var Schedule[] */
    private array $schedules = [];

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

        foreach ($this->dueEvents($now) as $schedule) {
            try {
                $results[] = $schedule->run();
            } catch (\Throwable $e) {
                $results[] = null;
            }
        }

        return $results;
    }

    private function pushSchedule(Schedule $schedule): Schedule
    {
        $this->schedules[] = $schedule;

        return $schedule;
    }
}
