<?php

namespace App\Services;

class TimeParserService
{
    public function parseToSeconds(mixed $value): int
    {
        if ($value === null) {
            return 0;
        }

        $value = trim((string) $value);

        if ($value === '') {
            return 0;
        }

        $negative = str_starts_with($value, '-');
        $value = ltrim($value, '+- ');

        if (preg_match('/^\d{1,3}:\d{1,2}(:\d{1,2})?$/', $value) === 1) {
            $parts = array_map('intval', explode(':', $value));
            $seconds = ($parts[0] * 3600) + (($parts[1] ?? 0) * 60) + ($parts[2] ?? 0);

            return $negative ? -$seconds : $seconds;
        }

        if (is_numeric(str_replace(',', '.', $value))) {
            $seconds = (int) round(((float) str_replace(',', '.', $value)) * 3600);

            return $negative ? -$seconds : $seconds;
        }

        return 0;
    }

    public function secondsToDecimalHours(int $seconds): float
    {
        return round($seconds / 3600, 2);
    }

    public function secondsToHourMinute(int $seconds): string
    {
        $sign = $seconds < 0 ? '-' : '';
        $seconds = (int) round(abs($seconds) / 60) * 60;
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        return sprintf('%s%d:%02d', $sign, $hours, $minutes);
    }

    public function secondsToHourMinuteSecond(int $seconds): string
    {
        $sign = $seconds < 0 ? '-' : '';
        $seconds = abs($seconds);

        return sprintf(
            '%s%d:%02d:%02d',
            $sign,
            intdiv($seconds, 3600),
            intdiv($seconds % 3600, 60),
            $seconds % 60,
        );
    }
}
