<?php

namespace App\Services;

use App\Models\PayrollPeriod;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class OctoberFirstHalfBonusClassificationService
{
    public function preview(PayrollPeriod $period): array
    {
        $this->assertPeriod($period);

        return DB::table('payroll_bonuses')
            ->where('payroll_period_id', $period->id)
            ->whereIn('type', ['manual', 'other'])
            ->where(function ($query): void {
                $query->where('type', 'manual')
                    ->orWhere(function ($query): void {
                        $query->where('type', 'other')
                            ->where('description', '!=', 'Pago doble Feriado Morazanico');
                    });
            })
            ->select('id', 'type', 'description', 'amount')
            ->orderBy('id')
            ->get()
            ->map(fn ($bonus): array => [
                'id' => $bonus->id,
                'from' => $bonus->type,
                'to' => $bonus->type === 'manual' ? 'other' : 'client_extra',
                'description' => $bonus->description,
                'amount' => $bonus->amount,
                'valid' => $bonus->type === 'other'
                    || $bonus->description === 'Double pay for Morazánico holidays',
            ])->all();
    }

    public function apply(PayrollPeriod $period): int
    {
        $this->assertPeriod($period);

        return DB::transaction(function () use ($period): int {
            $bonuses = DB::table('payroll_bonuses')
                ->where('payroll_period_id', $period->id)
                ->whereIn('type', ['manual', 'other'])
                ->where(function ($query): void {
                    $query->where('type', 'manual')
                        ->orWhere(function ($query): void {
                            $query->where('type', 'other')
                                ->where('description', '!=', 'Pago doble Feriado Morazanico');
                        });
                })
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            foreach ($bonuses as $bonus) {
                if ($bonus->type === 'manual' && $bonus->description !== 'Double pay for Morazánico holidays') {
                    throw new RuntimeException("Bono manual inesperado #{$bonus->id}; no se aplicaron cambios.");
                }
            }

            // Only metadata changes. Query builder bypasses PayrollBonus's recalculation observer.
            $manualIds = $bonuses->where('type', 'manual')->pluck('id')->all();
            $otherIds = $bonuses->where('type', 'other')->pluck('id')->all();
            if ($otherIds) {
                DB::table('payroll_bonuses')->whereIn('id', $otherIds)->update(['type' => 'client_extra']);
            }
            if ($manualIds) {
                DB::table('payroll_bonuses')->whereIn('id', $manualIds)->update([
                    'type' => 'other',
                    'description' => 'Pago doble Feriado Morazanico',
                ]);
            }

            return count($manualIds) + count($otherIds);
        });
    }

    private function assertPeriod(PayrollPeriod $period): void
    {
        if ($period->starts_at?->toDateString() !== '2026-09-26'
            || $period->ends_at?->toDateString() !== '2026-10-10') {
            throw new RuntimeException('Este comando solo permite la quincena del 26/09 al 10/10/2026.');
        }
    }
}
