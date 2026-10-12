<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TrackingImportAlert extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'resolved' => 'boolean',
        ];
    }

    public function payrollPeriod(): BelongsTo
    {
        return $this->belongsTo(PayrollPeriod::class);
    }
}
