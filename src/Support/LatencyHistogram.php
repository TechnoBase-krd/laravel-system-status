<?php

namespace Technobase\SystemStatus\Support;

/**
 * Response times as a fixed histogram, so percentiles can be summed across minutes, routes and
 * components. Each request adds one to the first bucket whose upper bound it fits under.
 */
class LatencyHistogram
{
    /** Upper bounds in ms; a last, open bucket holds everything slower. */
    public const BOUNDS = [50, 100, 250, 500, 1000, 2500, 5000];

    /** Upper end assumed for the open bucket: twice the last bound. */
    public const OPEN_BUCKET_MS = 10000;

    public const COLUMNS = ['le_50', 'le_100', 'le_250', 'le_500', 'le_1000', 'le_2500', 'le_5000', 'le_inf'];

    public static function index(int $ms): int
    {
        foreach (self::BOUNDS as $index => $bound) {
            if ($ms <= $bound) {
                return $index;
            }
        }

        return count(self::BOUNDS);
    }

    /**
     * The p-th percentile in ms, interpolated inside its bucket; null without requests.
     *
     * @param  array<int|string, int|string|null>  $counts  one count per bucket, in order (or keyed by COLUMNS)
     */
    public static function percentile(array $counts, float $p): ?int
    {
        $counts = self::normalize($counts);
        $total = array_sum($counts);

        if ($total === 0) {
            return null;
        }

        $target = max(1, $p / 100 * $total);
        $cumulative = 0;

        foreach ($counts as $index => $count) {
            if ($count === 0) {
                continue;
            }

            if ($cumulative + $count >= $target) {
                $lower = $index === 0 ? 0 : self::BOUNDS[$index - 1];
                $upper = self::BOUNDS[$index] ?? self::OPEN_BUCKET_MS;

                return (int) round($lower + ($upper - $lower) * (($target - $cumulative) / $count));
            }

            $cumulative += $count;
        }

        return self::OPEN_BUCKET_MS;
    }

    /**
     * Bucket counts of a database row or aggregate (object or array with le_* fields).
     *
     * @return array<int, int>
     */
    public static function fromRow(object|array $row): array
    {
        $row = (array) $row;

        return array_map(fn (string $column) => (int) ($row[$column] ?? 0), self::COLUMNS);
    }

    /**
     * SUM(le_*) AS le_* for a select.
     */
    public static function sumSelect(): string
    {
        return implode(', ', array_map(fn (string $c) => "COALESCE(SUM({$c}), 0) AS {$c}", self::COLUMNS));
    }

    /**
     * @return array<int, int>
     */
    private static function normalize(array $counts): array
    {
        if (array_key_exists(self::COLUMNS[0], $counts)) {
            return self::fromRow($counts);
        }

        $values = array_values($counts);

        return array_map(fn (int $i) => (int) ($values[$i] ?? 0), array_keys(self::COLUMNS));
    }
}
