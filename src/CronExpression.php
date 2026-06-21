<?php

declare(strict_types=1);

namespace PhpCron;

final readonly class CronExpression
{
    private const ALIASES = [
        '@yearly'   => '0 0 1 1 *',
        '@annually' => '0 0 1 1 *',
        '@monthly'  => '0 0 1 * *',
        '@weekly'   => '0 0 * * 0',
        '@daily'    => '0 0 * * *',
        '@midnight' => '0 0 * * *',
        '@hourly'   => '0 * * * *',
    ];

    private const DAY_NAMES = [
        'sun' => 0, 'sunday' => 0,
        'mon' => 1, 'monday' => 1,
        'tue' => 2, 'tuesday' => 2,
        'wed' => 3, 'wednesday' => 3,
        'thu' => 4, 'thursday' => 4,
        'fri' => 5, 'friday' => 5,
        'sat' => 6, 'saturday' => 6,
    ];

    private const MONTH_NAMES = [
        'jan' => 1, 'january' => 1,
        'feb' => 2, 'february' => 2,
        'mar' => 3, 'march' => 3,
        'apr' => 4, 'april' => 4,
        'may' => 5,
        'jun' => 6, 'june' => 6,
        'jul' => 7, 'july' => 7,
        'aug' => 8, 'august' => 8,
        'sep' => 9, 'september' => 9,
        'oct' => 10, 'october' => 10,
        'nov' => 11, 'november' => 11,
        'dec' => 12, 'december' => 12,
    ];

    private const RANGES = [
        [0, 59],   // minute
        [0, 23],   // hour
        [1, 31],   // day of month
        [1, 12],   // month
        [0, 7],    // day of week (0 and 7 = Sunday)
    ];

    private const RANGES_SECONDS = [
        [0, 59],   // second
        [0, 59],   // minute
        [0, 23],   // hour
        [1, 31],   // day of month
        [1, 12],   // month
        [0, 7],    // day of week (0 and 7 = Sunday)
    ];

    public string $expression;

    /** @var array<int, list<int>> */
    private array $fields;

    public bool $hasSeconds;

    public function __construct(string $expression)
    {
        $expression = self::ALIASES[trim($expression)] ?? trim($expression);

        $parts = preg_split('/\s+/', $expression);

        if ($parts === false || (count($parts) !== 5 && count($parts) !== 6)) {
            throw new \InvalidArgumentException(
                sprintf('Cron expression "%s" must have 5 or 6 fields', $expression)
            );
        }

        $this->hasSeconds = count($parts) === 6;
        $ranges = $this->hasSeconds ? self::RANGES_SECONDS : self::RANGES;

        $fields = [];
        foreach ($parts as $i => $part) {
            [$min, $max] = $ranges[$i];
            $fields[] = self::parseField($part, $min, $max);
        }

        // Normalize day of week: replace 7 with 0 (both mean Sunday)
        $dowIdx = $this->hasSeconds ? 5 : 4;
        if (in_array(7, $fields[$dowIdx], true)) {
            $fields[$dowIdx] = array_unique(array_merge(
                array_values(array_filter($fields[$dowIdx], fn(int $d): bool => $d !== 7)),
                in_array(0, $fields[$dowIdx], true) ? [0] : [0]
            ));
        }

        $this->expression = $expression;
        $this->fields = $fields;
    }

    public function isDue(\DateTimeInterface $dateTime): bool
    {
        if ($this->hasSeconds) {
            $second    = (int) $dateTime->format('s');
            $minute    = (int) $dateTime->format('i');
            $hour      = (int) $dateTime->format('G');
            $day       = (int) $dateTime->format('j');
            $month     = (int) $dateTime->format('n');
            $dayOfWeek = (int) $dateTime->format('w');

            return in_array($second, $this->fields[0], true)
                && in_array($minute, $this->fields[1], true)
                && in_array($hour, $this->fields[2], true)
                && in_array($day, $this->fields[3], true)
                && in_array($month, $this->fields[4], true)
                && in_array($dayOfWeek, $this->fields[5], true);
        }

        $minute   = (int) $dateTime->format('i');
        $hour     = (int) $dateTime->format('G');
        $day      = (int) $dateTime->format('j');
        $month    = (int) $dateTime->format('n');
        $dayOfWeek = (int) $dateTime->format('w');

        return in_array($minute, $this->fields[0], true)
            && in_array($hour, $this->fields[1], true)
            && in_array($day, $this->fields[2], true)
            && in_array($month, $this->fields[3], true)
            && in_array($dayOfWeek, $this->fields[4], true);
    }

    public static function resolveDayName(string $name): ?int
    {
        return self::DAY_NAMES[strtolower($name)] ?? null;
    }

    /**
     * @return list<int>
     */
    private static function parseField(string $field, int $min, int $max): array
    {
        $field = self::normalizeNamedValue($field);

        if (str_contains($field, ',')) {
            $values = [];
            foreach (explode(',', $field) as $part) {
                $values = [...$values, ...self::parseRange(trim($part), $min, $max)];
            }
            return array_values(array_unique($values));
        }

        return self::parseRange($field, $min, $max);
    }

    /**
     * @return list<int>
     */
    private static function parseRange(string $field, int $min, int $max): array
    {
        $step = 1;
        $rangeMin = $min;
        $rangeMax = $max;

        if (str_contains($field, '/')) {
            [$range, $step] = explode('/', $field, 2);
            $step = (int) $step;
            $field = $range;
        }

        if ($field === '*') {
            return self::rangeStep($rangeMin, $rangeMax, $step);
        }

        if (str_contains($field, '-')) {
            [$rangeMin, $rangeMax] = explode('-', $field, 2);
            $rangeMin = (int) $rangeMin;
            $rangeMax = (int) $rangeMax;
            return self::rangeStep($rangeMin, $rangeMax, $step);
        }

        return [(int) $field];
    }

    private static function normalizeNamedValue(string $field): string
    {
        $lower = strtolower($field);

        if (isset(self::DAY_NAMES[$lower])) {
            return (string) self::DAY_NAMES[$lower];
        }

        if (isset(self::MONTH_NAMES[$lower])) {
            return (string) self::MONTH_NAMES[$lower];
        }

        return $field;
    }

    /**
     * @return list<int>
     */
    private static function rangeStep(int $min, int $max, int $step): array
    {
        $values = [];
        for ($i = $min; $i <= $max; $i += $step) {
            $values[] = $i;
        }
        return $values;
    }
}
