<?php

namespace Database\Seeders;

use App\Models\Team;
use Illuminate\Database\Seeder;

class TeamSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['APPOINTMENT SETTER', 'BDC', 'DEBT COLLECTIONS', 'FINANCIAL OPERATIONS', 'PROJECT COORDINATOR', 'REPOS'] as $name) {
            Team::query()->updateOrCreate(['name' => $name], ['active' => true]);
        }
    }
}
