<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TrackabiDailyActivity extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'activity_date' => 'date',
            'raw_metadata' => 'array',
            'imported_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function importBatch(): BelongsTo
    {
        return $this->belongsTo(TrackabiActivityImportBatch::class, 'import_batch_id');
    }
}
