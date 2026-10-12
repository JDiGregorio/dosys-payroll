<?php

namespace App\Services\Trackabi;

use InvalidArgumentException;

class TrackabiInsightsCsvActivityProvider implements TrackabiActivityProvider
{
    public function source(): string
    {
        return 'trackabi_insights_csv';
    }

    public function normalize(array $values): DailyTrackabiActivityData
    {
        $productive = $this->duration($values['productive'] ?? null);
        $unproductive = $this->duration($values['unproductive'] ?? null);
        if ($productive === null || $unproductive === null || $productive + $unproductive > 86400) {
            throw new InvalidArgumentException('Duración Productive/Unproductive inválida, ausente o superior a 24 horas.');
        }

        return new DailyTrackabiActivityData($productive, $unproductive, $this->source());
    }

    private function duration(mixed $raw): ?int
    {
        if (! is_string($raw) || ! preg_match('/^(\d{1,4}):([0-5]\d)(?::([0-5]\d))?$/D', trim($raw), $parts)) {
            return null;
        }

        $seconds = ((int) $parts[1] * 3600) + ((int) $parts[2] * 60) + (int) ($parts[3] ?? 0);

        return $seconds <= 86400 ? $seconds : null;
    }
}
