<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <style>
        @page { margin: 24px 26px; }
        body { font-family: DejaVu Sans, sans-serif; color: #252b33; font-size: 8px; }
        .top { border-top: 2px solid #232f42; padding-top: 8px; height: 47px; }
        .logo { float: left; width: 110px; max-height: 42px; }
        .range { float: right; text-align: right; font-size: 9px; }
        h1 { text-align: center; font-size: 17px; margin: 9px 0 3px; }
        .company { text-align: center; font-size: 9px; margin-bottom: 16px; }
        .summary { border-top: 1px solid #cbd2d9; border-bottom: 1px solid #cbd2d9; padding: 10px 0; margin-bottom: 18px; }
        .summary strong { font-size: 12px; }
        .metrics { width: 100%; margin-top: 8px; }
        .metrics td { width: 33%; }
        .metrics .value { font-weight: bold; font-size: 12px; padding-top: 4px; }
        .section { page-break-before: always; }
        .section:first-child { page-break-before: auto; }
        h2 { font-size: 13px; margin: 10px 0 4px; }
        .week { margin-bottom: 9px; color: #66717c; }
        table.hours { width: 100%; border-collapse: collapse; table-layout: fixed; }
        .hours th, .hours td { padding: 3px 4px; border-bottom: 1px solid #d9dee4; text-align: right; }
        .hours th { background: #edf0f3; font-size: 7px; }
        .hours tr:nth-child(even) td { background: #f6f7f8; }
        .hours td:first-child, .hours td:nth-child(2), .hours th:first-child, .hours th:nth-child(2) { text-align: left; }
        .hours th:first-child { width: 13%; }
        .hours th:nth-child(2) { width: 23%; }
        .hours th:last-child, .hours td:last-child { font-weight: bold; }
    </style>
</head>
<body>
@foreach ($report['weeks'] as $week)
    @foreach ($report['campaigns'] as $campaign)
        @php $members = array_values(array_filter($week['members'], fn ($member) => $member['campaign'] === $campaign['name'])); @endphp
        @if (count($members))
            <div class="section">
                <div class="top">
                    @if ($logo)<img class="logo" src="{{ $logo }}" alt="DOSYS">@endif
                    <div class="range">{{ $report['from'] }} - {{ $report['to'] }}</div>
                </div>
                <h1>Time &amp; Activity Report</h1>
                <div class="company">DOSYS BPO LLC</div>
                <div class="summary">
                    <strong>Grand total</strong>
                    <table class="metrics"><tr><td>Time<div class="value">{{ \App\Services\Reports\ClientWeeklyHoursExporter::hours($report['grand_total_seconds']) }}</div></td><td>Avg. Activity<div class="value">-</div></td><td>Total spent<div class="value">-</div></td></tr></table>
                </div>
                <h2>{{ $campaign['name'] }}</h2>
                <div class="week">{{ $week['start'] }} - {{ $week['end'] }}</div>
                <table class="hours">
                    <thead><tr><th>Project</th><th>Member</th>
                        @for ($offset = 0; $offset < 7; $offset++)
                            <th>{{ \Carbon\Carbon::parse($week['start'])->addDays($offset)->format('D, M d, Y') }}</th>
                        @endfor
                        <th></th>
                    </tr></thead>
                    <tbody>
                        @foreach ($members as $member)
                            <tr><td>{{ $campaign['name'] }}</td><td>{{ $member['employee'] }}</td>
                                @foreach ($member['daily'] as $date => $seconds)
                                    <td>{{ ($date > $report['to'] || $date < $report['from'] || $seconds === null) ? '-' : \App\Services\Reports\ClientWeeklyHoursExporter::hours($seconds) }}</td>
                                @endforeach
                                <td>{{ \App\Services\Reports\ClientWeeklyHoursExporter::hours($member['total_seconds']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    @endforeach
@endforeach
</body>
</html>
