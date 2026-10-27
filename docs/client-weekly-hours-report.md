# Client weekly hours report

This report is read-only. It does not import tracking, recalculate payroll, update reviews, or change schedules. The first supported run is restricted to 2026-09-21 through 2026-10-10.

```bash
./vendor/bin/sail artisan payroll:export-client-weekly-hours --dry-run
./vendor/bin/sail artisan payroll:export-client-weekly-hours --draft
```

Without `--draft`, PDF/XLSX generation is blocked whenever there are warnings. Draft files retain the `_DRAFT` filename suffix; the report body does not display a draft legend. Both files default to `storage/app/reports`; `--output` changes the directory.

## Sources and calculation

- Daily expectations, assigned overtime, paid breaks/lunch, justified time, paid time, OFF, and review state come from persisted `daily_time_reviews`. `ScheduleExpectationService::forDate()` is used only to audit current template differences; it does not recalculate historical reviews.
- For dates from the direct Trackabi cutover, Desktop Activity is the stored `trackabi_daily_activities.measured_activity_seconds` (productive plus unproductive), or an explicit manually verified value. It is shown for audit, not treated as the payroll timer. Activity is joined by employee and date.
- For pre-cutover dates, measured Desktop Activity is unavailable. The report uses the already consolidated `daily_time_reviews.payable_seconds` and final lost-time decision; it does not substitute raw `logged_time` for Desktop Activity.
- Daily base is stored expected ordinary seconds plus assigned/approved daily overtime. No excess activity creates overtime.
- Remaining gap uses the final stored lost-time decision after supervisor justification, cross-checked against `base - payable_seconds`. Gaps below 1,800 seconds are tolerated in full; gaps of 1,800 seconds or more are deducted exactly. This rule is for client billing only and does not modify payroll.
- Paid OFF and full-day absence are non-billable. A full-day justification with comments suggesting actual work is flagged for manual validation; the report does not invent billable hours.
- Undated period-level manual overtime cannot be allocated to a daily cell and is reported as a warning.
- Weekly totals are the exact sum of visible daily billable cells. The partial final week has a blank 2026-10-11 cell.

The XLSX has one sheet per campaign, `ALL`, `AUDIT`, `SCHEDULE_AUDIT`, and `WARNINGS`. Time cells are numeric Excel durations formatted `[h]:mm`. The export also writes a combined PDF and one PDF per campaign; each separate PDF shows only that campaign and its total. Dosys BPO staff and Jose Di Gregorio are excluded from the client report. The PDF uses landscape legal format. The `Time` grand total is client billable time; average activity and total spent are shown as `-` because this report has no defensible consolidated score or cost rule.

## Why final export can be blocked

`UNRESOLVED_REVIEW`, `CLIENT_REPORT_DATA_MISSING`, `UNDATED_MANUAL_OT`, `AMBIGUOUS_FULL_DAY_JUSTIFICATION`, `AMBIGUOUS_LEAVE_OR_HOLIDAY`, `UNCLASSIFIED_PARTIAL_JUSTIFICATION`, `OFF_WITH_ACTIVITY_UNVERIFIED`, `MISSING_WEEKLY_CONFIGURATION`, `WEEKLY_OVER_TARGET`, `PRE_FIRST_WORKDAY_OFF`, and historical campaign/template mismatches require review. Current employee campaign is used when no historical campaign snapshot exists; explicit Trackabi project IDs are checked against active project mappings. Vacation and holiday are not reliably typed per daily review, so a comment is an audit signal, not an automatic billing override.

The report deliberately includes inactive employees with historical payroll/tracking evidence and excludes people with neither for the relevant period. A current `paid_without_tracking` employee has no automatic external client billability rule and is shown as zero with a warning.
