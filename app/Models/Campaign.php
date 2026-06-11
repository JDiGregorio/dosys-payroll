<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Campaign extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function teams(): HasMany
    {
        return $this->hasMany(Team::class);
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    public function hubstaffProjectMappings(): HasMany
    {
        return $this->hasMany(HubstaffProjectMapping::class);
    }
}
