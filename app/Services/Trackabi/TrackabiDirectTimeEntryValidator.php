<?php

namespace App\Services\Trackabi;

class TrackabiDirectTimeEntryValidator
{
    public function loggedTime(mixed $raw): array
    {
        if (! is_string($raw) || ! preg_match('/^(\d+):([0-5]\d):([0-5]\d)$/D', $raw, $parts)) {
            return ['seconds' => null, 'issue' => 'malformed_duration'];
        }

        $maxHours = min(24, max(1, (int) config('trackabi_direct.max_entry_hours', 24)));
        $hours = ltrim($parts[1], '0');
        $hours = $hours === '' ? '0' : $hours;
        if (strlen($hours) > strlen((string) $maxHours)
            || (strlen($hours) === strlen((string) $maxHours) && strcmp($hours, (string) $maxHours) > 0)) {
            return ['seconds' => null, 'issue' => 'exceeds_daily_limit'];
        }

        $seconds = ((int) $hours * 3600) + ((int) $parts[2] * 60) + (int) $parts[3];
        if ($seconds > $maxHours * 3600) {
            return ['seconds' => null, 'issue' => 'exceeds_daily_limit'];
        }

        return ['seconds' => $seconds, 'issue' => null];
    }

    public function reasonCode(mixed $raw, ?string $issue): ?string
    {
        return match ($issue) {
            null => null,
            'exceeds_daily_limit' => 'EXCESSIVE_LOGGED_TIME',
            'malformed_duration' => $raw === null ? 'MISSING_LOGGED_TIME' : 'INVALID_LOGGED_TIME',
            'incomplete_start_end' => 'MISSING_START_END',
            'logged_exceeds_start_end', 'malformed_start_end' => 'INCONSISTENT_START_END',
            default => 'OTHER',
        };
    }

    public function startEndIssues(array $entry, ?int $loggedSeconds): array
    {
        $start = $entry['start_time'] ?? null;
        $end = $entry['end_time'] ?? null;
        if ($start === null && $end === null) {
            return [];
        }
        if ($start === null || $end === null) {
            return ['incomplete_start_end'];
        }
        if (! is_string($start) || ! is_string($end)
            || ! preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d:[0-5]\d$/D', $start)
            || ! preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d:[0-5]\d$/D', $end)) {
            return ['malformed_start_end'];
        }
        if ($loggedSeconds === null) {
            return [];
        }

        $startSeconds = $this->clockSeconds($start);
        $endSeconds = $this->clockSeconds($end);
        $elapsed = $endSeconds >= $startSeconds
            ? $endSeconds - $startSeconds
            : 86400 - $startSeconds + $endSeconds;

        return $loggedSeconds > $elapsed + 60 ? ['logged_exceeds_start_end'] : [];
    }

    public function duplicateFingerprint(array $entry): ?string
    {
        $member = $entry['member'] ?? null;
        if (! is_array($member) || ! isset($entry['date_logged'], $entry['start_time'], $entry['end_time'])
            || ! isset($member['id']) && ! isset($member['email'])) {
            return null;
        }

        return hash('sha256', json_encode([
            $member['id'] ?? strtolower((string) $member['email']),
            $entry['date_logged'],
            $entry['project']['id'] ?? null,
            $entry['start_time'],
            $entry['end_time'],
            $entry['logged_time'] ?? null,
        ], JSON_THROW_ON_ERROR));
    }

    public function sanitizedEntry(array $entry): array
    {
        $member = is_array($entry['member'] ?? null) ? $entry['member'] : [];
        $project = is_array($entry['project'] ?? null) ? $entry['project'] : [];
        $client = is_array($entry['client'] ?? null) ? $entry['client'] : [];
        $task = is_array($entry['task'] ?? null) ? $entry['task'] : [];

        return [
            'id' => $entry['id'] ?? null,
            'member' => $member ? [
                'id' => $member['id'] ?? null,
                'email' => '[REDACTED]',
            ] : null,
            'client' => $client ? ['id' => $client['id'] ?? null] : null,
            'project' => $project ? [
                'id' => $project['id'] ?? null,
                'name' => $project['name'] ?? null,
            ] : null,
            'task' => $task ? ['id' => $task['id'] ?? null] : null,
            'description' => isset($entry['description']) ? '[REDACTED]' : null,
            'date_logged' => $entry['date_logged'] ?? null,
            'logged_time' => $entry['logged_time'] ?? null,
            'billable' => $entry['billable'] ?? null,
            'start_time' => $entry['start_time'] ?? null,
            'end_time' => $entry['end_time'] ?? null,
            'timer_started_at' => $entry['timer_started_at'] ?? null,
            'timer_timezone' => $entry['timer_timezone'] ?? null,
            'time_type' => $entry['time_type'] ?? null,
        ];
    }

    public function memberName(mixed $member): string
    {
        if (! is_array($member)) {
            return 'Sin mapeo';
        }

        $name = trim((string) ($member['first_name'] ?? '').' '.(string) ($member['last_name'] ?? ''));

        return $name !== '' ? $name : 'Sin mapeo';
    }

    private function clockSeconds(string $value): int
    {
        [$hours, $minutes, $seconds] = array_map('intval', explode(':', $value));

        return ($hours * 3600) + ($minutes * 60) + $seconds;
    }
}
