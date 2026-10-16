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

        if (! $period || ! $employee) {
            return $defaultRows;
        }

        if ($period->starts_at->toDateString() !== '2026-09-11'
            || $period->ends_at->toDateString() !== '2026-09-25') {
            $amounts = [];
            foreach (app(BonusApplicationService::class)->approvedBonusesForEmployee($period, $employee) as $bonus) {
                $label = match ($bonus->type) {
                    'client_extra' => 'Bono Extra Cliente',
                    'other' => trim((string) $bonus->description) ?: 'Otro bono',
                    'manual' => trim((string) $bonus->description) ?: 'Bono manual',
                    default => null,
                };
                if ($label !== null) {
                    $amounts[$label] = ($amounts[$label] ?? 0) + (int) round((float) $bonus->amount * 100);
                }
            }

            // A manually edited payroll result may differ from bonus records; never display a false breakdown.
            if (! $amounts) {
                return $defaultRows;
            }

            if (array_sum($amounts) !== $totalCents) {
                return [['Bonos extras (sin desglose)', (float) ($totalCents / 100), true]];
            }

            return array_map(
                fn (string $label, int $cents): array => [$label, (float) ($cents / 100), true],
                array_keys($amounts),
                array_values($amounts),
            );
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
