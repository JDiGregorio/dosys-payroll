<?php

namespace App\Services\Trackabi;

use App\Services\TimeParserService;
use Carbon\Carbon;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class TrackabiDailyNormalizer
{
    public function __construct(
        private readonly TimeParserService $timeParser,
    ) {}

    /**
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>|null
     */
    public function normalizeEntry(array $entry): ?array
    {
        $email = $this->string($entry, ['member.email', 'user.email', 'employee.email', 'email']);
        $memberName = $this->memberName($entry);
        $date = $this->date($entry);
        $trackedSeconds = $this->duration($this->first($entry, [
            'loggedTime',
            'trackedTime',
            'duration',
            'time',
            'totalTime',
            'total',
        ]));

        if ($date === null) {
            return null;
        }

        $warnings = [];
        $loggedTime = $this->first($entry, [
            'loggedTime',
            'trackedTime',
            'duration',
            'time',
            'totalTime',
            'total',
        ]);

        if ($loggedTime === null || trim((string) $loggedTime) === '') {
            $warnings[] = 'logged_time_null';
        }

        $productiveSeconds = $this->nullableDuration($this->first($entry, [
            'productiveTime',
            'productive_time',
            'productiveSeconds',
        ]));
        $unproductiveSeconds = $this->nullableDuration($this->first($entry, [
            'unproductiveTime',
            'unproductive_time',
            'unproductiveSeconds',
        ]));
        $activityScore = $this->number($this->first($entry, [
            'activityScore',
            'activity_score',
            'activity',
            'activityPercentage',
        ]));
        $review = $this->reviewState($trackedSeconds, $productiveSeconds, $activityScore);
        $warnings = array_values(array_unique(array_merge($warnings, $review['warnings'])));

        return [
            'external_id' => $this->string($entry, ['id', 'entryId', 'timeEntryId', 'externalId']),
            'source_email' => $email ? strtolower($email) : null,
            'source_member_id' => $this->string($entry, ['member.id', 'user.id', 'employee.id', 'memberId']),
            'member_name' => $memberName,
            'date' => $date,
            'source_started_at' => $this->dateTime($entry, ['startTime', 'startedAt', 'start']),
            'source_ended_at' => $this->dateTime($entry, ['endTime', 'endedAt', 'end']),
            'project' => $this->string($entry, ['project.name', 'projectName', 'project']),
            'team' => $this->string($entry, ['team.name', 'teamName', 'team']),
            'task' => $this->string($entry, ['task.name', 'taskName', 'task']),
            'time_type' => $this->string($entry, ['timeType', 'type']),
            'tracked_seconds' => $trackedSeconds,
            'billable_seconds' => $this->duration($this->first($entry, ['billableTime', 'billableSeconds', 'billable'])),
            'productive_seconds' => $productiveSeconds,
            'unproductive_seconds' => $unproductiveSeconds,
            'activity_score' => $activityScore,
            'adjusted_payable_seconds' => $review['adjusted_payable_seconds'],
            'adjustment_reason' => $review['adjustment_reason'],
            'requires_manual_review' => $review['requires_manual_review'],
            'warning_codes' => $warnings,
            'raw_payload' => $entry,
        ];
    }

    private function memberName(array $entry): ?string
    {
        $name = $this->string($entry, ['member.name', 'user.name', 'employee.name', 'name']);

        if ($name) {
            return $name;
        }

        $first = $this->string($entry, ['member.firstName', 'user.firstName', 'employee.firstName', 'firstName']);
        $last = $this->string($entry, ['member.lastName', 'user.lastName', 'employee.lastName', 'lastName']);
        $full = trim(implode(' ', array_filter([$first, $last])));

        return $full !== '' ? $full : null;
    }

    /**
     * @param  array<int, string>  $keys
     */
    private function string(array $entry, array $keys): ?string
    {
        $value = $this->first($entry, $keys);

        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return (string) Str::of((string) $value)->squish();
    }

    /**
     * @param  array<int, string>  $keys
     */
    private function first(array $entry, array $keys): mixed
    {
        foreach ($keys as $key) {
            if (Arr::has($entry, $key)) {
                return Arr::get($entry, $key);
            }
        }

        return null;
    }

    private function date(array $entry): ?string
    {
        $value = $this->first($entry, ['dateLogged', 'date', 'loggedDate', 'startTime', 'startedAt']);

        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return Carbon::parse((string) $value)->toDateString();
    }

    /**
     * @param  array<int, string>  $keys
     */
    private function dateTime(array $entry, array $keys): ?string
    {
        $value = $this->first($entry, $keys);

        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return Carbon::parse((string) $value)->toDateTimeString();
    }

    private function nullableDuration(mixed $value): ?int
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return $this->duration($value);
    }

    private function duration(mixed $value): int
    {
        if ($value === null || trim((string) $value) === '') {
            return 0;
        }

        if (is_bool($value)) {
            return 0;
        }

        if (is_numeric($value)) {
            $numeric = (float) $value;

            return $numeric <= 24
                ? (int) round($numeric * 3600)
                : max((int) round($numeric), 0);
        }

        $string = trim((string) $value);

        if (str_starts_with($string, 'PT')) {
            $interval = new \DateInterval($string);

            return ($interval->d * 86400) + ($interval->h * 3600) + ($interval->i * 60) + $interval->s;
        }

        return max($this->timeParser->parseToSeconds($string), 0);
    }

    private function number(mixed $value): ?float
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return (float) str_replace(['%', ','], ['', ''], (string) $value);
    }

    /**
     * @return array{adjusted_payable_seconds: int, adjustment_reason: string|null, requires_manual_review: bool, warnings: array<int, string>}
     */
    private function reviewState(int $trackedSeconds, ?int $productiveSeconds, ?float $activityScore): array
    {
        $fullCreditScore = (float) config('trackabi.min_activity_score_for_full_credit', 70);
        $reviewScore = (float) config('trackabi.min_activity_score_for_review', 40);

        if ($activityScore === null) {
            return [
                'adjusted_payable_seconds' => $trackedSeconds,
                'adjustment_reason' => 'Trackabi/MindCloud no envió métricas de productividad en list-time-entries.',
                'requires_manual_review' => false,
                'warnings' => ['productivity_metrics_unavailable'],
            ];
        }

        if ($activityScore >= $fullCreditScore) {
            return [
                'adjusted_payable_seconds' => $trackedSeconds,
                'adjustment_reason' => null,
                'requires_manual_review' => false,
                'warnings' => [],
            ];
        }

        return [
            'adjusted_payable_seconds' => $trackedSeconds,
            'adjustment_reason' => $activityScore >= $reviewScore
                ? 'Activity score medio; revisar si aplica justificación.'
                : 'Activity score bajo; revisar con prioridad.',
            'requires_manual_review' => true,
            'warnings' => [],
        ];
    }
}
