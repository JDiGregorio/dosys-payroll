<?php

namespace App\Services;

use App\Models\PayrollResult;

class PayrollVoucherBonusPresenter
{
    public function extraBonusRows(PayrollResult $result): array
    {
        $totalCents = (int) round((float) $result->extra_bonuses_amount * 100);
        $defaultRows = [['Bonos extra cliente', (float) ($totalCents / 100), true]];
        $period = $result->payrollPeriod;
        $employee = $result->employee;

        if (! $period || ! $employee
            || $period->starts_at->toDateString() !== '2026-09-11'
            || $period->ends_at->toDateString() !== '2026-09-25') {
            return $defaultRows;
        }

        $lunchCents = 0;
        $holidayCents = 0;

        foreach (app(BonusApplicationService::class)->approvedBonusesForEmployee($period, $employee) as $bonus) {
            if (! in_array($bonus->type, ['manual', 'other'], true)) {
                continue;
            }

            $amountCents = (int) round((float) $bonus->amount * 100);

            if ($bonus->description === 'September 15th - Lunch Bonus') {
                $lunchCents += $amountCents;
            } elseif ($bonus->description === 'Holiday September 15, Independence Day') {
                $holidayCents += $amountCents;
            }
        }

        if ($lunchCents + $holidayCents > $totalCents) {
            return $defaultRows;
        }

        $rows = [];
        $clientCents = $totalCents - $lunchCents - $holidayCents;

        if ($clientCents || (! $lunchCents && ! $holidayCents)) {
            $rows[] = ['Bonos extra cliente', (float) ($clientCents / 100), true];
        }

        if ($lunchCents) {
            $rows[] = ['Bono de almuerzo (15 de septiembre)', (float) ($lunchCents / 100), true];
        }

        if ($holidayCents) {
            $rows[] = ['Pago adicional por feriado trabajado (15 de septiembre)', (float) ($holidayCents / 100), true];
        }

        return $rows;
    }
}
