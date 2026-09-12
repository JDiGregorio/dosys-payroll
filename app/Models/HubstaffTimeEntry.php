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
            'source_started_at' => 'datetime',
            'source_ended_at' => 'datetime',
            'billable_seconds' => 'integer',
            'productive_seconds' => 'integer',
            'unproductive_seconds' => 'integer',
            'activity_score' => 'decimal:2',
            'adjusted_payable_seconds' => 'integer',
            'requires_manual_review' => 'boolean',
            'activity_percentage' => 'decimal:2',
            'idle_percentage' => 'decimal:2',
            'total_spent' => 'decimal:2',
            'regular_spent' => 'decimal:2',
            'pto_spent' => 'decimal:2',
            'holiday_spent' => 'decimal:2',
            'raw_payload' => 'array',
            'active' => 'boolean',
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
