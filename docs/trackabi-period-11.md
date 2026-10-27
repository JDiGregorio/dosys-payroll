# Trackabi payroll period 11 (local validation)

Period 11 is 2026-10-11 through 2026-10-25. Keep it open until supervisors finish reviews and Operations completes its final review. Do not use historical estimated loss as a substitute for missing Insights activity.

## Sources and mappings

- Trackabi Direct provides raw time entries; `logged_time` is audit data, not measured work.
- Insights Staff Summary CSV provides desktop activity: productive seconds plus unproductive seconds. An explicit `00:00` is different from a missing CSV row.
- Project 75415 and 80297 map to PALMETTO, 75416 to AUTO FINANCE CENTER, and 75417 to RRD FINANCIAL.
- Starting 2026-10-21, project 81118 is Break 10, 81119 is Break 15, and 81120 is Lunch. These are paid-time categories, never campaign mappings or additional desktop activity.
- Jonathan and Orely remain paid without tracking. David Delcid and Rocio Rodas have no payroll employee and their CSV rows are explicitly ignored.

## Local commands

Use a database backup before importing. Configure `TRACKABI_PROVIDER=direct`, `TRACKABI_DIRECT_API_ENABLED=true`, `TRACKABI_DIRECT_API_KEY`, and `TRACKABI_DIRECT_CUTOVER_DATE=2026-09-26`. Never commit API credentials. After changing environment values, run `./vendor/bin/sail artisan config:clear`.

```sh
./vendor/bin/sail artisan migrate
./vendor/bin/sail artisan trackabi:import --period=11 --from=2026-10-11 --to=2026-10-25 --dry-run
./vendor/bin/sail artisan trackabi:import --period=11 --from=2026-10-11 --to=2026-10-25 --commit
./vendor/bin/sail artisan trackabi:import-insights-directory storage/app/trackabi-insights/period-11 --period=11 --dry-run
./vendor/bin/sail artisan trackabi:import-insights-directory storage/app/trackabi-insights/period-11 --period=11 --commit
./vendor/bin/sail artisan trackabi:coverage --period=11
./vendor/bin/sail artisan trackabi:paid-time-coverage --period=11
./vendor/bin/sail artisan trackabi:paid-time-coverage --period=11 --apply-alerts
./vendor/bin/sail artisan trackabi:audit-payroll-activity --period=11
./vendor/bin/sail artisan trackabi:audit-payroll-activity --period=11 --detail
./vendor/bin/sail artisan trackabi:audit-payroll-activity --period=11 --apply-alerts
./vendor/bin/sail artisan trackabi:exclude-day --period=11 --employee=43 --date=2026-10-22 --reason="Descanso sin registro confirmado"
./vendor/bin/sail artisan trackabi:exclude-day --period=11 --employee=43 --date=2026-10-22 --reason="Descanso sin registro confirmado" --apply
```

The directory importer requires one correctly named CSV per period date (for example `2026-10-11-2026-10-11-insights.csv`) and rejects missing or out-of-range dates before writing. A repeated import of unchanged files makes no duplicate activity rows. Changed files refresh pending reviews; applied reviews retain their decisions and receive a data-after-review alert.

The activity audit is read-only unless `--apply-alerts` is given. Its >28-minute threshold is a review signal, never a cap or a deduction. The full-detail view includes regular time, approved OT, fixed trackable target, measured desktop activity, gap, justification, final loss, and accepted logged time for audit. The target remains the scheduled paid time less the configured paid Lunch/Break entitlement on a day with activity; it never follows desktop activity upward. An explicit zero-activity day receives no automatic pause credit.

`trackabi:exclude-day` requires an open period and an unreviewed employee-day. It preserves imported Direct entries as inactive raw records and archives the Insights row in `trackabi_day_exclusions`; both importers skip that employee-day on later imports. Exclusion does not mark the day OFF. Locally, Delmark has exclusions for 2026-10-11, 2026-10-18, 2026-10-22, and 2026-10-25. Sunday is already a fixed schedule rest day; October 22 remains a scheduled day without records until its variable OFF status is reviewed.

## Pay and review

Expected trackable time is scheduled paid time less the employee's applicable paid lunch and break entitlement. Special-project entries are retained for compliance auditing only; they do not add to desktop activity or create a second pay credit. A missing Lunch/Break selection generates a compliance alert, not an automatic deduction. Excess marked time also generates an alert, not a second deduction. The supervisor decides any remaining activity shortfall in Daily Review.

The `weekly_flexible` overtime mode is opt-in. It is configured for Marco Lara locally; regular weekday hours remain daily, while preassigned OT is reconciled within its Monday-Sunday weekly pool, including across period boundaries. Unapproved over-target desktop activity never becomes automatic overtime.

Employees with a fixed weekly rest day can set `fixed_weekly_off_day` (ISO weekday 1-7) and `fixed_weekly_off_from_date` in the employee form. Delmark locally uses Sunday (7) effective 2026-10-11. His other weekly rest day varies and must be marked OFF by the supervisor; a Trackabi timer on a scheduled OFF day does not create automatic pay. No earlier period was recalculated.

After all supervisor reviews and required activity are resolved, Jonathan's account with `can_final_review_operations` can use the payroll period's Operations final-review action. The approval is audited and invalidates if underlying review, activity, entry, or alert data changes. Importing never approves a period or closes it.
