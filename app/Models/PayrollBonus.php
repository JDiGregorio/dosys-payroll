<?php

namespace App\Models;

use App\Services\PayrollCalculationService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayrollBonus extends Model
{
    protected $guarded = [];

    protected static function booted(): void
    {
        $recalculate = function (PayrollBonus $bonus): void {
            $period = PayrollPeriod::query()->find($bonus->payroll_period_id);

            if ($period) {
                app(PayrollCalculationService::class)->recalculatePayrollResults($period);
            }
        };

        static::saved($recalculate);
        static::deleted($recalculate);
    }

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
        ];
    }

    public function payrollPeriod(): BelongsTo
    {
        return $this->belongsTo(PayrollPeriod::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }
}
