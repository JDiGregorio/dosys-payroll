<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HubstaffTimeEntry extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'activity_percentage' => 'decimal:2',
            'idle_percentage' => 'decimal:2',
            'total_spent' => 'decimal:2',
            'regular_spent' => 'decimal:2',
            'pto_spent' => 'decimal:2',
            'holiday_spent' => 'decimal:2',
            'raw_payload' => 'array',
        ];
    }

    public function payrollPeriod(): BelongsTo
    {
        return $this->belongsTo(PayrollPeriod::class);
    }

    public function hubstaffImport(): BelongsTo
    {
        return $this->belongsTo(HubstaffImport::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
