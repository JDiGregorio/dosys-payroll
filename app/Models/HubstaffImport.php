<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HubstaffImport extends Model
{
    protected $guarded = [];

    public function payrollPeriod(): BelongsTo
    {
        return $this->belongsTo(PayrollPeriod::class);
    }

    public function timeEntries(): HasMany
    {
        return $this->hasMany(HubstaffTimeEntry::class);
    }
}
